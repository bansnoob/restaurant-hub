<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
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

        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $base = Sale::query()
            ->whereDate('sale_datetime', '>=', $dateFrom)
            ->whereDate('sale_datetime', '<=', $dateTo);

        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $base->where('branch_id', (int) $branchFilter);
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

        $sales = (clone $listQuery)
            ->with(['branch:id,name', 'cashier:id,name'])
            ->withCount('saleItems')
            ->orderByDesc('sale_datetime')
            ->paginate(20)
            ->withQueryString();

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

        $filters = [
            'preset' => $preset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $branchFilter,
            'statuses' => $statusFilter,
            'payment_method' => $paymentFilter,
            'search' => $search,
        ];

        return view('modules.sales.index', compact(
            'sales',
            'summary',
            'filters',
            'branches',
            'paymentBreakdown',
            'orderTypeBreakdown',
            'dailySeries',
        ));
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
