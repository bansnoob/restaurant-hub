<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBranch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SaleResource;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ResolvesBranch;

    private const CROSS_BRANCH_DENIED = 'You cannot place an order for another branch.';

    public function __construct(
        private readonly SaleService $saleService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request, self::CROSS_BRANCH_DENIED);

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

        $branchId = $this->resolveBranchId($request, self::CROSS_BRANCH_DENIED);
        $validated['branch_id'] = $branchId;
        $this->assertItemsOnBranchMenu($validated['items'], $branchId);

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

    /**
     * Every line must come from the order's own branch menu. The iPad rings up
     * whatever menu it last cached, so after a branch switch it can send another
     * branch's item ids; booking them would put that branch's items and prices
     * into this branch's sales.
     *
     * @param  array<int, array{menu_item_id: int|string}>  $items
     */
    private function assertItemsOnBranchMenu(array $items, int $branchId): void
    {
        $onMenu = MenuItem::where('branch_id', $branchId)
            ->whereIn('id', array_column($items, 'menu_item_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $errors = [];
        foreach ($items as $index => $item) {
            if (! in_array((int) $item['menu_item_id'], $onMenu, true)) {
                $errors["items.{$index}.menu_item_id"] = ["This item is not on this branch's menu. Refresh the menu and try again."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
