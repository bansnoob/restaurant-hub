<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'sale_datetime' => $this->sale_datetime?->toIso8601String(),
            'order_type' => $this->order_type,
            'status' => $this->status,
            'table_label' => $this->table_label,
            'sub_total' => (float) $this->sub_total,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'grand_total' => (float) $this->grand_total,
            'paid_total' => (float) $this->paid_total,
            'change_total' => (float) $this->change_total,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'items' => SaleItemResource::collection($this->whenLoaded('saleItems')),
            'cashier' => new UserResource($this->whenLoaded('cashier')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
