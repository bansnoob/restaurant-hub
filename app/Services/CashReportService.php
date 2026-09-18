<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the Cash Report's day rows.
 *
 * The report used to read day_closures alone, so a day nobody closed had no row and
 * was silently missing from the tab. This service unions the closures with the days
 * that actually moved the till, so a missed close shows up as a visible gap instead
 * of disappearing.
 */
class CashReportService
{
    /**
     * Every day in range that has a closure or till-affecting activity, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dayRows(string $dateFrom, string $dateTo, ?int $branchId = null): Collection
    {
        $activity = $this->activityByDay($dateFrom, $dateTo, $branchId);
        $closures = $this->closuresByDay($dateFrom, $dateTo, $branchId);
        $branchNames = $this->branchNames();

        $rows = $closures->map(fn (DayClosure $closure) => $this->closedRow($closure, $branchNames));

        $unclosed = $activity
            ->reject(fn (array $day) => $closures->has($this->key($day['branch_id'], $day['date'])))
            ->map(fn (array $day) => $this->unclosedRow($day, $branchNames));

        return $rows->values()
            ->concat($unclosed->values())
            ->sortByDesc(fn (array $row) => $row['date'].'|'.str_pad((string) $row['branch_id'], 11, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Headline figures. Unclosed days contribute their expected cash only — with no
     * counted figure there is no variance to report, and inventing one would show a
     * shortfall nobody ever measured.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function totals(Collection $rows): array
    {
        $closed = $rows->where('closed', true);

        return [
            'cash_on_hand' => round((float) $closed->sum('counted_cash'), 2),
            'expected_total' => round((float) $rows->sum('expected_cash'), 2),
            'variance_total' => round((float) $closed->sum('variance'), 2),
            'days_closed' => $closed->count(),
            'days_unclosed' => $rows->where('closed', false)->count(),
            'cash_sales_total' => round((float) $rows->sum('cash_sales_total'), 2),
            'cash_expenses_total' => round((float) $rows->sum('cash_expenses_total'), 2),
        ];
    }

    /**
     * Cash movement per branch/date, as two grouped queries rather than a pair per day.
     *
     * Only cash counts: a GCash-only day never touched the drawer, so it is not a gap
     * in the cash report and must not be listed as one.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function activityByDay(string $dateFrom, string $dateTo, ?int $branchId): Collection
    {
        $days = [];

        foreach ($this->salesByDay($dateFrom, $dateTo, $branchId) as $row) {
            $key = $this->key((int) $row->branch_id, $this->dateOf($row->day));
            $days[$key] = [
                'branch_id' => (int) $row->branch_id,
                'date' => $this->dateOf($row->day),
                'cash_sales_total' => (float) $row->cash_sales + (float) $row->mixed_cash,
                'cash_expenses_total' => 0.0,
                'order_count' => (int) $row->order_count,
                'expense_count' => 0,
            ];
        }

        foreach ($this->expensesByDay($dateFrom, $dateTo, $branchId) as $row) {
            $key = $this->key((int) $row->branch_id, $this->dateOf($row->day));
            $existing = $days[$key] ?? [
                'branch_id' => (int) $row->branch_id,
                'date' => $this->dateOf($row->day),
                'cash_sales_total' => 0.0,
                'cash_expenses_total' => 0.0,
                'order_count' => 0,
                'expense_count' => 0,
            ];

            $days[$key] = array_merge($existing, [
                'cash_expenses_total' => (float) $row->cash_expenses,
                'expense_count' => (int) $row->expense_count,
            ]);
        }

        return collect($days)->filter(
            fn (array $day) => $day['cash_sales_total'] > 0 || $day['cash_expenses_total'] > 0
        );
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function salesByDay(string $dateFrom, string $dateTo, ?int $branchId): Collection
    {
        return Sale::query()
            ->where('status', 'completed')
            ->whereDate('sale_datetime', '>=', $dateFrom)
            ->whereDate('sale_datetime', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('branch_id', DB::raw('DATE(sale_datetime)'))
            ->toBase()
            ->get([
                'branch_id',
                DB::raw('DATE(sale_datetime) as day'),
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN grand_total ELSE 0 END) as cash_sales"),
                DB::raw("SUM(CASE WHEN payment_method = 'mixed' THEN COALESCE(cash_amount, 0) ELSE 0 END) as mixed_cash"),
                DB::raw('COUNT(*) as order_count'),
            ]);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function expensesByDay(string $dateFrom, string $dateTo, ?int $branchId): Collection
    {
        return Expense::query()
            ->where('status', 'approved')
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('branch_id', 'expense_date')
            ->toBase()
            ->get([
                'branch_id',
                DB::raw('expense_date as day'),
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_expenses"),
                DB::raw('COUNT(*) as expense_count'),
            ]);
    }

    /**
     * @return Collection<string, DayClosure>
     */
    private function closuresByDay(string $dateFrom, string $dateTo, ?int $branchId): Collection
    {
        return DayClosure::with(['branch:id,name', 'closedBy:id,name'])
            ->whereDate('closed_at_date', '>=', $dateFrom)
            ->whereDate('closed_at_date', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->get()
            ->keyBy(fn (DayClosure $closure) => $this->key(
                (int) $closure->branch_id,
                $this->dateOf($closure->closed_at_date)
            ));
    }

    /**
     * @param  array<int, string>  $branchNames
     * @return array<string, mixed>
     */
    private function closedRow(DayClosure $closure, array $branchNames): array
    {
        return [
            'date' => $this->dateOf($closure->closed_at_date),
            'branch_id' => (int) $closure->branch_id,
            'branch_name' => $closure->branch?->name ?? $branchNames[(int) $closure->branch_id] ?? '—',
            'closed' => true,
            'closure' => $closure,
            'closed_by' => $closure->closedBy?->name,
            'cash_sales_total' => (float) $closure->cash_sales_total + (float) $closure->mixed_cash_total,
            'cash_expenses_total' => (float) $closure->cash_expenses_total,
            'expected_cash' => (float) $closure->expected_cash,
            'counted_cash' => (float) $closure->counted_cash,
            'variance' => (float) $closure->variance,
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, string>  $branchNames
     * @return array<string, mixed>
     */
    private function unclosedRow(array $day, array $branchNames): array
    {
        return [
            'date' => $day['date'],
            'branch_id' => $day['branch_id'],
            'branch_name' => $branchNames[$day['branch_id']] ?? '—',
            'closed' => false,
            'closure' => null,
            'closed_by' => null,
            'cash_sales_total' => $day['cash_sales_total'],
            'cash_expenses_total' => $day['cash_expenses_total'],
            // No opening float is carried: the drawer has never recorded one, so the
            // day's own movement is the whole of what the drawer should hold.
            'expected_cash' => round($day['cash_sales_total'] - $day['cash_expenses_total'], 2),
            'counted_cash' => null,
            'variance' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function branchNames(): array
    {
        return Branch::query()->pluck('name', 'id')->all();
    }

    private function key(int $branchId, string $date): string
    {
        return $branchId.'|'.$date;
    }

    private function dateOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
