<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBranch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StockCountResource;
use App\Http\Resources\V1\StockCountSessionResource;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockCountController extends Controller
{
    use ResolvesBranch;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /** Open a count session: the pre-filled walk list plus the restock fence. */
    public function start(Request $request): StockCountSessionResource
    {
        $request->validate(['branch_id' => ['sometimes', 'integer', 'exists:branches,id']]);

        return new StockCountSessionResource(
            $this->inventory->buildCountSession($this->resolveBranchId($request))
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $request->integer('limit') ?: InventoryService::RECENT_COUNTS_LIMIT;

        return StockCountResource::collection(
            $this->inventory->recentCounts($this->resolveBranchId($request), $limit)
        );
    }

    /**
     * Submit the whole count as one batch.
     *
     * previous_quantity and restocked_quantity are RECOMPUTED server-side from
     * the InventoryService primitives inside the transaction; the client's only
     * authoritative input is counted_quantity.
     */
    public function store(Request $request): JsonResponse
    {
        // Two-phase: scalars first, so branch_id is resolved before the entries
        // array is validated against a branch-scoped allow-list.
        $scalars = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'counted_at' => ['required', 'date', 'before_or_equal:today'],
            'restock_cursor' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $branchId = $this->resolveBranchId($request);

        $entries = $this->validateEntries($request, $branchId);
        $cursor = (int) $scalars['restock_cursor'];

        // `min:0` is not a bound: an unvalidated cursor could claim every
        // unclaimed movement in the branch, including deliveries logged after
        // this session opened whose quantities nobody counted.
        abort_if(
            $cursor > $this->inventory->restockCursor($branchId),
            422,
            'Stale count session. Reopen the count.'
        );

        $stockCount = $this->inventory->recordCount(
            $branchId,
            (string) $scalars['counted_at'],
            $entries,
            $cursor,
            $scalars['notes'] ?? null,
            $request->user(),
        );

        return (new StockCountResource($this->loadCountAggregates($stockCount)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return array<int, array{counted: float, declared_restock: null}>
     *                                                                   keyed by ingredient_id. The mobile client's restocks arrive as
     *                                                                   inventory_movements, so it never declares one here.
     */
    private function validateEntries(Request $request, int $branchId): array
    {
        // One query for the whole allow-list instead of one `exists` round trip
        // per row. `is_active` is deliberately NOT part of the constraint: an
        // ingredient deactivated mid-session must still record, rather than
        // 422-ing an hour of counting.
        $allowed = Ingredient::where('branch_id', $branchId)->pluck('id')->all();

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:'.InventoryService::MAX_COUNT_ENTRIES],
            'entries.*.ingredient_id' => ['required', 'integer', 'distinct', Rule::in($allowed)],
            'entries.*.counted_quantity' => [
                'required', 'numeric', 'min:0',
                'max:'.InventoryService::MAX_QUANTITY,
                'decimal:0,'.InventoryService::QUANTITY_SCALE,
            ],
            // Accepted for wire compatibility with the web blade, then ignored.
            'entries.*.previous_quantity' => ['sometimes', 'numeric'],
            'entries.*.restocked_quantity' => ['sometimes', 'numeric'],
        ]);

        $result = [];
        foreach ($validated['entries'] as $row) {
            $result[(int) $row['ingredient_id']] = [
                'counted' => (float) $row['counted_quantity'],
                'declared_restock' => null,
            ];
        }

        return $result;
    }

    private function loadCountAggregates(StockCount $stockCount): StockCount
    {
        return StockCount::with('recordedBy:id,name')
            ->withCount('entries')
            ->withSum('entries', 'consumption')
            ->findOrFail($stockCount->id);
    }
}
