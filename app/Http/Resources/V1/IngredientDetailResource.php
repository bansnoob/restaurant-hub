<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\StockCountEntry;
use App\Support\Inventory\IngredientView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Ingredient detail: the full IngredientResource object plus its recent count
 * history (oldest-first, capped by InventoryService::INGREDIENT_HISTORY_LIMIT).
 */
class IngredientDetailResource extends JsonResource
{
    /** @param Collection<int, StockCountEntry> $history */
    public function __construct(IngredientView $view, private readonly Collection $history)
    {
        parent::__construct($view);
    }

    public function toArray(Request $request): array
    {
        return [
            'ingredient' => (new IngredientResource($this->resource))->toArray($request),
            'history' => StockCountEntryResource::collection($this->history)->toArray($request),
        ];
    }
}
