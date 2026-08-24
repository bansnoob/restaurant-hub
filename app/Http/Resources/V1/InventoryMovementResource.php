<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'branch_id' => (int) $this->branch_id,
            'ingredient_id' => (int) $this->ingredient_id,
            'direction' => $this->direction,
            'movement_type' => $this->movement_type,
            'quantity' => round((float) $this->quantity, InventoryService::QUANTITY_SCALE),
            'unit_cost' => $this->unit_cost === null
                ? null
                : round((float) $this->unit_cost, InventoryService::COST_SCALE),
            'notes' => $this->notes,
            'moved_at' => $this->moved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
