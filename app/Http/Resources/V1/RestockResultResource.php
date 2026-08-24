<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\InventoryMovement;
use App\Support\Inventory\IngredientView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of a quick restock: the movement that was logged plus the
 * ingredient with current_stock already bumped and pending_restock already
 * including this movement, so the client can swap the row in without refetching.
 */
class RestockResultResource extends JsonResource
{
    public function __construct(InventoryMovement $movement, private readonly IngredientView $view)
    {
        parent::__construct($movement);
    }

    public function toArray(Request $request): array
    {
        return [
            'movement' => (new InventoryMovementResource($this->resource))->toArray($request),
            'ingredient' => (new IngredientResource($this->view))->toArray($request),
        ];
    }
}
