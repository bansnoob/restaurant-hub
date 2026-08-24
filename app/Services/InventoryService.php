<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryMovement;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\User;
use App\Services\Inventory\CategoryReader;
use App\Services\Inventory\CountHistoryReader;
use App\Services\Inventory\InventorySnapshotReader;
use App\Services\Inventory\MovementLedger;
use App\Support\Inventory\IngredientStats;
use App\Support\Inventory\IngredientView;
use App\Support\Inventory\InventorySummary;
use App\Support\Inventory\StockCountSession;
use App\Support\Inventory\StockCountSessionRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The single source of truth for inventory arithmetic, shared by the legacy
 * web controller (App\Http\Controllers\InventoryController) and the mobile API
 * controllers under App\Http\Controllers\Api\V1.
 *
 * The restock CLAIM mechanism — how a delivery logged between two counts stays
 * out of the consumption figure — lives in App\Services\Inventory\MovementLedger;
 * read its class doc before touching inventory_movements.
 *
 * INVARIANT maintained by every write path here:
 *     ingredients.current_stock == <last counted quantity> + <unclaimed signed sum>
 */
final class InventoryService
{
    /** @var list<string> */
    public const UNITS = ['g', 'kg', 'ml', 'l', 'pcs'];

    /** decimal(14,3) ceiling for every quantity column. */
    public const MAX_QUANTITY = 99999999999.999;

    /** decimal(12,4) ceiling for unit costs. */
    public const MAX_UNIT_COST = 99999999.9999;

    public const QUANTITY_SCALE = 3;

    public const COST_SCALE = 4;

    public const CLAIM_REFERENCE_TYPE = MovementLedger::CLAIM_REFERENCE_TYPE;

    public const DIRECTION_IN = MovementLedger::DIRECTION_IN;

    public const DIRECTION_OUT = MovementLedger::DIRECTION_OUT;

    public const TYPE_PURCHASE = MovementLedger::TYPE_PURCHASE;

    public const TYPE_ADJUSTMENT = MovementLedger::TYPE_ADJUSTMENT;

    public const RECENT_COUNTS_LIMIT = 10;

    public const INGREDIENT_HISTORY_LIMIT = 15;

    public const MAX_COUNT_ENTRIES = 500;

    /** Smallest quantity the schema can represent; deltas below this are noise. */
    private const QUANTITY_EPSILON = 0.001;

    public function __construct(
        private readonly MovementLedger $ledger = new MovementLedger,
        private readonly CountHistoryReader $history = new CountHistoryReader,
        private readonly CategoryReader $categories = new CategoryReader,
        private readonly InventorySnapshotReader $snapshot = new InventorySnapshotReader,
    ) {}

    // =====================================================================
    // Reads
    // =====================================================================

    /**
     * @param  array{search?: string, unit?: string, category_id?: int|'none', low_only?: bool, include_inactive?: bool}  $filters
     *                                                                                                                              `include_inactive` is TRI-STATE on purpose: an absent key means
     *                                                                                                                              "do not filter on is_active at all" (the web index lists active and
     *                                                                                                                              inactive together). The API always passes the key explicitly.
     * @return Collection<int, IngredientView>
     */
    public function listIngredients(?int $branchId, array $filters = []): Collection
    {
        $query = Ingredient::with(['branch', 'category']);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $unit = (string) ($filters['unit'] ?? '');
        if (in_array($unit, self::UNITS, true)) {
            $query->where('unit', $unit);
        }

        // Tri-state, like include_inactive: an ABSENT key means "do not filter
        // on category at all", 'none' means the uncategorised tail only, and an
        // int means that one category.
        $categoryId = $filters['category_id'] ?? null;
        if ($categoryId === 'none') {
            $query->whereNull('ingredient_category_id');
        } elseif (is_int($categoryId)) {
            $query->where('ingredient_category_id', $categoryId);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->where('name', 'like', $needle)->orWhere('sku', 'like', $needle);
            });
        }

        if (array_key_exists('include_inactive', $filters) && ! $filters['include_inactive']) {
            $query->where('is_active', true);
        }

        // low_only below is a Collection filter, so it PRESERVES this order.
        $ingredients = $query->orderedForWalk()->get();

        if (! empty($filters['low_only'])) {
            $ingredients = $ingredients->filter(fn (Ingredient $i): bool => $i->isLowStock())->values();
        }

        return $this->decorate($ingredients, $branchId);
    }

    public function viewIngredient(Ingredient $ingredient): IngredientView
    {
        return $this->decorate(collect([$ingredient]), $ingredient->branch_id)->first();
    }

    /**
     * The ingredient's most recent stock count entries, OLDEST-FIRST.
     *
     * @return Collection<int, StockCountEntry>
     */
    public function ingredientHistory(Ingredient $ingredient, int $limit = self::INGREDIENT_HISTORY_LIMIT): Collection
    {
        return $this->history->ingredientHistory((int) $ingredient->id, $limit);
    }

    /**
     * Daily consumption rate + last count date per ingredient, keyed by
     * ingredient_id. `daily_rate` is null until the ingredient has two counts.
     *
     * @param  array<int, int>  $ingredientIds  empty = every ingredient in scope
     * @return array<int, array{daily_rate: float|null, last_counted_at: string|null}>
     */
    public function consumptionRates(?int $branchId = null, array $ingredientIds = []): array
    {
        return $this->history->consumptionRates($branchId, $ingredientIds);
    }

    /**
     * The most recent counted_quantity per ingredient (the snapshot a new count
     * measures against).
     *
     * @param  array<int, int>  $ingredientIds
     * @return array<int, array{previous: float, last_counted_at: string, stock_count_id: int}>
     */
    public function previousQuantities(array $ingredientIds): array
    {
        return array_map(
            fn (array $row): array => ['previous' => $this->quantity($row['previous'])] + $row,
            $this->history->previousQuantities($ingredientIds),
        );
    }

    /**
     * Signed net of UNCLAIMED movements (in = +, out = -), keyed by
     * ingredient_id, rounded to the schema's quantity scale.
     *
     * @param  array<int, int>  $ingredientIds
     * @param  int|null  $maxMovementId  claim fence; null = no upper bound.
     * @param  bool  $lock  take row locks (only true inside recordCount's transaction).
     * @return array<int, float>
     */
    public function pendingRestocks(array $ingredientIds, ?int $maxMovementId = null, bool $lock = false): array
    {
        return array_map(
            fn (float $v): float => $this->quantity($v),
            $this->ledger->pendingRestocks($ingredientIds, $maxMovementId, $lock),
        );
    }

    /**
     * MAX(id) over the unclaimed movements; 0 when nothing is pending.
     * $branchId null spans every branch (the web all-branches count session).
     */
    public function restockCursor(?int $branchId = null): int
    {
        return $this->ledger->restockCursor($branchId);
    }

    /** @return Collection<int, StockCount> */
    public function recentCounts(?int $branchId, int $limit = self::RECENT_COUNTS_LIMIT): Collection
    {
        return $this->snapshot->recentCounts($branchId, $limit);
    }

    public function summary(?int $branchId): InventorySummary
    {
        return $this->snapshot->summary($branchId);
    }

    /** True when the given count is its BRANCH's most recent (guards deleteCount). */
    public function isLatestCount(StockCount $stockCount): bool
    {
        return $this->snapshot->isLatestCount($stockCount);
    }

    /**
     * The branch's own categories plus the shared ones, in walk order.
     *
     * @return Collection<int, IngredientCategory>
     */
    public function listCategories(?int $branchId, bool $includeInactive = false): Collection
    {
        return $this->categories->listCategories($branchId, $includeInactive);
    }

    /**
     * The branch's OWN category ids (shared ones excluded).
     *
     * @return list<int>
     */
    public function ownCategoryIds(int $branchId): array
    {
        return $this->categories->ownCategoryIds($branchId);
    }

    /**
     * One saved count's entries, in the walk order it was taken in.
     *
     * @return Collection<int, StockCountEntry>
     */
    public function countEntries(StockCount $stockCount): Collection
    {
        return $this->history->countEntries((int) $stockCount->id);
    }

    // =====================================================================
    // Session building
    // =====================================================================

    /**
     * Open a count session. The cursor read, the ingredient read and the
     * pending sums share ONE transaction so a movement landing mid-build can
     * never make them disagree.
     *
     * $branchId null = every branch. That is the BARE-call contract, kept for
     * direct service callers only: BOTH HTTP surfaces always pass a branch —
     * the web count modal because a count may contain only its own branch's
     * ingredients (InventoryController::startCount now requires branch_id), and
     * the mobile API because ResolvesBranch::resolveBranchId() returns an int.
     */
    public function buildCountSession(?int $branchId): StockCountSession
    {
        return DB::transaction(function () use ($branchId): StockCountSession {
            // orderedForWalk, not orderBy('name'): `rows` IS the walk order —
            // category sort_order, then name, uncategorised last — so the count
            // matches the shelves. Consumers group runs and must never re-sort.
            $ingredients = Ingredient::with(['branch:id,name', 'category'])
                ->where('is_active', true)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->orderedForWalk()
                ->get();

            abort_if(
                $ingredients->count() > self::MAX_COUNT_ENTRIES,
                422,
                'A stock count is limited to '.self::MAX_COUNT_ENTRIES.' ingredients. Deactivate unused ingredients or count one branch at a time.'
            );

            $ids = $ingredients->pluck('id')->all();
            $cursor = $this->restockCursor($branchId);
            $previous = $this->previousQuantities($ids);
            // Fenced sum = what THIS count will claim. Unfenced sum = everything
            // already folded into current_stock; the baseline needs the latter.
            $claimed = $this->pendingRestocks($ids, $cursor);
            $unclaimed = $this->pendingRestocks($ids);

            $rows = $ingredients
                ->map(fn (Ingredient $i) => $this->buildSessionRow($i, $previous, $claimed, $unclaimed))
                ->values()
                ->all();

            return new StockCountSession(
                branchId: $branchId,
                branchName: $branchId === null ? null : Branch::find($branchId)?->name,
                today: now()->toDateString(),
                restockCursor: $cursor,
                rows: $rows,
            );
        });
    }

    // =====================================================================
    // Writes
    // =====================================================================

    /**
     * Quick restock: log a received delivery. The movement row and the
     * current_stock bump land in ONE transaction — this is the row that makes
     * the next stock count's consumption come out right.
     */
    public function recordRestock(
        Ingredient $ingredient,
        float $quantity,
        ?float $unitCost,
        ?string $notes,
        User $actor,
    ): InventoryMovement {
        return DB::transaction(function () use ($ingredient, $quantity, $unitCost, $notes, $actor): InventoryMovement {
            $locked = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();

            abort_if(
                (float) $locked->current_stock + $quantity > self::MAX_QUANTITY,
                422,
                'That quantity would exceed the maximum stock this ingredient can hold.'
            );

            $movement = $this->ledger->record(
                $locked,
                self::DIRECTION_IN,
                self::TYPE_PURCHASE,
                $this->quantity($quantity),
                $unitCost,
                $notes,
                $actor,
            );

            // Atomic UPDATE ... SET current_stock = current_stock + ?; no
            // read-modify-write race, and the caller's model stays untouched.
            Ingredient::whereKey($locked->id)->increment('current_stock', $this->quantity($quantity));

            return $movement;
        });
    }

    /**
     * Manual stock correction. Writes an 'adjustment' movement for the delta so
     * the next count still balances, then sets current_stock.
     *
     * Returns a FRESH Ingredient instance; the argument is never mutated.
     *
     * @param  float|null  $expectedCurrentStock  optimistic-concurrency guard: when supplied and
     *                                            the locked row disagrees, the write is refused
     *                                            with 409 rather than clobbering another device.
     */
    public function recordAdjustment(
        Ingredient $ingredient,
        float $newStock,
        ?string $notes,
        User $actor,
        ?float $expectedCurrentStock = null,
    ): Ingredient {
        return DB::transaction(function () use ($ingredient, $newStock, $notes, $actor, $expectedCurrentStock): Ingredient {
            $locked = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $current = (float) $locked->current_stock;

            abort_if(
                $expectedCurrentStock !== null && abs($expectedCurrentStock - $current) >= self::QUANTITY_EPSILON,
                409,
                'This ingredient\'s stock changed on another device. Refresh and try again.'
            );

            $delta = $this->quantity($newStock - $current);

            // A no-op edit must not pollute the ledger the claim mechanism reads.
            if (abs($delta) < self::QUANTITY_EPSILON) {
                return $locked;
            }

            $this->ledger->record(
                $locked,
                $delta > 0 ? self::DIRECTION_IN : self::DIRECTION_OUT,
                self::TYPE_ADJUSTMENT,
                abs($delta),
                null,
                $notes,
                $actor,
            );

            Ingredient::whereKey($locked->id)->update(['current_stock' => $this->quantity($newStock)]);

            return $locked->fresh();
        });
    }

    /**
     * Persist a whole count as one batch.
     *
     * @param  array<int, array{counted: float, declared_restock?: float|null}>  $entries
     *                                                                                     keyed by ingredient_id. `declared_restock` is the operator's manually
     *                                                                                     typed "Restocked" figure (the web count table has such a column); it is
     *                                                                                     an observation the movements ledger does not know about, so it is ADDED
     *                                                                                     to the claimed movement sum rather than replacing it. The mobile API
     *                                                                                     always passes null — its restocks arrive as movements.
     * @param  int|null  $restockCursor  null = claim every unclaimed movement (WEB sentinel);
     *                                   an int claims only id <= cursor, clamped server-side.
     */
    public function recordCount(
        int $branchId,
        string $countedAt,
        array $entries,
        ?int $restockCursor,
        ?string $notes,
        User $actor,
    ): StockCount {
        return DB::transaction(function () use ($branchId, $countedAt, $entries, $restockCursor, $notes, $actor): StockCount {
            // Branch-level gate: serialises concurrent submits for this branch so
            // two tablets cannot both read the same "previous" snapshot.
            Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();

            $ids = array_map('intval', array_keys($entries));
            abort_if($ids === [], 422, 'A stock count needs at least one entry.');
            abort_if(count($ids) > self::MAX_COUNT_ENTRIES, 422, 'A stock count is limited to '.self::MAX_COUNT_ENTRIES.' ingredients.');

            $this->guardCountOrdering($branchId, $countedAt);

            // Lock the rows we are about to rewrite so previousQuantities /
            // pendingRestocks / the current_stock write all see current data.
            // BRANCH-SCOPED: see lockCountIngredients().
            $ingredients = $this->lockCountIngredients($branchId, $ids);

            $cursor = $restockCursor === null
                ? null
                : min($restockCursor, $this->restockCursor($branchId));

            $previous = $this->previousQuantities($ids);
            // Lock the whole unclaimed set (a superset of the fenced set), then
            // derive the fenced sum from that same locked snapshot.
            $pendingAll = $this->pendingRestocks($ids, null, lock: true);
            $pendingClaimed = $this->pendingRestocks($ids, $cursor);

            $stockCount = StockCount::create([
                'branch_id' => $branchId,
                'counted_at' => $countedAt,
                'recorded_by_user_id' => $actor->id,
                'notes' => $notes,
                'total_value' => 0,
            ]);

            $counted = $this->insertCountEntries(
                $stockCount,
                $entries,
                $ingredients,
                $previous,
                $pendingClaimed,
                $pendingAll,
            );

            $this->ledger->claim(array_keys($counted), $stockCount->id, $cursor);
            $this->settleStockAfterCount($counted);

            return $stockCount;
        });
    }

    /**
     * Reverts current_stock to the prior count (or the entry's own
     * previous_quantity), un-claims the movements this count claimed so the
     * deliveries return to the pending pool, then deletes the count.
     *
     * Deliberately NOT branch-guarded: it only unwinds rows recordCount wrote,
     * and recordCount can no longer write an entry outside the count's branch.
     * Aborting here on a legacy cross-branch entry would strand such a count as
     * undeletable — leaving the corruption in place instead of letting the
     * owner roll it back.
     */
    public function deleteCount(StockCount $stockCount): void
    {
        DB::transaction(function () use ($stockCount): void {
            Branch::whereKey($stockCount->branch_id)->lockForUpdate()->first();

            $previousCount = $this->priorCountQuery($stockCount)->first();
            $previousEntries = $previousCount
                ? StockCountEntry::where('stock_count_id', $previousCount->id)->get()->keyBy('ingredient_id')
                : collect();

            $currentEntries = StockCountEntry::where('stock_count_id', $stockCount->id)->get();
            $ids = $currentEntries->pluck('ingredient_id')->map('intval')->all();

            $this->ledger->unclaim($stockCount->id);

            // Re-read AFTER the un-claim so the reverted stock still satisfies
            // `current_stock == lastCounted + unclaimed sum`.
            $pending = $this->pendingRestocks($ids);

            foreach ($currentEntries as $entry) {
                $ingredientId = (int) $entry->ingredient_id;
                $revert = $previousEntries->get($ingredientId)?->counted_quantity ?? $entry->previous_quantity;

                Ingredient::whereKey($ingredientId)->update([
                    'current_stock' => $this->quantity((float) $revert + ($pending[$ingredientId] ?? 0.0)),
                ]);
            }

            $stockCount->delete();
        });
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * Lock the counted rows, scoped to the count's OWN branch.
     *
     * A submitted ingredient that belongs to another branch is REJECTED, never
     * skipped. Skipping would let the caller believe the row was counted when
     * it was dropped; accepting it (the behaviour before this guard) wrote a
     * branch-B entry under a branch-A count, so MovementLedger::claim() stamped
     * B's unclaimed deliveries as claimed by A, settleStockAfterCount()
     * overwrote B's current_stock, and deleteCount()'s branch-scoped rollback
     * then reverted B to a branch-A baseline — silently, with no error shown.
     *
     * Both surfaces converge here (web InventoryController::storeCount and
     * Api\V1\StockCountController::store), so neither can bypass the guard.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Ingredient>
     */
    private function lockCountIngredients(int $branchId, array $ids): Collection
    {
        $ingredients = Ingredient::where('branch_id', $branchId)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Also catches an id that does not exist at all — validated at the
        // boundary, but an ingredient can be hard-deleted between that
        // allow-list query and this locking read.
        $foreign = array_values(array_diff($ids, $ingredients->keys()->map('intval')->all()));

        if ($foreign !== []) {
            // A ValidationException, not abort(422): the web module posts a real
            // browser form, and only a ValidationException redirects back with
            // $errors for the page's toast to render. JSON callers still get 422.
            throw ValidationException::withMessages([
                'entries' => 'These ingredients are no longer part of the branch being counted: '
                    .implode(', ', $foreign)
                    .'. Reopen the count for the right branch and try again.',
            ]);
        }

        return $ingredients;
    }

    /**
     * @param  Collection<int, Ingredient>  $ingredients
     * @return Collection<int, IngredientView>
     */
    private function decorate(Collection $ingredients, ?int $branchId): Collection
    {
        $ids = $ingredients->pluck('id')->map('intval')->all();
        // Scoped to $ids: viewIngredient() decorates ONE ingredient and runs on
        // every write path, so an unfiltered full-history scan would be paid per
        // restock and per ingredient edit.
        $rates = $this->consumptionRates($branchId, $ids);
        $pending = $this->pendingRestocks($ids);

        return $ingredients->map(function (Ingredient $i) use ($rates, $pending): IngredientView {
            $rate = $rates[$i->id]['daily_rate'] ?? null;
            $stock = (float) $i->current_stock;

            return new IngredientView($i, new IngredientStats(
                dailyConsumption: $rate,
                daysRemaining: ($rate !== null && $rate > 0 && $stock > 0)
                    ? round($stock / $rate, 1)
                    : null,
                lastCountedAt: $rates[$i->id]['last_counted_at'] ?? null,
                pendingRestock: $pending[$i->id] ?? 0.0,
            ));
        })->values();
    }

    /**
     * @param  array<int, array{previous: float, last_counted_at: string, stock_count_id: int}>  $previous
     * @param  array<int, float>  $claimed  signed sum of the movements this count will claim
     * @param  array<int, float>  $unclaimed  signed sum of EVERY unclaimed movement
     */
    private function buildSessionRow(Ingredient $i, array $previous, array $claimed, array $unclaimed): StockCountSessionRow
    {
        $restocked = $claimed[$i->id] ?? 0.0;
        $previousQuantity = $this->previousQuantityFor($i, $previous, $unclaimed[$i->id] ?? 0.0);
        $expected = $this->quantity($previousQuantity + $restocked);

        return new StockCountSessionRow(
            ingredientId: (int) $i->id,
            name: (string) $i->name,
            sku: $i->sku,
            unit: (string) $i->unit,
            branchId: (int) $i->branch_id,
            branchName: $i->branch?->name,
            reorderLevel: $this->quantity((float) $i->reorder_level),
            previousQuantity: $previousQuantity,
            restockedQuantity: $restocked,
            expectedQuantity: $expected,
            countedQuantity: $expected,
            ingredientCategoryId: $i->ingredient_category_id === null ? null : (int) $i->ingredient_category_id,
            categoryName: $i->category?->name,
            categorySortOrder: $i->category?->sort_order === null ? null : (int) $i->category->sort_order,
        );
    }

    /**
     * `previous` must be a snapshot taken BEFORE the pending movements, so that
     * `previous + restocked` equals the ingredient's book stock right now.
     *
     * With a prior count that is the last counted quantity. Without one, the
     * only baseline is current_stock — which already INCLUDES the pending
     * movements — so they must be subtracted back out.
     *
     * IMPORTANT: the sum subtracted is the UNFENCED one. current_stock contains
     * every unclaimed movement, including any that landed above this session's
     * cursor; subtracting only the claimed part would inflate the baseline and
     * re-read the mid-session delivery as consumption.
     *
     * @param  array<int, array{previous: float, last_counted_at: string, stock_count_id: int}>  $previous
     */
    private function previousQuantityFor(Ingredient $i, array $previous, float $unclaimedTotal): float
    {
        if (isset($previous[$i->id])) {
            return $previous[$i->id]['previous'];
        }

        $derived = $this->quantity((float) $i->current_stock - $unclaimedTotal);

        if ($derived < 0) {
            // Not clamped: a negative here means current_stock drifted below the
            // unclaimed sum, which is a real data problem worth seeing.
            Log::warning('Inventory baseline is negative for an uncounted ingredient.', [
                'ingredient_id' => $i->id,
                'current_stock' => (float) $i->current_stock,
                'pending_restock' => $unclaimedTotal,
            ]);
        }

        return $derived;
    }

    /**
     * Persist every entry in one bulk insert (a 500-row loop of ::create()
     * would hold the movement row locks far longer than necessary).
     *
     * @param  array<int, array{counted: float, declared_restock?: float|null}>  $entries
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  array<int, array{previous: float, last_counted_at: string, stock_count_id: int}>  $previous
     * @param  array<int, float>  $pendingClaimed  signed sum of the movements this count claims
     * @param  array<int, float>  $pendingAll  signed sum of EVERY unclaimed movement
     * @return array<int, float> counted quantity keyed by ingredient_id, for the rows actually written
     */
    private function insertCountEntries(
        StockCount $stockCount,
        array $entries,
        Collection $ingredients,
        array $previous,
        array $pendingClaimed,
        array $pendingAll,
    ): array {
        $now = now();
        $rows = [];
        $counted = [];

        foreach ($entries as $ingredientId => $entry) {
            $ingredient = $ingredients->get((int) $ingredientId);
            // lockCountIngredients() rejects the whole count unless every id is
            // present, so this cannot happen. It throws rather than `continue`s
            // because silently dropping a row the operator counted — while still
            // reporting the count as saved — is the exact failure that guard
            // exists to prevent.
            if ($ingredient === null) {
                throw new LogicException(
                    'insertCountEntries received an ingredient the branch guard should have rejected: '.$ingredientId
                );
            }

            $id = (int) $ingredient->id;
            $countedQuantity = $this->quantity((float) $entry['counted']);
            $restocked = $this->quantity(($pendingClaimed[$id] ?? 0.0) + (float) ($entry['declared_restock'] ?? 0.0));
            // Baseline subtracts the UNFENCED sum — see previousQuantityFor().
            $previousQuantity = isset($previous[$id])
                ? $previous[$id]['previous']
                : $this->quantity((float) $ingredient->current_stock - ($pendingAll[$id] ?? 0.0));

            $rows[] = [
                'stock_count_id' => $stockCount->id,
                'ingredient_id' => $id,
                'previous_quantity' => $previousQuantity,
                'restocked_quantity' => $restocked,
                'counted_quantity' => $countedQuantity,
                'consumption' => max(0.0, $this->quantity($previousQuantity + $restocked - $countedQuantity)),
                'unit_cost' => 0,
                'line_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $counted[$id] = $countedQuantity;
        }

        if ($rows !== []) {
            StockCountEntry::insert($rows);
        }

        return $counted;
    }

    /**
     * Restore `current_stock == lastCounted + unclaimed sum`.
     *
     * A delivery logged mid-session sits ABOVE the cursor, stays unclaimed, and
     * is carried into the next count — so it must be added back on top of the
     * counted quantity here, otherwise the column silently under-reports it.
     *
     * @param  array<int, float>  $counted  counted quantity keyed by ingredient_id
     */
    private function settleStockAfterCount(array $counted): void
    {
        if ($counted === []) {
            return;
        }

        // Re-read post-claim: this now returns exactly the id > cursor rows.
        $stillPending = $this->pendingRestocks(array_keys($counted), null, lock: true);

        foreach ($counted as $ingredientId => $quantity) {
            Ingredient::whereKey($ingredientId)->update([
                'current_stock' => $this->quantity($quantity + ($stillPending[$ingredientId] ?? 0.0)),
            ]);
        }
    }

    /**
     * Movement claims are ordered by AUTO_INCREMENT id while previous_quantity
     * is ordered by the backdatable counted_at DATE. Mixing the two orderings
     * lets a backdated count claim movements the NEXT count then reads as
     * consumption, unrecoverably. Keep the two orderings in agreement.
     */
    private function guardCountOrdering(int $branchId, string $countedAt): void
    {
        $latest = StockCount::where('branch_id', $branchId)
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return;
        }

        abort_if(
            Carbon::parse($countedAt)->startOfDay()->lt(Carbon::parse($latest->counted_at)->startOfDay()),
            422,
            'A later stock count already exists for this branch. Delete it first to record an earlier one.'
        );
    }

    /**
     * The count immediately preceding this one, within the SAME branch, using a
     * total (counted_at, id) ordering so a second count on the same date is not
     * skipped by a strict `counted_at <` comparison.
     */
    private function priorCountQuery(StockCount $stockCount): Builder
    {
        return StockCount::where('branch_id', $stockCount->branch_id)
            ->where(function (Builder $q) use ($stockCount): void {
                $q->where('counted_at', '<', $stockCount->counted_at)
                    ->orWhere(function (Builder $inner) use ($stockCount): void {
                        $inner->where('counted_at', '=', $stockCount->counted_at)
                            ->where('id', '<', $stockCount->id);
                    });
            })
            ->orderByDesc('counted_at')
            ->orderByDesc('id');
    }

    /** Round to the schema's quantity scale so float noise never reaches the wire. */
    private function quantity(float $value): float
    {
        return round($value, self::QUANTITY_SCALE);
    }
}
