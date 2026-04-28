<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'menu_item_id' => $this->menu_item_id,
            'item_name' => $this->item_name,
            'unit_price' => (float) $this->unit_price,
            'quantity' => (float) $this->quantity,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'line_total' => (float) $this->line_total,
            'notes' => $this->notes,
        ];
    }
}
