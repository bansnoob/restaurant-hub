<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a day closure's stored figures in step with the rows they were computed from.
 *
 * A closure records a snapshot taken at closing time. Nothing used to refresh it, and
 * the Expenses page never blocked edits to a closed day, so changing an expense after
 * the fact left the closure describing a day that no longer existed — silently, with
 * the Cash Report still reporting the stale expected_cash. That had already happened on
 * 8 of 80 production closures, three of them by more than P15,000.
 *
 * counted_cash and opening_float are never touched here. Those are human observations
 * of physical cash; everything else is derived from rows and can be recomputed.
 */
class DayClosureRecalculator
{
    /**
     * Recompute a closure in place. Returns it with the refreshed figures.
     */
    public function recalculate(DayClosure $closure): DayClosure
    {
        $totals = $this->totalsFor(
            (int) $closure->branch_id,
            $closure->closed_at_date->format('Y-m-d')
        );

        $openingFloat = (float) $closure->opening_float;
        $expected = $openingFloat
            + $totals['cash_sales_total']
            + $totals['mixed_cash_total']
            - $totals['cash_expenses_total'];

        $closure->update([
            'cash_sales_total' => round($totals['cash_sales_total'], 2),
            'mixed_cash_total' => round($totals['mixed_cash_total'], 2),
            'gcash_sales_total' => round($totals['gcash_sales_total'], 2),
            'cash_expenses_total' => round($totals['cash_expenses_total'], 2),
            'expected_cash' => round($expected, 2),
            'variance' => round((float) $closure->counted_cash - $expected, 2),
            'order_count' => $totals['order_count'],
            'expense_count' => $totals['expense_count'],
        ]);

        return $closure;
    }

    /**
     * Recompute the closure covering this branch/date, if there is one.
     *
     * Call this after any write that changes a day's sales or expenses. A day with no
     * closure has nothing to keep in step, so this is a no-op — which is what makes it
     * safe to call unconditionally from the expense and GCash write paths.
     */
    public function recalculateFor(int $branchId, string|\DateTimeInterface|null $date): ?DayClosure
    {
        $date = $this->normaliseDate($date);
        if ($date === null) {
            return null;
        }

        $closure = $this->closureFor($branchId, $date);

        return $closure ? $this->recalculate($closure) : null;
    }

    /**
     * The closure covering this branch/date, if any. Null means the day is open.
     */
    public function closureFor(int $branchId, string|\DateTimeInterface|null $date): ?DayClosure
    {
        $date = $this->normaliseDate($date);
        if ($date === null) {
            return null;
        }

        return DayClosure::where('branch_id', $branchId)
            ->whereDate('closed_at_date', $date)
            ->first();
    }

    /**
     * Recompute both the closure a row is moving off and the one it is moving onto.
     *
     * An expense edited across a date or branch boundary leaves two days wrong, not one:
     * the row is gone from where it was and new where it landed. Recomputing only the
     * destination would leave the origin permanently overstated.
     *
     * @return array<int, DayClosure>
     */
    public function recalculateForMove(
        int $fromBranchId,
        string|\DateTimeInterface|null $fromDate,
        int $toBranchId,
        string|\DateTimeInterface|null $toDate
    ): array {
        $fromDate = $this->normaliseDate($fromDate);
        $toDate = $this->normaliseDate($toDate);
        $touched = [];

        $before = $this->recalculateFor($fromBranchId, $fromDate);
        if ($before) {
            $touched[] = $before;
        }

        $movedDay = $fromDate !== $toDate || $fromBranchId !== $toBranchId;
        if ($movedDay) {
            $after = $this->recalculateFor($toBranchId, $toDate);
            if ($after) {
                $touched[] = $after;
            }
        }

        return $touched;
    }

    /**
     * expense_date carries no cast on the model, so callers hand over whatever the
     * column gave them — a 'Y-m-d' string, a full datetime string, or a Carbon.
     */
    private function normaliseDate(string|\DateTimeInterface|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $date = substr($date, 0, 10);

        return $date === '' ? null : $date;
    }

    /**
     * The canonical day totals. Cash, mixed-cash and GCash sales plus approved cash
     * expenses for one branch on one date.
     *
     * Two kinds of cash are deliberately absent, for the same reason: special expenses,
     * which live in their own table, and expenses marked paid_from = 'outside'. Both are
     * real cash leaving the business, and neither came out of this drawer. They are a
     * range-level position — see CashReportService::cashOverhead() and paidOutsideDrawer().
     *
     * @return array{cash_sales_total: float, mixed_cash_total: float, gcash_sales_total: float, cash_expenses_total: float, order_count: int, expense_count: int}
     */
    public function totalsFor(int $branchId, string $date): array
    {
        $sales = Sale::query()
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereDate('sale_datetime', $date)
            ->toBase()
            ->first([
                DB::raw("COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN grand_total ELSE 0 END), 0) as cash_sales"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_method = 'mixed' THEN COALESCE(cash_amount, 0) ELSE 0 END), 0) as mixed_cash"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_method = 'gcash' THEN grand_total ELSE 0 END), 0) as gcash_sales"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_method = 'mixed' THEN COALESCE(gcash_amount, 0) ELSE 0 END), 0) as mixed_gcash"),
                DB::raw('COUNT(*) as order_count'),
            ]);

        $expenses = Expense::query()
            ->where('branch_id', $branchId)
            ->where('status', 'approved')
            ->whereDate('expense_date', $date)
            ->toBase()
            ->first([
                // paid_from = 'drawer' only: an outside-paid expense is real cash out of
                // the business, but it never passed through this till, so it cannot be
                // part of what the till is expected to hold.
                DB::raw("COALESCE(SUM(CASE WHEN payment_method = 'cash' AND paid_from = 'drawer' THEN amount ELSE 0 END), 0) as cash_expenses"),
                DB::raw('COUNT(*) as expense_count'),
            ]);

        return [
            'cash_sales_total' => (float) ($sales->cash_sales ?? 0),
            'mixed_cash_total' => (float) ($sales->mixed_cash ?? 0),
            'gcash_sales_total' => (float) ($sales->gcash_sales ?? 0) + (float) ($sales->mixed_gcash ?? 0),
            'cash_expenses_total' => (float) ($expenses->cash_expenses ?? 0),
            'order_count' => (int) ($sales->order_count ?? 0),
            'expense_count' => (int) ($expenses->expense_count ?? 0),
        ];
    }
}
