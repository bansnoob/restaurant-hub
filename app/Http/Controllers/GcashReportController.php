<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GcashAdjustment;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Read-only report over GCash money movement, built from individual sales rather than
 * from day closures — so it stays accurate for days that were never closed.
 */
class GcashReportController extends Controller
{
    private const PER_PAGE = 20;

    private const DEFAULT_RANGE_DAYS = 29;

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        [$dateFrom, $dateTo] = $this->resolveRange($validated);

        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $search = trim((string) ($validated['search'] ?? ''));

        $sales = $this->paginateTransactions($branchId, $dateFrom, $dateTo, $search);

        $gcashSalesTotal = Sale::gcashAmountSum($this->salesQuery($branchId, $dateFrom, $dateTo, $search));

        // An order-number search selects individual sales, and no expense carries an order
        // number — so nothing matches and the net collapses to the matched sales. Keeping the
        // range expense total here would make the tiles contradict the table beneath them.
        $gcashExpensesTotal = $search === ''
            ? (float) $this->expensesQuery($branchId, $dateFrom, $dateTo)->sum('amount')
            : 0.0;

        // Adjustments carry no order number either, so an order-number search excludes them
        // for the same reason as expenses.
        $adjustmentsTotal = $search === ''
            ? (float) $this->adjustmentsQuery($branchId, $dateFrom, $dateTo)->sum('amount')
            : 0.0;

        $totals = [
            // Sales only — this is the figure day_closures.gcash_sales_total snapshots, and it
            // must keep matching it. Adjustments are reported separately for exactly that reason.
            'gcash_sales_total' => $gcashSalesTotal,
            'gcash_expenses_total' => $gcashExpensesTotal,
            // Already signed: a deduction is stored negative, so this adds.
            'adjustments_total' => $adjustmentsTotal,
            'net_gcash' => $gcashSalesTotal - $gcashExpensesTotal + $adjustmentsTotal,
            // The paginator already counted this exact filtered set.
            'transaction_count' => $sales->total(),
        ];

        return view('modules.gcash_report.index', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'sales' => $sales,
            'expenses' => $this->paginateExpenses($branchId, $dateFrom, $dateTo),
            'adjustments' => $this->paginateAdjustments($branchId, $dateFrom, $dateTo),
            'totals' => $totals,
            'filters' => [
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
            'defaults' => [
                'date_from' => $this->defaultDateFrom(),
                'date_to' => $this->defaultDateTo(),
            ],
        ]);
    }

    /**
     * Record GCash income that never went through the POS.
     *
     * Written to `sales` as a completed GCash sale so it counts everywhere GCash revenue
     * counts — the Sales page and the day-closure GCash total — rather than living in a
     * parallel ledger the rest of the app cannot see.
     */
    public function store(Request $request, SaleService $sales): RedirectResponse
    {
        $validated = $request->validate($this->recordRules());

        if ($blocked = $this->dayClosedResponse((int) $validated['branch_id'], $validated['sale_date'])) {
            return $blocked;
        }

        $date = Carbon::parse($validated['sale_date']);

        Sale::create([
            'branch_id' => (int) $validated['branch_id'],
            'order_number' => $sales->generateOrderNumber(
                (int) $validated['branch_id'],
                $date->toDateString(),
                Sale::MANUAL_GCASH_PREFIX
            ),
            // Keep the clock time when the record is for today so it sorts naturally
            // against POS sales; a backdated record lands at the end of its own day.
            'sale_datetime' => $date->isToday() ? now() : $date->copy()->endOfDay(),
            'cashier_user_id' => $request->user()->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => $validated['amount'],
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $validated['amount'],
            'paid_total' => $validated['amount'],
            'change_total' => 0,
            'payment_method' => 'gcash',
            'gcash_amount' => $validated['amount'],
            'notes' => $validated['description'],
            'closed_at' => now(),
        ]);

        return back()->with('success', 'GCash record added.');
    }

    public function update(Request $request, Sale $sale, SaleService $sales): RedirectResponse
    {
        if ($blocked = $this->guardManualRecord($sale)) {
            return $blocked;
        }

        $validated = $request->validate($this->recordRules());

        // Both the day it is leaving and the day it is landing on must still be open.
        foreach ([$sale->sale_datetime?->toDateString(), $validated['sale_date']] as $date) {
            if ($date && ($blocked = $this->dayClosedResponse((int) $sale->branch_id, $date))) {
                return $blocked;
            }
        }
        if ($blocked = $this->dayClosedResponse((int) $validated['branch_id'], $validated['sale_date'])) {
            return $blocked;
        }

        $date = Carbon::parse($validated['sale_date']);
        $movedDay = $sale->sale_datetime?->toDateString() !== $date->toDateString();
        $movedBranch = (int) $sale->branch_id !== (int) $validated['branch_id'];

        $sale->update([
            'branch_id' => (int) $validated['branch_id'],
            // Re-issue the number if it moved, so it stays unique within its new branch/day.
            'order_number' => ($movedDay || $movedBranch)
                ? $sales->generateOrderNumber((int) $validated['branch_id'], $date->toDateString(), Sale::MANUAL_GCASH_PREFIX)
                : $sale->order_number,
            'sale_datetime' => $movedDay ? $date->copy()->endOfDay() : $sale->sale_datetime,
            'sub_total' => $validated['amount'],
            'grand_total' => $validated['amount'],
            'paid_total' => $validated['amount'],
            'gcash_amount' => $validated['amount'],
            'notes' => $validated['description'],
        ]);

        return back()->with('success', 'GCash record updated.');
    }

    public function destroy(Sale $sale): RedirectResponse
    {
        if ($blocked = $this->guardManualRecord($sale)) {
            return $blocked;
        }

        if ($blocked = $this->dayClosedResponse((int) $sale->branch_id, $sale->sale_datetime?->toDateString())) {
            return $blocked;
        }

        $sale->delete();

        return back()->with('success', 'GCash record deleted.');
    }

    /**
     * Record a correcting entry against GCash takings.
     *
     * Stored in its own table rather than as a sale or an expense: a correction is neither
     * revenue nor a cost, so it must not reach the Sales page's order counts and averages or
     * the expense reporting. Keeping it out of `sales` also means it cannot invalidate the
     * gcash_sales_total a closed day was signed off with — which is why, unlike a GCash record,
     * an adjustment needs no closed-day guard and can be dated freely.
     */
    public function storeAdjustment(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->adjustmentRules());

        GcashAdjustment::create([
            'branch_id' => (int) $validated['branch_id'],
            'adjustment_date' => $validated['adjustment_date'],
            // The form posts a positive figure and the sign is applied here, so a missing or
            // mistyped minus cannot silently record the opposite of what was intended.
            'amount' => -1 * (float) $validated['amount'],
            'reason' => $validated['reason'],
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Adjustment recorded.');
    }

    public function updateAdjustment(Request $request, GcashAdjustment $gcashAdjustment): RedirectResponse
    {
        $validated = $request->validate($this->adjustmentRules());

        $gcashAdjustment->update([
            'branch_id' => (int) $validated['branch_id'],
            'adjustment_date' => $validated['adjustment_date'],
            'amount' => -1 * (float) $validated['amount'],
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'Adjustment updated.');
    }

    public function destroyAdjustment(GcashAdjustment $gcashAdjustment): RedirectResponse
    {
        $gcashAdjustment->delete();

        return back()->with('success', 'Adjustment deleted.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function adjustmentRules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'adjustment_date' => ['required', 'date'],
            // Positive here; storeAdjustment applies the minus. Two decimal places for the
            // same reason as a record: more precision would display rounded while the total
            // summed the unrounded value.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function recordRules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sale_date' => ['required', 'date'],
            // `decimal:0,2` because the money columns hold 2 places: without it 100.999 is
            // accepted, then rounds to 101.00 for display while the total still sums the
            // unrounded value — so the column would not add up to the tile above it.
            // max keeps it inside sales.gcash_amount, the narrowest column at decimal(10,2).
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * Only hand-entered records may be touched here. A POS sale's grand_total is the sum of
     * its sale_items, so rewriting it from a report would leave the two disagreeing.
     */
    private function guardManualRecord(Sale $sale): ?RedirectResponse
    {
        if (! $sale->isManualGcashRecord()) {
            return back()->with('error', 'Only manually added GCash records can be edited here. POS orders are managed from the POS.');
        }

        return null;
    }

    /**
     * A day closure stores gcash_sales_total as a snapshot taken at closing time. Changing a
     * sale afterwards would leave that snapshot stale and make this report disagree with the
     * Cash Report, so the day must be reopened first.
     */
    private function dayClosedResponse(int $branchId, ?string $date): ?RedirectResponse
    {
        if (! $date) {
            return null;
        }

        $closed = DayClosure::where('branch_id', $branchId)
            ->whereDate('closed_at_date', $date)
            ->exists();

        if (! $closed) {
            return null;
        }

        return back()->with('error', sprintf(
            'The day %s is already closed for this branch. Reopen it on the Cash Report before changing GCash records.',
            Carbon::parse($date)->format('M j, Y')
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function resolveRange(array $validated): array
    {
        $dateFrom = ! empty($validated['date_from'])
            ? Carbon::parse((string) $validated['date_from'])->toDateString()
            : $this->defaultDateFrom();

        $dateTo = ! empty($validated['date_to'])
            ? Carbon::parse((string) $validated['date_to'])->toDateString()
            : $this->defaultDateTo();

        // A backwards range would silently return nothing; swap it instead.
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    private function defaultDateFrom(): string
    {
        return now()->subDays(self::DEFAULT_RANGE_DAYS)->toDateString();
    }

    private function defaultDateTo(): string
    {
        return now()->toDateString();
    }

    /**
     * @return LengthAwarePaginator<int, Sale>
     */
    private function paginateTransactions(?int $branchId, string $dateFrom, string $dateTo, string $search): LengthAwarePaginator
    {
        return $this->salesQuery($branchId, $dateFrom, $dateTo, $search)
            ->with(['branch:id,name', 'cashier:id,name'])
            ->orderByDesc('sales.sale_datetime')
            ->orderByDesc('sales.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Paginated under its own page name so it does not fight the sales table over `?page=`.
     *
     * @return LengthAwarePaginator<int, Expense>
     */
    private function paginateExpenses(?int $branchId, string $dateFrom, string $dateTo): LengthAwarePaginator
    {
        return $this->expensesQuery($branchId, $dateFrom, $dateTo)
            ->with(['branch:id,name', 'category:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'expense_page')
            ->withQueryString();
    }

    /**
     * The single definition of "which sales this report is about". Every figure on the page —
     * the list, the total and the count — must come from this, or the tiles will contradict
     * the table beneath them.
     *
     * @return Builder<Sale>
     */
    private function salesQuery(?int $branchId, string $dateFrom, string $dateTo, string $search = ''): Builder
    {
        $query = Sale::query()
            ->gcashBearing()
            ->whereDate('sales.sale_datetime', '>=', $dateFrom)
            ->whereDate('sales.sale_datetime', '<=', $dateTo);

        if ($branchId !== null) {
            $query->where('sales.branch_id', $branchId);
        }

        if ($search !== '') {
            // `!` as the escape character rather than a backslash: MySQL and SQLite disagree on
            // whether a backslash in a string literal is itself an escape, so `!` is portable.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("sales.order_number LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        return $query;
    }

    /**
     * Paginated under its own page name so the three tables do not fight over `?page=`.
     *
     * @return LengthAwarePaginator<int, GcashAdjustment>
     */
    private function paginateAdjustments(?int $branchId, string $dateFrom, string $dateTo): LengthAwarePaginator
    {
        return $this->adjustmentsQuery($branchId, $dateFrom, $dateTo)
            ->with(['branch:id,name', 'recordedBy:id,name'])
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'adjustment_page')
            ->withQueryString();
    }

    /**
     * @return Builder<GcashAdjustment>
     */
    private function adjustmentsQuery(?int $branchId, string $dateFrom, string $dateTo): Builder
    {
        $query = GcashAdjustment::query()
            ->whereDate('adjustment_date', '>=', $dateFrom)
            ->whereDate('adjustment_date', '<=', $dateTo);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /**
     * @return Builder<Expense>
     */
    private function expensesQuery(?int $branchId, string $dateFrom, string $dateTo): Builder
    {
        $query = Expense::query()
            ->where('status', 'approved')
            ->where('payment_method', 'gcash')
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }
}
