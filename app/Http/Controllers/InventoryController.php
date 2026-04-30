<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InventoryController extends Controller
{
    private const UNITS = ['pcs', 'g', 'kg', 'ml', 'l'];

    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $branchFilter = $request->query('branch_id');
        $unitFilter = (string) $request->query('unit', '');
        $search = trim((string) $request->query('search', ''));
        $lowOnly = (bool) $request->query('low_only', false);

        $ingredientsQuery = Ingredient::with('branch');

        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $ingredientsQuery->where('branch_id', (int) $branchFilter);
        }
        if (in_array($unitFilter, self::UNITS, true)) {
            $ingredientsQuery->where('unit', $unitFilter);
        }
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $ingredientsQuery->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                  ->orWhere('sku', 'like', $needle);
            });
        }

        $ingredients = $ingredientsQuery->orderBy('name')->get();

        $consumptionByIngredient = $this->latestConsumptionRates();

        $ingredients = $ingredients->map(function (Ingredient $i) use ($consumptionByIngredient) {
            $stats = $consumptionByIngredient[$i->id] ?? null;
            $dailyRate = $stats['daily_rate'] ?? 0.0;
            $daysRemaining = ($dailyRate > 0 && $i->current_stock > 0)
                ? (float) $i->current_stock / $dailyRate
                : null;
            $i->daily_consumption = $dailyRate;
            $i->days_remaining = $daysRemaining;
            $i->last_counted_at = $stats['last_counted_at'] ?? null;
            return $i;
        });

        if ($lowOnly) {
            $ingredients = $ingredients->filter(fn ($i) => $i->isLowStock())->values();
        }

        $allIngredients = Ingredient::with('branch')->orderBy('name')->get();
        $lowStock = $allIngredients->filter(fn ($i) => $i->isLowStock());

        $latestCount = StockCount::orderByDesc('counted_at')->orderByDesc('id')->first();
        $daysSinceLastCount = $latestCount ? (int) Carbon::parse($latestCount->counted_at)->diffInDays(now()) : null;

        $stats = [
            'total_items' => $allIngredients->count(),
            'active_items' => $allIngredients->where('is_active', true)->count(),
            'low_stock_count' => $lowStock->count(),
            'days_since_last_count' => $daysSinceLastCount,
            'last_count_at' => $latestCount?->counted_at?->toDateString(),
            'counts_this_month' => StockCount::whereDate('counted_at', '>=', now()->startOfMonth()->toDateString())->count(),
        ];

        $recentCounts = StockCount::with(['branch:id,name', 'recordedBy:id,name'])
            ->withCount('entries')
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

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

        $entries = StockCountEntry::with('stockCount:id,counted_at,branch_id')
            ->where('ingredient_id', $ingredient->id)
            ->whereHas('stockCount')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $consumptionByIngredient = $this->latestConsumptionRates();
        $stats = $consumptionByIngredient[$ingredient->id] ?? null;
        $dailyRate = $stats['daily_rate'] ?? 0.0;
        $daysRemaining = ($dailyRate > 0 && (float) $ingredient->current_stock > 0)
            ? (float) $ingredient->current_stock / $dailyRate
            : null;

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
                'daily_consumption' => $dailyRate,
                'days_remaining' => $daysRemaining,
                'last_counted_at' => $stats['last_counted_at'] ?? null,
            ],
            'history' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'counted_at' => $e->stockCount?->counted_at?->toDateString(),
                'counted_at_label' => $e->stockCount?->counted_at?->format('M j, Y'),
                'previous_quantity' => (float) $e->previous_quantity,
                'restocked_quantity' => (float) $e->restocked_quantity,
                'counted_quantity' => (float) $e->counted_quantity,
                'consumption' => (float) $e->consumption,
            ])->reverse()->values(),
        ]);
    }

    public function startCount(Request $request): JsonResponse
    {
        $branchFilter = $request->query('branch_id');
        $branchesQuery = Branch::where('is_active', true)->orderBy('name');
        $branches = $branchesQuery->get();

        $ingredientsQuery = Ingredient::with('branch')->where('is_active', true);
        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $ingredientsQuery->where('branch_id', (int) $branchFilter);
        }
        $ingredients = $ingredientsQuery->orderBy('name')->get();

        $previousByIngredient = $this->latestPreviousQuantities($ingredients->pluck('id')->all());

        $rows = $ingredients->map(function (Ingredient $i) use ($previousByIngredient) {
            $previous = $previousByIngredient[$i->id]['previous'] ?? (float) $i->current_stock;
            return [
                'ingredient_id' => $i->id,
                'name' => $i->name,
                'sku' => $i->sku,
                'unit' => $i->unit,
                'branch_id' => $i->branch_id,
                'branch_name' => $i->branch?->name,
                'reorder_level' => (float) $i->reorder_level,
                'previous_quantity' => (float) $previous,
                'restocked_quantity' => 0.0,
                'counted_quantity' => (float) $previous,
            ];
        })->values();

        return response()->json([
            'today' => now()->toDateString(),
            'branches' => $branches,
            'ingredients' => $rows,
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

        DB::transaction(function () use ($validated, $request) {
            $stockCount = StockCount::create([
                'branch_id' => $validated['branch_id'],
                'counted_at' => $validated['counted_at'],
                'recorded_by_user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
                'total_value' => 0,
            ]);

            foreach ($validated['entries'] as $row) {
                $ingredient = Ingredient::find($row['ingredient_id']);
                if (! $ingredient) {
                    continue;
                }

                $previous = (float) $row['previous_quantity'];
                $restocked = (float) ($row['restocked_quantity'] ?? 0);
                $counted = (float) $row['counted_quantity'];
                $consumption = max(0.0, $previous + $restocked - $counted);

                StockCountEntry::create([
                    'stock_count_id' => $stockCount->id,
                    'ingredient_id' => $ingredient->id,
                    'previous_quantity' => $previous,
                    'restocked_quantity' => $restocked,
                    'counted_quantity' => $counted,
                    'consumption' => $consumption,
                    'unit_cost' => 0,
                    'line_value' => 0,
                ]);

                $ingredient->update(['current_stock' => $counted]);
            }
        });

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

    public function destroyCount(StockCount $stockCount): RedirectResponse
    {
        $latest = StockCount::orderByDesc('counted_at')->orderByDesc('id')->first();
        if (! $latest || $latest->id !== $stockCount->id) {
            return back()->with('error', 'Only the most recent count can be deleted.');
        }

        DB::transaction(function () use ($stockCount): void {
            $previousCount = StockCount::where('counted_at', '<', $stockCount->counted_at)
                ->orderByDesc('counted_at')
                ->orderByDesc('id')
                ->first();

            $previousEntries = $previousCount
                ? StockCountEntry::where('stock_count_id', $previousCount->id)->get()->keyBy('ingredient_id')
                : collect();

            $currentEntries = StockCountEntry::where('stock_count_id', $stockCount->id)->get();
            foreach ($currentEntries as $entry) {
                $ingredient = Ingredient::find($entry->ingredient_id);
                if (! $ingredient) {
                    continue;
                }
                $previousQuantity = $previousEntries->get($ingredient->id)?->counted_quantity;
                if ($previousQuantity !== null) {
                    $ingredient->update(['current_stock' => $previousQuantity]);
                } else {
                    $ingredient->update(['current_stock' => $entry->previous_quantity]);
                }
            }

            $stockCount->delete();
        });

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
     * For each ingredient, compute the daily consumption rate from its
     * most recent two stock count entries (consumption / days between counts).
     *
     * @return array<int, array{daily_rate: float, last_counted_at: ?string}>
     */
    private function latestConsumptionRates(): array
    {
        $rows = DB::table('stock_count_entries as e')
            ->join('stock_counts as c', 'e.stock_count_id', '=', 'c.id')
            ->select('e.ingredient_id', 'e.consumption', 'c.counted_at')
            ->orderBy('e.ingredient_id')
            ->orderByDesc('c.counted_at')
            ->orderByDesc('c.id')
            ->get();

        $byIngredient = [];
        foreach ($rows as $row) {
            $id = (int) $row->ingredient_id;
            if (! isset($byIngredient[$id])) {
                $byIngredient[$id] = [];
            }
            $byIngredient[$id][] = $row;
        }

        $result = [];
        foreach ($byIngredient as $id => $entries) {
            $latest = $entries[0] ?? null;
            $previous = $entries[1] ?? null;
            if (! $latest) {
                continue;
            }

            $days = null;
            if ($previous) {
                $days = (int) Carbon::parse($previous->counted_at)->diffInDays(Carbon::parse($latest->counted_at));
                if ($days <= 0) {
                    $days = 1;
                }
            }

            $dailyRate = $days ? max(0.0, ((float) $latest->consumption) / $days) : 0.0;

            $result[$id] = [
                'daily_rate' => round($dailyRate, 4),
                'last_counted_at' => (string) $latest->counted_at,
            ];
        }

        return $result;
    }

    /**
     * Returns the most recent counted_quantity per ingredient (used to pre-fill new counts).
     *
     * @param array<int, int> $ingredientIds
     * @return array<int, array{previous: float, last_counted_at: string}>
     */
    private function latestPreviousQuantities(array $ingredientIds): array
    {
        if (empty($ingredientIds)) {
            return [];
        }

        $rows = DB::table('stock_count_entries as e')
            ->join('stock_counts as c', 'e.stock_count_id', '=', 'c.id')
            ->whereIn('e.ingredient_id', $ingredientIds)
            ->select('e.ingredient_id', 'e.counted_quantity', 'c.counted_at')
            ->orderBy('e.ingredient_id')
            ->orderByDesc('c.counted_at')
            ->orderByDesc('c.id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->ingredient_id;
            if (! isset($result[$id])) {
                $result[$id] = [
                    'previous' => (float) $row->counted_quantity,
                    'last_counted_at' => (string) $row->counted_at,
                ];
            }
        }

        return $result;
    }

}
