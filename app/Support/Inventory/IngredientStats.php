<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * Derived (non-column) numbers that decorate an Ingredient for display.
 *
 * Immutable by construction: every read path builds a fresh instance rather
 * than mutating an existing one.
 */
final readonly class IngredientStats
{
    /**
     * @param  float|null  $dailyConsumption  null until the ingredient has TWO stock counts
     *                                        to derive a rate from (one count gives no window).
     * @param  float|null  $daysRemaining  current_stock / dailyConsumption, rounded to 1dp.
     * @param  string|null  $lastCountedAt  Y-m-d of the most recent count including this ingredient.
     * @param  float  $pendingRestock  SIGNED net of unclaimed inventory_movements
     *                                 (in = +, out = -). Negative after a downward adjustment.
     */
    public function __construct(
        public ?float $dailyConsumption,
        public ?float $daysRemaining,
        public ?string $lastCountedAt,
        public float $pendingRestock,
    ) {}
}
