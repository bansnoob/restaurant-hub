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
            'base_price' => (float) $this->base_price,
            'is_active' => $this->is_active,
            'category' => new MenuCategoryResource($this->whenLoaded('category')),
        ];
    }
}
