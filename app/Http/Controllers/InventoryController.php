<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Services\InventoryService;
use App\Support\Inventory\StockCountSessionRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The owner-facing web inventory module.
 *
 * All inventory arithmetic lives in App\Services\InventoryService, shared with
 * the mobile API controllers, so the two surfaces can never drift on
 * consumption, previous quantities or the restock claim.
 */
class InventoryController extends Controller
{
    private const UNITS = InventoryService::UNITS;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $branchFilter = $request->query('branch_id');
        $unitFilter = (string) $request->query('unit', '');
        $search = trim((string) $request->query('search', ''));
        $lowOnly = (bool) $request->query('low_only', false);

        // `include_inactive` is deliberately absent: the web index lists active
        // and inactive ingredients together, as it always has.
        $views = $this->inventory->listIngredients($this->branchId($branchFilter), [
            'search' => $search,
            'unit' => in_array($unitFilter, self::UNITS, true) ? $unitFilter : '',
            'low_only' => $lowOnly,
        ]);

        $ingredients = $views->map->toDecoratedModel();

        $allIngredients = Ingredient::with('branch')->orderBy('name')->get();
        $lowStock = $allIngredients->filter(fn (Ingredient $i) => $i->isLowStock());

        $summary = $this->inventory->summary(null);
        $stats = [
            'total_items' => $summary->totalItems,
            'active_items' => $summary->activeItems,
            'low_stock_count' => $summary->lowStockCount,
            'days_since_last_count' => $summary->daysSinceLastCount,
            'last_count_at' => $summary->lastCountAt,
            'counts_this_month' => $summary->countsThisMonth,
        ];

        $recentCounts = $this->inventory->recentCounts(null);

        $filters = [
            'search' => $search,
            'branch_id' => $branchFilter,
            'unit' => $unitFilter,
            'low_only' => $lowOnly,
        ];

        return view('modules.inventory.index', compact(
            'branches',
            'ingredients',
            'allIngredients',
            'lowStock',
            'recentCounts',
            'stats',
            'filters',
        ));
    }

    public function showIngredient(Ingredient $ingredient): JsonResponse
    {
        $ingredient->load('branch');

        $view = $this->inventory->viewIngredient($ingredient);
        $stats = $view->stats;
        $entries = $this->inventory->ingredientHistory($ingredient);

        return response()->json([
            'ingredient' => [
                'id' => $ingredient->id,
                'branch_id' => $ingredient->branch_id,
                'branch_name' => $ingredient->branch?->name,
                'name' => $ingredient->name,
                'sku' => $ingredient->sku,
                'unit' => $ingredient->unit,
                'current_stock' => (float) $ingredient->current_stock,
                'reorder_level' => (float) $ingredient->reorder_level,
                'is_active' => (bool) $ingredient->is_active,
                'is_low_stock' => $ingredient->isLowStock(),
                // The drawer has always received 0 (never null) for an
                // ingredient with too few counts to derive a rate from.
                'daily_consumption' => $stats->dailyConsumption ?? 0.0,
                'days_remaining' => $stats->daysRemaining,
                'last_counted_at' => $stats->lastCountedAt,
                'pending_restock' => $stats->pendingRestock,
            ],
            'history' => $entries->map(fn (StockCountEntry $e) => [
                'id' => $e->id,
                'counted_at' => $e->stockCount?->counted_at?->toDateString(),
                'counted_at_label' => $e->stockCount?->counted_at?->format('M j, Y'),
                'previous_quantity' => (float) $e->previous_quantity,
                'restocked_quantity' => (float) $e->restocked_quantity,
                'counted_quantity' => (float) $e->counted_quantity,
                'consumption' => (float) $e->consumption,
            ])->values(),
        ]);
    }

    public function startCount(Request $request): JsonResponse
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        // No branch_id in the query means EVERY branch — the blade fetches this
        // endpoint bare and lets the owner pick the branch in the modal.
        $session = $this->inventory->buildCountSession($this->branchId($request->query('branch_id')));

        return response()->json([
            'today' => $session->today,
            'branches' => $branches,
            'restock_cursor' => $session->restockCursor,
            'ingredients' => array_map(fn (StockCountSessionRow $row): array => [
                'ingredient_id' => $row->ingredientId,
                'name' => $row->name,
                'sku' => $row->sku,
                'unit' => $row->unit,
                'branch_id' => $row->branchId,
                'branch_name' => $row->branchName,
                'reorder_level' => $row->reorderLevel,
                'previous_quantity' => $row->previousQuantity,
                // The web count table's "Restocked" column is an EDITABLE
                // operator field. It is seeded with 0 so a manually typed
                // delivery is never added on top of the quantity already
                // derived from inventory_movements; the derived figure is
                // exposed read-only as pending_restock and folded in server-side.
                'restocked_quantity' => 0.0,
                'pending_restock' => $row->restockedQuantity,
                'expected_quantity' => $row->expectedQuantity,
                'counted_quantity' => $row->countedQuantity,
            ], $session->rows),
        ]);
    }

    public function storeCount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'counted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'entries.*.previous_quantity' => ['required', 'numeric', 'min:0'],
            'entries.*.restocked_quantity' => ['nullable', 'numeric', 'min:0'],
            'entries.*.counted_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $entries = [];
        foreach ($validated['entries'] as $row) {
            $entries[(int) $row['ingredient_id']] = [
                'counted' => (float) $row['counted_quantity'],
                // The operator's manual figure is an observation the movements
                // ledger does not know about, so the service ADDS it to the
                // claimed movement sum rather than replacing it.
                'declared_restock' => isset($row['restocked_quantity'])
                    ? (float) $row['restocked_quantity']
                    : null,
            ];
        }

        $this->inventory->recordCount(
            (int) $validated['branch_id'],
            (string) $validated['counted_at'],
            $entries,
            // null is the WEB sentinel: claim every unclaimed movement at
            // commit time. The blade posts no cursor.
            null,
            $validated['notes'] ?? null,
            $request->user(),
        );

        return redirect()->route('inventory.index')->with('success', 'Stock count saved.');
    }

    public function showCount(StockCount $stockCount): JsonResponse
    {
        $stockCount->load(['branch:id,name', 'recordedBy:id,name']);
        $entries = StockCountEntry::with('ingredient:id,name,sku,unit')
            ->where('stock_count_id', $stockCount->id)
            ->get();

        $countedAt = Carbon::parse($stockCount->counted_at);
        $previousCount = StockCount::where('counted_at', '<', $stockCount->counted_at)
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();
        $daysSincePrevious = $previousCount ? (int) Carbon::parse($previousCount->counted_at)->diffInDays($countedAt) : null;

        return response()->json([
            'count' => [
                'id' => $stockCount->id,
                'counted_at' => $stockCount->counted_at?->toDateString(),
                'counted_at_label' => $stockCount->counted_at?->format('M j, Y'),
                'branch' => $stockCount->branch ? ['id' => $stockCount->branch->id, 'name' => $stockCount->branch->name] : null,
                'recorded_by' => $stockCount->recordedBy?->name,
                'notes' => $stockCount->notes,
                'days_since_previous' => $daysSincePrevious,
            ],
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->ingredient?->name ?? '—',
                'sku' => $e->ingredient?->sku,
                'unit' => $e->ingredient?->unit ?? '—',
                'previous_quantity' => (float) $e->previous_quantity,
                'restocked_quantity' => (float) $e->restocked_quantity,
                'counted_quantity' => (float) $e->counted_quantity,
                'consumption' => (float) $e->consumption,
            ])->values(),
        ]);
    }

    /**
     * The "most recent count" guard is now BRANCH-SCOPED (it used to be global),
     * matching the branch-scoped rollback the service performs. Deleting also
     * un-claims the movements this count claimed, so a delivery is returned to
     * the pending pool instead of being stranded.
     */
    public function destroyCount(StockCount $stockCount): RedirectResponse
    {
        if (! $this->inventory->isLatestCount($stockCount)) {
            return back()->with('error', 'Only the most recent count can be deleted.');
        }

        $this->inventory->deleteCount($stockCount);

        return back()->with('success', 'Stock count deleted.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:40'],
            'unit' => ['required', 'in:g,kg,ml,l,pcs'],
            'current_stock' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
        ]);

        Ingredient::create($validated + ['cost_per_unit' => 0, 'is_active' => true]);

        return back()->with('success', 'Ingredient added.');
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:40'],
            'unit' => ['required', 'in:g,kg,ml,l,pcs'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $ingredient->update($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Ingredient updated.');
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        $ingredient->delete();

        return back()->with('success', 'Ingredient deleted.');
    }

    /**
     * The query string gives us string|null; the service is strictly typed and
     * treats null as "every branch".
     */
    private function branchId(mixed $raw): ?int
    {
        return (is_string($raw) || is_int($raw)) && is_numeric($raw) ? (int) $raw : null;
    }
}
