<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;

/**
 * An Ingredient paired with its derived stats. This is what InventoryService
 * hands back to both the API resources and the legacy blade.
 */
final readonly class IngredientView
{
    public function __construct(
        public Ingredient $ingredient,
        public IngredientStats $stats,
    ) {}

    /**
     * Returns a CLONE of the model carrying the stats as ad-hoc attributes, for
     * the legacy blade (resources/views/modules/inventory/index.blade.php) only.
     *
     * The original model is never touched (immutability rule). The clone is
     * deliberately dirty with NON-COLUMN attributes, so calling save() on it
     * would throw "Unknown column" — the blade must stay strictly read-only.
     */
    public function toDecoratedModel(): Ingredient
    {
        $clone = clone $this->ingredient;

        // The blade previously received 0.0 (never null) for a rate-less
        // ingredient; preserve that exactly. The API resource emits null.
        $clone->daily_consumption = $this->stats->dailyConsumption ?? 0.0;
        $clone->days_remaining = $this->stats->daysRemaining;
        $clone->last_counted_at = $this->stats->lastCountedAt;

        return $clone;
    }
}
