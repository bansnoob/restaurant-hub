<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

        $totals = [
            'gcash_sales_total' => $gcashSalesTotal,
            'gcash_expenses_total' => $gcashExpensesTotal,
            'net_gcash' => $gcashSalesTotal - $gcashExpensesTotal,
            // The paginator already counted this exact filtered set.
            'transaction_count' => $sales->total(),
        ];

        return view('modules.gcash_report.index', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'sales' => $sales,
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
