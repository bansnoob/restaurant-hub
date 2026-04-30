<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => (int) $this->branch_id,
            'expense_category_id' => $this->expense_category_id ? (int) $this->expense_category_id : null,
            'expense_date' => $this->expense_date instanceof \DateTimeInterface
                ? $this->expense_date->format('Y-m-d')
                : $this->expense_date,
            'reference_no' => $this->reference_no,
            'vendor_name' => $this->vendor_name,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'notes' => $this->notes,
            'category' => new ExpenseCategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
