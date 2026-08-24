<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The inventory_movements ledger and the restock CLAIM mechanism.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS — read before touching inventory_movements
 * ---------------------------------------------------------------------------
 * A stock count derives consumption as `previous + restocked - counted`. For
 * that to be right, stock that ARRIVED between two counts must be visible to
 * the later count, otherwise the delivery is silently read as consumption.
 *
 * Every quantity change that is not a count therefore writes a movement row (a
 * "restock" or an "adjustment"). A movement whose `reference_type` IS NULL is
 * UNCLAIMED — no stock count has folded it into a `restocked_quantity` yet.
 * claim() stamps `reference_type = CLAIM_REFERENCE_TYPE` and
 * `reference_id = <stock_count id>`; unclaim() reverses it for one count.
 *
 * The fence is `inventory_movements.id` (a single AUTO_INCREMENT sequence), NOT
 * a time window:
 *   - `moved_at > lastCount.counted_at` — counted_at is a backdatable DATE, so
 *     a restock at 14:00 and a count at 18:00 the same day land on the wrong
 *     side, and backdating a count re-sums weeks of restocks. Double-counts.
 *   - `moved_at > lastCount.created_at` — a business timestamp against a record
 *     timestamp: a delivery entered the next morning falls below the boundary
 *     and is dropped forever.
 *   - `movements.created_at > lastCount.created_at` — right instinct, but
 *     `timestamps()` on MySQL is whole-second precision, so a restock and a
 *     count in the same second tie, and neither `>` nor `>=` is safe.
 * An id fence cannot tie and cannot be backdated.
 *
 * CONSEQUENCE: `reference_type IS NULL` is the definition of "unclaimed".
 * Do NOT repurpose reference_type / reference_id on this table for another
 * feature without reworking every read here and in InventoryService.
 */
final class MovementLedger
{
    public const CLAIM_REFERENCE_TYPE = 'stock_count';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Signed net of UNCLAIMED movements (in = +, out = -), keyed by ingredient_id.
     *
     * @param  array<int, int>  $ingredientIds
     * @param  int|null  $maxMovementId  claim fence; null = no upper bound.
     * @param  bool  $lock  take row locks (only ever true inside recordCount's transaction).
     * @return array<int, float>
     */
    public function pendingRestocks(array $ingredientIds, ?int $maxMovementId = null, bool $lock = false): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        $query = DB::table('inventory_movements')
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereNull('reference_type')
            ->when($maxMovementId !== null, fn ($q) => $q->where('id', '<=', $maxMovementId))
            ->select('ingredient_id', 'direction', 'quantity');

        if ($lock) {
            $query->lockForUpdate();
        }

        $totals = [];
        foreach ($query->get() as $row) {
            $id = (int) $row->ingredient_id;
            $signed = $row->direction === self::DIRECTION_OUT
                ? -((float) $row->quantity)
                : (float) $row->quantity;
            $totals[$id] = ($totals[$id] ?? 0.0) + $signed;
        }

        return $totals;
    }

    /**
     * MAX(id) over the unclaimed movements; 0 when nothing is pending.
     * $branchId null spans every branch (the web all-branches count session).
     */
    public function restockCursor(?int $branchId = null): int
    {
        return (int) (DB::table('inventory_movements')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('reference_type')
            ->max('id') ?? 0);
    }

    /**
     * Stamp the unclaimed movements at or below the fence as belonging to this
     * count. $cursor null claims everything unclaimed (the web sentinel);
     * `id <= 0` matches no row, so 0 claims nothing.
     *
     * @param  array<int, int>  $ingredientIds
     */
    public function claim(array $ingredientIds, int $stockCountId, ?int $cursor): void
    {
        if ($ingredientIds === []) {
            return;
        }

        DB::table('inventory_movements')
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereNull('reference_type')
            ->when($cursor !== null, fn ($q) => $q->where('id', '<=', $cursor))
            ->update([
                'reference_type' => self::CLAIM_REFERENCE_TYPE,
                'reference_id' => $stockCountId,
                'updated_at' => now(),
            ]);
    }

    /** Return a deleted count's movements to the pending pool. */
    public function unclaim(int $stockCountId): void
    {
        DB::table('inventory_movements')
            ->where('reference_type', self::CLAIM_REFERENCE_TYPE)
            ->where('reference_id', $stockCountId)
            ->update(['reference_type' => null, 'reference_id' => null, 'updated_at' => now()]);
    }

    /**
     * Append one unclaimed movement. The caller owns the surrounding
     * transaction and the matching current_stock write.
     */
    public function record(
        Ingredient $ingredient,
        string $direction,
        string $movementType,
        float $quantity,
        ?float $unitCost,
        ?string $notes,
        User $actor,
    ): InventoryMovement {
        return InventoryMovement::create([
            'branch_id' => $ingredient->branch_id,
            'ingredient_id' => $ingredient->id,
            'direction' => $direction,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => $notes,
            'moved_at' => now(),
            'created_by_user_id' => $actor->id,
        ]);
    }
}
