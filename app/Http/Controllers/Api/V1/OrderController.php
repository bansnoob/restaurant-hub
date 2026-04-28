<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        private readonly SaleService $saleService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $query = Sale::where('branch_id', $branchId)
            ->with('saleItems')
            ->orderByDesc('sale_datetime');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $date = $request->string('date')->toString() ?: now()->toDateString();
        $query->whereDate('sale_datetime', $date);

        return SaleResource::collection($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'order_type' => ['sometimes', 'in:dine_in,takeout,delivery'],
            'table_label' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.discount_total' => ['sometimes', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branchId = $validated['branch_id'] ?? $this->resolveBranchId($request);
        $validated['branch_id'] = $branchId;

        $sale = $this->saleService->createOrder($validated, $request->user());

        return (new SaleResource($sale->load('saleItems')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Sale $sale, Request $request): SaleResource
    {
        $this->authorizeBranchAccess($request, $sale);

        return new SaleResource($sale->load('saleItems', 'cashier'));
    }

    public function pay(Sale $sale, Request $request): SaleResource
    {
        $this->authorizeBranchAccess($request, $sale);

        $validated = $request->validate([
            'payment_method' => ['required', 'in:cash,gcash,mixed'],
            'paid_total' => ['required', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'gcash_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sale = $this->saleService->processPayment(
            $sale,
            $validated['payment_method'],
            (float) $validated['paid_total'],
            isset($validated['cash_amount']) ? (float) $validated['cash_amount'] : null,
            isset($validated['gcash_amount']) ? (float) $validated['gcash_amount'] : null,
        );

        return new SaleResource($sale->load('saleItems'));
    }

    public function void(Sale $sale, Request $request): SaleResource
    {
        $this->authorizeBranchAccess($request, $sale);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $sale = $this->saleService->voidSale($sale, $validated['reason'] ?? null);

        return new SaleResource($sale->load('saleItems'));
    }

    private function resolveBranchId(Request $request): int
    {
        $user = $request->user();

        if ($user->hasRole('owner') && $request->filled('branch_id')) {
            return $request->integer('branch_id');
        }

        $branchId = $user->resolveBranchId();
        abort_unless($branchId, 403, 'User is not linked to any branch.');

        return $branchId;
    }

    private function authorizeBranchAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user->hasRole('owner')) {
            return;
        }

        $branchId = $user->resolveBranchId();
        abort_unless($branchId && $branchId === (int) $sale->branch_id, 403);
    }
}
