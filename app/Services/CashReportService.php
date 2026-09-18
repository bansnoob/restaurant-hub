<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SpecialExpense;
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
    public function totals(Collection $rows, string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $closed = $rows->where('closed', true);
        $cashOverhead = $this->cashOverhead($dateFrom, $dateTo, $branchId);

        return [
            // Net of overhead: monthly costs settled in cash genuinely left the
            // business's cash, so a figure that ignored them would overstate what
            // is actually held. The deduction is surfaced as its own figure below
            // rather than silently shrinking this one.
            'cash_on_hand' => round((float) $closed->sum('counted_cash') - $cashOverhead, 2),
            'cash_overhead_total' => round($cashOverhead, 2),
            'expected_total' => round((float) $rows->sum('expected_cash'), 2),
            'variance_total' => round((float) $closed->sum('variance'), 2),
            'days_closed' => $closed->count(),
            'days_unclosed' => $rows->where('closed', false)->count(),
            'cash_sales_total' => round((float) $rows->sum('cash_sales_total'), 2),
            'cash_expenses_total' => round((float) $rows->sum('cash_expenses_total'), 2),
        ];
    }

    /**
     * Monthly overhead settled in cash within the range.
     *
     * Deliberately kept out of the per-day rows. A month's rent is not a cost of the
     * day it happened to be handed over: charging it to that day's expected_cash would
     * throw the drawer variance by the full rent amount and the closing cashier would
     * wear it. On 2026-09-02 that would have turned a clean +₱278 into +₱16,278 against
     * a drawer that only ever held ₱1,000. It belongs to the cash position over the
     * range, not to any one reconciliation.
     *
     * Scoping mirrors the GCash wallet's treatment of the same table, so the two money
     * positions cannot disagree about the same row:
     *  - settlement-dated on COALESCE(paid_date, period_month), because this is a
     *    position statement, not an accrual one;
     *  - a company-wide row (branch_id null) belongs to no single branch, so it is
     *    excluded from a single-branch view and deducted from the all-branches figure.
     */
    private function cashOverhead(string $dateFrom, string $dateTo, ?int $branchId): float
    {
        $query = SpecialExpense::query()->where('payment_method', 'cash');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        // whereRaw, not whereDate: the comparison is on a COALESCE of two columns, and
        // a plain string compare is correct on both drivers — MySQL compares DATEs, and
        // SQLite's stored strings share the 'Y-m-d' prefix so they order correctly.
        $query->whereRaw('COALESCE(paid_date, period_month) >= ?', [$dateFrom])
            ->whereRaw('COALESCE(paid_date, period_month) <= ?', [$dateTo.' 23:59:59']);

        return (float) $query->sum('amount');
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
