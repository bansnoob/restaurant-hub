<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'item_type' => $this->item_type,
            'base_price' => (float) $this->base_price,
            'tax_rate' => (float) $this->tax_rate,
            'is_active' => $this->is_active,
            'category' => new MenuCategoryResource($this->whenLoaded('category')),
        ];
    }
}
