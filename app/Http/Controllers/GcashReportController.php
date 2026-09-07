<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GcashAdjustment;
use App\Models\GcashEntryStatus;
use App\Models\GcashWallet;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Services\SaleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
        $expenses = $this->paginateExpenses($branchId, $dateFrom, $dateTo);

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
            // Declined entries are excluded throughout: an entry that never reached the wallet
            // is not GCash the business received. NOTE this is deliberately no longer the same
            // figure as day_closures.gcash_sales_total, which snapshots what was *recorded* at
            // closing time — see GcashReportTest for the documented divergence.
            'gcash_sales_total' => $gcashSalesTotal,
            'gcash_expenses_total' => $gcashExpensesTotal,
            // Already signed: a deduction is stored negative, so this adds.
            'adjustments_total' => $adjustmentsTotal,
            'net_gcash' => $gcashSalesTotal - $gcashExpensesTotal + $adjustmentsTotal,
            // The paginator already counted this exact filtered set.
            'transaction_count' => $sales->total(),
            'declined_count' => $this->declinedCountInRange($branchId, $dateFrom, $dateTo),
        ];

        return view('modules.gcash_report.index', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'sales' => $sales,
            'expenses' => $expenses,
            'adjustments' => $this->paginateAdjustments($branchId, $dateFrom, $dateTo),
            'statuses' => $this->statusLookup($sales, $expenses),
            'wallet' => $this->walletSummary($branchId),
            'walletDefaults' => $this->walletDefaults($dateTo),
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
     * The wallet balance: what should currently be sitting in the GCash account.
     *
     * Deliberately NOT filtered by the report's date range — a balance is a running position,
     * not a period figure, so it always counts everything from the opening balance forward.
     * It does follow the branch filter, because each branch has its own wallet.
     *
     * @return array{balance: float, opening_balance: float, opening_date: ?string,
     *               inflow: float, outflow: float, adjustments: float, configured: bool,
     *               branch_id: ?int, per_branch: array<int, array{name: string, balance: float}>}
     */
    private function walletSummary(?int $branchId): array
    {
        // Deliberately NOT filtered by is_active, unlike the branch dropdown: deactivating a
        // branch does not empty its GCash account, and a balance that silently drops real money
        // because of an admin flag would be wrong.
        $branches = $branchId !== null
            ? Branch::where('id', $branchId)->get()
            : Branch::orderBy('name')->get();

        $wallets = GcashWallet::whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');

        $balance = 0.0;
        $openingTotal = 0.0;
        $inflow = 0.0;
        $outflow = 0.0;
        $adjustments = 0.0;
        $perBranch = [];

        foreach ($branches as $branch) {
            $wallet = $wallets->get($branch->id);
            $opening = (float) ($wallet->opening_balance ?? 0);
            // With no wallet configured, count from the beginning of time rather than refusing
            // to show a balance — the figure is then "movement so far", which is still useful.
            $since = $wallet?->opening_date?->toDateString();

            $branchInflow = Sale::gcashAmountSum($this->walletSalesQuery($branch->id, $since));
            $branchOutflow = (float) $this->walletExpensesQuery($branch->id, $since)->sum('amount')
                + (float) $this->walletSpecialExpensesQuery($branch->id, $since)->sum('amount');
            $branchAdjustments = (float) $this->walletAdjustmentsQuery($branch->id, $since)->sum('amount');

            $branchBalance = $opening + $branchInflow - $branchOutflow + $branchAdjustments;

            $openingTotal += $opening;
            $inflow += $branchInflow;
            $outflow += $branchOutflow;
            $adjustments += $branchAdjustments;
            $balance += $branchBalance;

            // A branch only earns a line in the breakdown if it actually holds or moves money,
            // so including inactive branches in the maths does not clutter the display.
            if ($wallet !== null || abs($branchBalance) > 0.001) {
                $perBranch[] = [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'balance' => round($branchBalance, 2),
                    'configured' => $wallet !== null,
                ];
            }
        }

        // Applied once, outside the loop: this money belongs to no branch, so adding it
        // per-branch would subtract it as many times as there are branches.
        $companyWideOverhead = $this->companyWideGcashOverhead($branchId);
        $outflow += $companyWideOverhead;
        $balance -= $companyWideOverhead;

        $single = $branchId !== null ? $wallets->get($branchId) : null;

        return [
            'balance' => round($balance, 2),
            'opening_balance' => round($openingTotal, 2),
            // Only a single branch in scope has one meaningful date; across branches the
            // opening dates differ, so the view must not present one as if it were shared.
            'opening_date' => $single?->opening_date?->toDateString(),
            'inflow' => round($inflow, 2),
            'outflow' => round($outflow, 2),
            'adjustments' => round($adjustments, 2),
            'company_wide_overhead' => round($companyWideOverhead, 2),
            'configured' => $branchId !== null ? $single !== null : $wallets->count() === $branches->count(),
            'scope_count' => $branches->count(),
            'unconfigured_count' => $branches->count() - $wallets->count(),
            'branch_id' => $branchId,
            'per_branch' => $perBranch,
        ];
    }

    /**
     * Per-branch opening balances for the editing drawer.
     *
     * The drawer edits ONE branch's wallet, so it must never be seeded from the summary — that
     * carries a cross-branch total and, on the all-branches view, no opening date at all.
     * Saving those aggregates against a single branch would overwrite a real wallet with a
     * figure belonging to no branch.
     *
     * @return array<string, array{opening_balance: string, opening_date: string}>
     */
    private function walletDefaults(string $fallbackDate): array
    {
        $wallets = GcashWallet::all()->keyBy('branch_id');

        return Branch::orderBy('name')->get()
            ->mapWithKeys(function (Branch $branch) use ($wallets, $fallbackDate) {
                $wallet = $wallets->get($branch->id);

                return [(string) $branch->id => [
                    'opening_balance' => number_format((float) ($wallet->opening_balance ?? 0), 2, '.', ''),
                    'opening_date' => $wallet?->opening_date?->toDateString() ?? $fallbackDate,
                ]];
            })
            ->all();
    }

    /**
     * Set (or move) the point the running balance counts from.
     */
    public function updateWallet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'opening_date' => ['required', 'date'],
        ]);

        GcashWallet::updateOrCreate(
            ['branch_id' => (int) $validated['branch_id']],
            [
                'opening_balance' => $validated['opening_balance'],
                'opening_date' => $validated['opening_date'],
                'updated_by_user_id' => $request->user()->id,
            ]
        );

        return back()->with('success', 'Opening balance saved.');
    }

    /**
     * Mark an entry as seen on the wallet statement, missing from it, or back to unreviewed.
     */
    public function updateEntryStatus(Request $request, string $type, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                GcashEntryStatus::ACCEPTED,
                GcashEntryStatus::DECLINED,
                GcashEntryStatus::PENDING,
            ])],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        if (! in_array($type, [GcashEntryStatus::TYPE_SALE, GcashEntryStatus::TYPE_EXPENSE], true)) {
            return back()->with('error', 'That entry cannot be reviewed.');
        }

        if (! $this->entryExists($type, $id)) {
            return back()->with('error', 'That entry no longer exists.');
        }

        // Pending is the absence of a verdict, so clearing one deletes the row rather than
        // storing a third state that the balance query would have to know about.
        if ($validated['status'] === GcashEntryStatus::PENDING) {
            GcashEntryStatus::where('entry_type', $type)->where('entry_id', $id)->delete();

            return back()->with('success', 'Marked as not yet reviewed.');
        }

        GcashEntryStatus::updateOrCreate(
            ['entry_type' => $type, 'entry_id' => $id],
            [
                'status' => $validated['status'],
                'note' => $validated['note'] ?? null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]
        );

        return back()->with('success', $validated['status'] === GcashEntryStatus::DECLINED
            ? 'Marked as not received — it no longer counts toward the balance.'
            : 'Marked as received.');
    }

    private function entryExists(string $type, int $id): bool
    {
        return $type === GcashEntryStatus::TYPE_SALE
            ? Sale::whereKey($id)->exists()
            : Expense::whereKey($id)->exists();
    }

    /**
     * Verdicts for the rows actually on screen, keyed "type:id", so the view can label each row
     * without a query per row.
     *
     * Scoped to the current page rather than loading the whole table: the verdict history grows
     * without bound while a page only ever shows PER_PAGE rows of each kind.
     *
     * @param  LengthAwarePaginator<int, Sale>  $sales
     * @param  LengthAwarePaginator<int, Expense>  $expenses
     * @return array<string, array{status: string, note: ?string}>
     */
    private function statusLookup(LengthAwarePaginator $sales, LengthAwarePaginator $expenses): array
    {
        $saleIds = collect($sales->items())->pluck('id');
        $expenseIds = collect($expenses->items())->pluck('id');

        if ($saleIds->isEmpty() && $expenseIds->isEmpty()) {
            return [];
        }

        return GcashEntryStatus::query()
            ->where(function (Builder $query) use ($saleIds, $expenseIds) {
                $query->where(fn (Builder $q) => $q
                    ->where('entry_type', GcashEntryStatus::TYPE_SALE)
                    ->whereIn('entry_id', $saleIds))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('entry_type', GcashEntryStatus::TYPE_EXPENSE)
                        ->whereIn('entry_id', $expenseIds));
            })
            ->get()
            ->mapWithKeys(fn (GcashEntryStatus $row) => [
                $row->entry_type.':'.$row->entry_id => [
                    'status' => $row->status,
                    'note' => $row->note,
                ],
            ])
            ->all();
    }

    private function declinedCountInRange(?int $branchId, string $dateFrom, string $dateTo): int
    {
        // excludeDeclined = false, or the query would filter out the very rows being counted.
        $sales = (clone $this->salesQuery($branchId, $dateFrom, $dateTo, '', false))
            ->whereIn('sales.id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_SALE))
            ->count();

        $expenses = (clone $this->expensesQuery($branchId, $dateFrom, $dateTo, false))
            ->whereIn('id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_EXPENSE))
            ->count();

        return $sales + $expenses;
    }

    /**
     * @return Builder<Sale>
     */
    private function walletSalesQuery(int $branchId, ?string $since): Builder
    {
        $query = Sale::query()
            ->gcashBearing()
            ->where('sales.branch_id', $branchId)
            ->whereNotIn('sales.id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_SALE));

        if ($since !== null) {
            $query->whereDate('sales.sale_datetime', '>=', $since);
        }

        return $query;
    }

    /**
     * @return Builder<Expense>
     */
    private function walletExpensesQuery(int $branchId, ?string $since): Builder
    {
        $query = Expense::query()
            ->where('status', 'approved')
            ->where('payment_method', 'gcash')
            ->where('branch_id', $branchId)
            ->whereNotIn('id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_EXPENSE));

        if ($since !== null) {
            $query->whereDate('expense_date', '>=', $since);
        }

        return $query;
    }

    /**
     * GCash-paid monthly overhead, for the WALLET only.
     *
     * Special expenses are deliberately invisible to every daily figure — the drawer
     * count, today's net income, today's cash on hand. The wallet is none of those:
     * it is a real-money position, so a rent or electricity bill settled from the
     * GCash account genuinely left it and the balance must fall or it will never
     * reconcile against the GCash app.
     *
     * `net_gcash` deliberately does NOT get this leg. That figure is trading
     * performance — what GCash selling earned against what selling it cost — and a
     * month's rent would drag it negative in a perfectly healthy month.
     *
     * Dated by `paid_date` where known, falling back to `period_month`: a balance is
     * a settlement-date position, not an accrual one. Rows with no branch are
     * company-wide and belong to no single branch's wallet, so they are excluded
     * here and surfaced separately by companyWideGcashOverhead().
     *
     * @return Builder<SpecialExpense>
     */
    private function walletSpecialExpensesQuery(int $branchId, ?string $since): Builder
    {
        $query = SpecialExpense::query()
            ->where('payment_method', 'gcash')
            ->where('branch_id', $branchId);

        if ($since !== null) {
            // whereRaw, not whereDate: the comparison is on COALESCE of two columns,
            // and a plain string compare is correct on both drivers here — MySQL
            // compares DATEs, and SQLite's 'Y-m-d H:i:s' strings share the date
            // prefix, so they order correctly against a 'Y-m-d' bound.
            $query->whereRaw('COALESCE(paid_date, period_month) >= ?', [$since]);
        }

        return $query;
    }

    /**
     * GCash overhead that covers the whole business rather than one location.
     *
     * It cannot be charged to any single branch's wallet without distorting that
     * branch, so it is reported as its own line and subtracted from the all-branches
     * total only.
     */
    private function companyWideGcashOverhead(?int $branchId): float
    {
        // A single-branch view is a statement about that branch's wallet, and this
        // money belongs to no branch, so it has no place there.
        if ($branchId !== null) {
            return 0.0;
        }

        return (float) SpecialExpense::query()
            ->where('payment_method', 'gcash')
            ->whereNull('branch_id')
            ->sum('amount');
    }

    /**
     * @return Builder<GcashAdjustment>
     */
    private function walletAdjustmentsQuery(int $branchId, ?string $since): Builder
    {
        $query = GcashAdjustment::query()->where('branch_id', $branchId);

        if ($since !== null) {
            $query->whereDate('adjustment_date', '>=', $since);
        }

        return $query;
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
        // excludeDeclined = false: a declined row must stay listed so it can be reviewed again.
        return $this->salesQuery($branchId, $dateFrom, $dateTo, $search, false)
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
        return $this->expensesQuery($branchId, $dateFrom, $dateTo, false)
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
    private function salesQuery(?int $branchId, string $dateFrom, string $dateTo, string $search = '', bool $excludeDeclined = true): Builder
    {
        $query = Sale::query()
            ->gcashBearing()
            ->whereDate('sales.sale_datetime', '>=', $dateFrom)
            ->whereDate('sales.sale_datetime', '<=', $dateTo);

        // Money that never reached the wallet is not money received, so it is out of every
        // reported figure. The listing passes false so a declined row stays visible and can be
        // un-declined — excluding it from the table would strand it.
        if ($excludeDeclined) {
            $query->whereNotIn('sales.id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_SALE));
        }

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
    private function expensesQuery(?int $branchId, string $dateFrom, string $dateTo, bool $excludeDeclined = true): Builder
    {
        $query = Expense::query()
            ->where('status', 'approved')
            ->where('payment_method', 'gcash')
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo);

        if ($excludeDeclined) {
            $query->whereNotIn('id', GcashEntryStatus::declinedIds(GcashEntryStatus::TYPE_EXPENSE));
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }
}
