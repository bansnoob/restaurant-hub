<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SalesController extends Controller
{
    private const ORDER_TYPES = ['dine_in', 'takeout', 'delivery'];

    private const STATUSES = ['completed', 'open', 'voided', 'refunded'];

    private const PAYMENT_METHODS = ['cash', 'gcash', 'mixed', 'unpaid'];

    public function index(Request $request): View
    {
        $preset = (string) $request->query('preset', '');
        [$dateFrom, $dateTo] = $this->resolveRange($request, $preset);

        $branchFilter = $request->query('branch_id');
        $statusFilter = (array) $request->query('statuses', []);
        $paymentFilter = (string) $request->query('payment_method', '');
        $search = trim((string) $request->query('search', ''));

        $statusFilter = array_values(array_intersect(self::STATUSES, $statusFilter));
        $branchId = (! empty($branchFilter) && is_numeric($branchFilter)) ? (int) $branchFilter : null;

        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        // Categories are branch-scoped (a null branch means shared). When a branch is chosen,
        // only offer categories that could actually appear for it.
        $categories = MenuCategory::where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('name')
            ->get();

        $categoryFilter = $request->query('category_id');
        $categoryId = (! empty($categoryFilter) && is_numeric($categoryFilter)
            && $categories->contains('id', (int) $categoryFilter))
            ? (int) $categoryFilter
            : null;

        $base = Sale::query()
            ->whereDate('sale_datetime', '>=', $dateFrom)
            ->whereDate('sale_datetime', '<=', $dateTo);

        if ($branchId !== null) {
            $base->where('branch_id', $branchId);
        }

        $listQuery = (clone $base);

        if (! empty($statusFilter)) {
            $listQuery->whereIn('status', $statusFilter);
        }
        if (in_array($paymentFilter, self::PAYMENT_METHODS, true)) {
            $listQuery->where('payment_method', $paymentFilter);
        }
        if ($search !== '') {
            $listQuery->where('order_number', 'like', '%'.$search.'%');
        }
        // Only orders that actually contain the category appear in the list.
        if ($categoryId !== null) {
            $listQuery->whereHas('saleItems.menuItem', fn (Builder $q) => $q->where('category_id', $categoryId));
        }

        $listQuery
            ->with(['branch:id,name', 'cashier:id,name'])
            ->withCount('saleItems');

        // In category mode each row shows only the category's slice of the order, not its total.
        if ($categoryId !== null) {
            $constraint = fn (Builder $q) => $q->whereHas(
                'menuItem',
                fn (Builder $m) => $m->where('category_id', $categoryId)
            );
            $listQuery
                ->withSum(['saleItems as category_line' => $constraint], 'line_total')
                ->withCount(['saleItems as category_items' => $constraint]);
        }

        $sales = $listQuery
            ->orderByDesc('sale_datetime')
            ->paginate(20)
            ->withQueryString();

        // Branch and category are scope dimensions — they shape the summary tiles too. The
        // payment/status/search filters only ever narrow the list.
        if ($categoryId !== null) {
            $summary = $this->categorySummary($categoryId, $dateFrom, $dateTo, $branchId);
            $paymentBreakdown = $this->categoryPaymentBreakdown($categoryId, $dateFrom, $dateTo, $branchId);
            $orderTypeBreakdown = $this->categoryOrderTypeBreakdown($categoryId, $dateFrom, $dateTo, $branchId);
            $dailySeries = $this->categoryDailySeries($categoryId, $dateFrom, $dateTo, $branchId);
        } else {
            $completedClone = (clone $base)->where('status', 'completed');
            $allClone = (clone $base);

            $orders = (clone $completedClone)->count();
            $grossSales = (float) (clone $completedClone)->sum('grand_total');
            $discounts = (float) (clone $completedClone)->sum('discount_total');
            $voidedCount = (clone $allClone)->where('status', 'voided')->count();
            $refundedCount = (clone $allClone)->where('status', 'refunded')->count();
            $voidedTotal = (float) (clone $allClone)->where('status', 'voided')->sum('grand_total');
            $refundedTotal = (float) (clone $allClone)->where('status', 'refunded')->sum('grand_total');
            $netSales = $grossSales - $voidedTotal - $refundedTotal;
            $avgOrder = $orders > 0 ? $grossSales / $orders : 0.0;

            $summary = [
                'orders' => $orders,
                'gross_sales' => $grossSales,
                'net_sales' => $netSales,
                'avg_order' => $avgOrder,
                'discounts' => $discounts,
                'voided_count' => $voidedCount,
                'voided_total' => $voidedTotal,
                'refunded_count' => $refundedCount,
                'refunded_total' => $refundedTotal,
            ];

            $paymentBreakdown = $this->paymentBreakdown(clone $completedClone);
            $orderTypeBreakdown = $this->orderTypeBreakdown(clone $completedClone);
            $dailySeries = $this->dailySeries(clone $completedClone, $dateFrom, $dateTo);
        }

        $filters = [
            'preset' => $preset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $branchFilter,
            'category_id' => $categoryId,
            'statuses' => $statusFilter,
            'payment_method' => $paymentFilter,
            'search' => $search,
        ];

        return view('modules.sales.index', compact(
            'sales',
            'summary',
            'filters',
            'branches',
            'categories',
            'paymentBreakdown',
            'orderTypeBreakdown',
            'dailySeries',
        ));
    }

    /**
     * Line-items of one category, joined to their sale and menu item, scoped to date and branch.
     * The join to menu_items naturally drops items whose menu_item was deleted (menu_item_id is
     * nulled on delete) — such a line has no category and cannot be attributed to one.
     *
     * @return Builder<SaleItem>
     */
    private function categoryItemsBase(int $categoryId, string $dateFrom, string $dateTo, ?int $branchId): Builder
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('menu_items', 'menu_items.id', '=', 'sale_items.menu_item_id')
            ->where('menu_items.category_id', $categoryId)
            ->whereDate('sales.sale_datetime', '>=', $dateFrom)
            ->whereDate('sales.sale_datetime', '<=', $dateTo)
            ->when($branchId !== null, fn (Builder $q) => $q->where('sales.branch_id', $branchId));
    }

    /**
     * Summary tiles for a category — the category's slice of each order rather than the order
     * total. Orders are counted as distinct sales that contain the category.
     *
     * @return array<string, float|int>
     */
    private function categorySummary(int $categoryId, string $dateFrom, string $dateTo, ?int $branchId): array
    {
        $base = fn () => $this->categoryItemsBase($categoryId, $dateFrom, $dateTo, $branchId);

        $orders = (int) $base()->where('sales.status', 'completed')->distinct()->count('sales.id');
        $grossSales = (float) $base()->where('sales.status', 'completed')->sum('sale_items.line_total');
        $discounts = (float) $base()->where('sales.status', 'completed')->sum('sale_items.discount_total');

        $voidedTotal = (float) $base()->where('sales.status', 'voided')->sum('sale_items.line_total');
        $voidedCount = (int) $base()->where('sales.status', 'voided')->distinct()->count('sales.id');
        $refundedTotal = (float) $base()->where('sales.status', 'refunded')->sum('sale_items.line_total');
        $refundedCount = (int) $base()->where('sales.status', 'refunded')->distinct()->count('sales.id');

        $netSales = $grossSales - $voidedTotal - $refundedTotal;

        return [
            'orders' => $orders,
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'avg_order' => $orders > 0 ? $grossSales / $orders : 0.0,
            'discounts' => $discounts,
            'voided_count' => $voidedCount,
            'voided_total' => $voidedTotal,
            'refunded_count' => $refundedCount,
            'refunded_total' => $refundedTotal,
        ];
    }

    /**
     * Cash vs GCash for the category's takings. A mixed-tender order is split by its own
     * cash:total ratio, so the category slice is attributed the same way the order was paid.
     *
     * @return array<string, array{count: int, total: float}>
     */
    private function categoryPaymentBreakdown(int $categoryId, string $dateFrom, string $dateTo, ?int $branchId): array
    {
        // The `* 1.0` forces real division: SQLite integer-divides two integer-valued decimals
        // (400 / 1000 = 0), while MySQL does not. Multiplying the numerator by 1.0 first keeps
        // both engines in floating point and agreeing.
        $cashPortion = "CASE
            WHEN sales.payment_method = 'cash' THEN sale_items.line_total
            WHEN sales.payment_method = 'mixed' AND sales.grand_total > 0
                THEN sale_items.line_total * COALESCE(sales.cash_amount, 0) * 1.0 / sales.grand_total
            ELSE 0 END";
        $gcashPortion = "CASE
            WHEN sales.payment_method = 'gcash' THEN sale_items.line_total
            WHEN sales.payment_method = 'mixed' AND sales.grand_total > 0
                THEN sale_items.line_total * COALESCE(sales.gcash_amount, 0) * 1.0 / sales.grand_total
            ELSE 0 END";

        $row = $this->categoryItemsBase($categoryId, $dateFrom, $dateTo, $branchId)
            ->where('sales.status', 'completed')
            ->selectRaw("
                COALESCE(SUM($cashPortion), 0) as cash_total,
                COALESCE(SUM($gcashPortion), 0) as gcash_total,
                COUNT(DISTINCT CASE WHEN sales.payment_method = 'cash' THEN sales.id END) as cash_cnt,
                COUNT(DISTINCT CASE WHEN sales.payment_method = 'gcash' THEN sales.id END) as gcash_cnt,
                COUNT(DISTINCT CASE WHEN sales.payment_method = 'mixed' THEN sales.id END) as mixed_cnt
            ")
            ->first();

        return [
            'cash' => ['total' => (float) $row->cash_total, 'count' => (int) $row->cash_cnt],
            'gcash' => ['total' => (float) $row->gcash_total, 'count' => (int) $row->gcash_cnt],
            'mixed' => ['count' => (int) $row->mixed_cnt],
        ];
    }

    /**
     * @return array<string, array{count: int, total: float}>
     */
    private function categoryOrderTypeBreakdown(int $categoryId, string $dateFrom, string $dateTo, ?int $branchId): array
    {
        $rows = $this->categoryItemsBase($categoryId, $dateFrom, $dateTo, $branchId)
            ->where('sales.status', 'completed')
            ->selectRaw('sales.order_type, COUNT(DISTINCT sales.id) as cnt, SUM(sale_items.line_total) as total')
            ->groupBy('sales.order_type')
            ->get()
            ->keyBy('order_type');

        $breakdown = [];
        foreach (self::ORDER_TYPES as $type) {
            $row = $rows->get($type);
            $breakdown[$type] = [
                'count' => $row ? (int) $row->cnt : 0,
                'total' => $row ? (float) $row->total : 0.0,
            ];
        }

        return $breakdown;
    }

    /**
     * @return array<int, array{date: string, label: string, short: string, total: float, orders: int, is_today: bool}>
     */
    private function categoryDailySeries(int $categoryId, string $dateFrom, string $dateTo, ?int $branchId): array
    {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        if ((int) $start->diffInDays($end) + 1 > 31) {
            $start = $end->copy()->subDays(13);
        }

        $rows = $this->categoryItemsBase($categoryId, $start->toDateString(), $dateTo, $branchId)
            ->where('sales.status', 'completed')
            ->selectRaw('DATE(sales.sale_datetime) as day, COUNT(DISTINCT sales.id) as cnt, SUM(sale_items.line_total) as total')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $today = now()->toDateString();
        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);
            $series[] = [
                'date' => $date,
                'label' => $cursor->format('M j'),
                'short' => $cursor->format('j'),
                'dow' => $cursor->format('D'),
                'total' => $row ? (float) $row->total : 0.0,
                'orders' => $row ? (int) $row->cnt : 0,
                'is_today' => $date === $today,
            ];
            $cursor->addDay();
        }

        return $series;
    }

    public function show(Sale $sale): JsonResponse
    {
        $sale->load(['branch:id,name', 'cashier:id,name']);
        $items = SaleItem::where('sale_id', $sale->id)
            ->orderBy('id')
            ->get(['id', 'item_name', 'unit_price', 'quantity', 'discount_total', 'tax_total', 'line_total']);

        $cashAmount = (float) ($sale->cash_amount ?? 0);
        $gcashAmount = (float) ($sale->gcash_amount ?? 0);
        if ($sale->payment_method === 'cash' && $cashAmount === 0.0) {
            $cashAmount = (float) $sale->grand_total;
        }
        if ($sale->payment_method === 'gcash' && $gcashAmount === 0.0) {
            $gcashAmount = (float) $sale->grand_total;
        }

        return response()->json([
            'sale' => [
                'id' => $sale->id,
                'order_number' => $sale->order_number,
                'sale_datetime' => optional($sale->sale_datetime)->toIso8601String(),
                'sale_datetime_label' => $sale->sale_datetime?->format('M j, Y · h:i A'),
                'closed_at' => optional($sale->closed_at)->toIso8601String(),
                'order_type' => $sale->order_type,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'sub_total' => (float) $sale->sub_total,
                'discount_total' => (float) $sale->discount_total,
                'tax_total' => (float) $sale->tax_total,
                'grand_total' => (float) $sale->grand_total,
                'paid_total' => (float) $sale->paid_total,
                'change_total' => (float) $sale->change_total,
                'cash_amount' => $cashAmount,
                'gcash_amount' => $gcashAmount,
                'table_label' => $sale->table_label,
                'notes' => $sale->notes,
                'branch' => $sale->branch ? ['id' => $sale->branch->id, 'name' => $sale->branch->name] : null,
                'cashier' => $sale->cashier ? ['id' => $sale->cashier->id, 'name' => $sale->cashier->name] : null,
            ],
            'items' => $items,
        ]);
    }

    /**
     * @return array{start: string, end: string}|array<int, string>
     */
    private function resolveRange(Request $request, string $preset): array
    {
        $today = now()->toDateString();

        switch ($preset) {
            case 'today':
                return [$today, $today];
            case 'yesterday':
                $y = now()->subDay()->toDateString();
                return [$y, $y];
            case '7d':
                return [now()->subDays(6)->toDateString(), $today];
            case '30d':
                return [now()->subDays(29)->toDateString(), $today];
            case 'month':
                return [now()->startOfMonth()->toDateString(), $today];
            default:
                $from = $request->string('date_from')->toString() ?: now()->startOfMonth()->toDateString();
                $to = $request->string('date_to')->toString() ?: $today;
                if (Carbon::parse($from)->gt(Carbon::parse($to))) {
                    [$from, $to] = [$to, $from];
                }
                return [$from, $to];
        }
    }

    /**
     * @return array<string, array{count: int, total: float}>
     */
    private function paymentBreakdown($query): array
    {
        $rows = $query
            ->selectRaw('payment_method, COUNT(*) as cnt, SUM(grand_total) as total, SUM(COALESCE(cash_amount, 0)) as cash_amt, SUM(COALESCE(gcash_amount, 0)) as gcash_amt')
            ->groupBy('payment_method')
            ->get();

        $cashTotal = 0.0;
        $gcashTotal = 0.0;
        $cashCount = 0;
        $gcashCount = 0;
        $mixedCount = 0;

        foreach ($rows as $row) {
            $count = (int) $row->cnt;
            $total = (float) $row->total;
            switch ($row->payment_method) {
                case 'cash':
                    $cashTotal += $total;
                    $cashCount += $count;
                    break;
                case 'gcash':
                    $gcashTotal += $total;
                    $gcashCount += $count;
                    break;
                case 'mixed':
                    $cashTotal += (float) $row->cash_amt;
                    $gcashTotal += (float) $row->gcash_amt;
                    $mixedCount += $count;
                    break;
            }
        }

        return [
            'cash' => ['total' => $cashTotal, 'count' => $cashCount],
            'gcash' => ['total' => $gcashTotal, 'count' => $gcashCount],
            'mixed' => ['count' => $mixedCount],
        ];
    }

    /**
     * @return array<string, array{count: int, total: float}>
     */
    private function orderTypeBreakdown($query): array
    {
        $rows = $query
            ->selectRaw('order_type, COUNT(*) as cnt, SUM(grand_total) as total')
            ->groupBy('order_type')
            ->get()
            ->keyBy('order_type');

        $breakdown = [];
        foreach (self::ORDER_TYPES as $type) {
            $row = $rows->get($type);
            $breakdown[$type] = [
                'count' => $row ? (int) $row->cnt : 0,
                'total' => $row ? (float) $row->total : 0.0,
            ];
        }
        return $breakdown;
    }

    /**
     * @return array<int, array{date: string, label: string, total: float, orders: int, is_today: bool}>
     */
    private function dailySeries($query, string $dateFrom, string $dateTo): array
    {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > 31) {
            $start = $end->copy()->subDays(13);
        }

        $rows = $query
            ->selectRaw('DATE(sale_datetime) as day, COUNT(*) as cnt, SUM(grand_total) as total')
            ->whereDate('sale_datetime', '>=', $start->toDateString())
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $today = now()->toDateString();
        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);
            $series[] = [
                'date' => $date,
                'label' => $cursor->format('M j'),
                'short' => $cursor->format('j'),
                'dow' => $cursor->format('D'),
                'total' => $row ? (float) $row->total : 0.0,
                'orders' => $row ? (int) $row->cnt : 0,
                'is_today' => $date === $today,
            ];
            $cursor->addDay();
        }
        return $series;
    }
}
