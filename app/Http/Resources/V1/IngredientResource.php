<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\InventoryService;
use App\Support\Inventory\IngredientView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps an IngredientView (model + derived stats).
 *
 * Every key is always present — nullable, never omitted — so the mobile cache
 * row mapping is total. Numerics are cast explicitly because Ingredient's
 * `decimal:` casts return STRINGS, and rounded to the schema's scale so float
 * artifacts (12.345 + 0.678 = 13.023000000000001) never reach the client.
 *
 * @property-read IngredientView $resource
 */
class IngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ingredient = $this->resource->ingredient;
        $stats = $this->resource->stats;

        return [
            'id' => (int) $ingredient->id,
            'branch_id' => (int) $ingredient->branch_id,
            'name' => $ingredient->name,
            'sku' => $ingredient->sku,
            'unit' => $ingredient->unit,
            'current_stock' => round((float) $ingredient->current_stock, InventoryService::QUANTITY_SCALE),
            'reorder_level' => round((float) $ingredient->reorder_level, InventoryService::QUANTITY_SCALE),
            'cost_per_unit' => round((float) $ingredient->cost_per_unit, InventoryService::COST_SCALE),
            'is_active' => (bool) $ingredient->is_active,
            'is_low_stock' => $ingredient->isLowStock(),
            'daily_consumption' => $stats->dailyConsumption,
            'days_remaining' => $stats->daysRemaining,
            'last_counted_at' => $stats->lastCountedAt,
            'pending_restock' => round($stats->pendingRestock, InventoryService::QUANTITY_SCALE),
        ];
    }
}
