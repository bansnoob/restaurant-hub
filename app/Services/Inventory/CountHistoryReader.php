<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockCountEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only derivations over stock_counts / stock_count_entries: the daily
 * consumption rate and the "previous quantity" snapshot a new count measures
 * against. Both are ordered by (counted_at DESC, id DESC), which is why
 * InventoryService refuses a count backdated behind its branch's latest one.
 */
final class CountHistoryReader
{
    /** Rates are rounded to 4dp, matching the cost scale the web UI displays. */
    private const RATE_SCALE = 4;

    /**
     * Daily consumption rate per ingredient, derived from its two most recent
     * entries (consumption / days between the two counts).
     *
     * `daily_rate` is NULL when the ingredient has fewer than two counts —
     * there is no window to divide by, so a rate would be a fabrication.
     *
     * $ingredientIds narrows the scan to the rows the caller is actually
     * decorating. Without it a single-ingredient write path (a quick restock,
     * an ingredient create/edit/deactivate) would hydrate the branch's ENTIRE
     * stock_count_entries history into PHP just to read two rows.
     *
     * @param  array<int, int>  $ingredientIds  empty = every ingredient in scope
     * @return array<int, array{daily_rate: float|null, last_counted_at: string|null}>
     */
    public function consumptionRates(?int $branchId = null, array $ingredientIds = []): array
    {
        $rows = DB::table('stock_count_entries as e')
            ->join('stock_counts as c', 'e.stock_count_id', '=', 'c.id')
            ->when($branchId !== null, function ($q) use ($branchId) {
                return $q->join('ingredients as i', 'e.ingredient_id', '=', 'i.id')
                    ->where('i.branch_id', $branchId);
            })
            ->when($ingredientIds !== [], fn ($q) => $q->whereIn('e.ingredient_id', $ingredientIds))
            ->select('e.ingredient_id', 'e.consumption', 'c.counted_at')
            ->orderBy('e.ingredient_id')
            ->orderByDesc('c.counted_at')
            ->orderByDesc('c.id')
            ->get();

        $byIngredient = [];
        foreach ($rows as $row) {
            $byIngredient[(int) $row->ingredient_id][] = $row;
        }

        $result = [];
        foreach ($byIngredient as $id => $entries) {
            $latest = $entries[0];
            $previous = $entries[1] ?? null;

            $result[$id] = [
                'daily_rate' => $previous === null ? null : $this->dailyRate($previous, $latest),
                'last_counted_at' => $this->toDateString($latest->counted_at),
            ];
        }

        return $result;
    }

    /**
     * The most recent counted_quantity per ingredient — the snapshot a new
     * count measures against.
     *
     * @param  array<int, int>  $ingredientIds
     * @return array<int, array{previous: float, last_counted_at: string, stock_count_id: int}>
     */
    public function previousQuantities(array $ingredientIds): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        $rows = DB::table('stock_count_entries as e')
            ->join('stock_counts as c', 'e.stock_count_id', '=', 'c.id')
            ->whereIn('e.ingredient_id', $ingredientIds)
            ->select('e.ingredient_id', 'e.counted_quantity', 'c.counted_at', 'c.id as stock_count_id')
            ->orderBy('e.ingredient_id')
            ->orderByDesc('c.counted_at')
            ->orderByDesc('c.id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->ingredient_id;
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = [
                'previous' => (float) $row->counted_quantity,
                'last_counted_at' => (string) $this->toDateString($row->counted_at),
                'stock_count_id' => (int) $row->stock_count_id,
            ];
        }

        return $result;
    }

    /**
     * The ingredient's most recent stock count entries, returned OLDEST-FIRST.
     *
     * Mirrors the legacy web query exactly: take the NEWEST $limit rows by id,
     * then reverse them for display.
     *
     * @return Collection<int, StockCountEntry>
     */
    public function ingredientHistory(int $ingredientId, int $limit): Collection
    {
        return StockCountEntry::with('stockCount:id,counted_at,branch_id')
            ->where('ingredient_id', $ingredientId)
            ->whereHas('stockCount')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * One count's entries in the same category walk order the count was taken
     * in, so reading a saved count back reproduces the physical walk.
     *
     * consumptionRates() and previousQuantities() above are deliberately NOT
     * reordered: their `orderBy('e.ingredient_id')` is a GROUPING requirement
     * for the PHP loops that follow, not a display order, and walking them by
     * category would silently break the "two most recent entries per
     * ingredient" derivation.
     *
     * Sorted in PHP rather than SQL: a count is capped at
     * InventoryService::MAX_COUNT_ENTRIES rows, and expressing "the
     * ingredient's category's sort_order" in SQL from stock_count_entries
     * needs a two-level correlated subquery that reads differently on MySQL
     * and SQLite. The comparator below is one place, portable, and testable.
     *
     * @return Collection<int, StockCountEntry>
     */
    public function countEntries(int $stockCountId): Collection
    {
        return StockCountEntry::with([
            'ingredient:id,name,sku,unit,ingredient_category_id',
            'ingredient.category:id,name,sort_order',
        ])
            ->where('stock_count_id', $stockCountId)
            ->get()
            ->sortBy(fn (StockCountEntry $entry): array => $this->walkSortKey($entry))
            ->values();
    }

    /**
     * Array sort key, compared element-wise by PHP's <=>. Element 0 is the
     * uncategorised flag, so an entry whose ingredient lost its category (or
     * whose ingredient row is missing entirely) sorts LAST instead of throwing
     * or disappearing.
     *
     * @return array{int, int, string, string, int}
     */
    private function walkSortKey(StockCountEntry $entry): array
    {
        $ingredient = $entry->ingredient;
        $category = $ingredient?->category;

        return [
            $category === null ? 1 : 0,
            (int) ($category->sort_order ?? 0),
            Str::lower((string) ($category->name ?? '')),
            Str::lower((string) ($ingredient->name ?? '')),
            (int) ($ingredient->id ?? 0),
        ];
    }

    private function dailyRate(object $previous, object $latest): float
    {
        $days = (int) Carbon::parse($previous->counted_at)->diffInDays(Carbon::parse($latest->counted_at));
        if ($days <= 0) {
            $days = 1;
        }

        return round(max(0.0, ((float) $latest->consumption) / $days), self::RATE_SCALE);
    }

    /**
     * counted_at is a DATE column, but the driver may hand it back with a time
     * component; normalise so callers always see Y-m-d.
     */
    private function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
