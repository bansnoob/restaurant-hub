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
            'discount_total' => (float) $this->discount_total,
            'grand_total' => (float) $this->grand_total,
            'paid_total' => (float) $this->paid_total,
            'change_total' => (float) $this->change_total,
            'payment_method' => $this->payment_method,
            'cash_amount' => $this->cash_amount !== null ? (float) $this->cash_amount : null,
            'gcash_amount' => $this->gcash_amount !== null ? (float) $this->gcash_amount : null,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'items' => SaleItemResource::collection($this->whenLoaded('saleItems')),
            'cashier' => new UserResource($this->whenLoaded('cashier')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
