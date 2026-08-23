<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Support\Inventory\InventorySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read InventorySummary $resource
 */
class InventorySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'branch_id' => $this->resource->branchId,
            'branch_name' => $this->resource->branchName,
            'total_items' => $this->resource->totalItems,
            'active_items' => $this->resource->activeItems,
            'low_stock_count' => $this->resource->lowStockCount,
            'last_count_at' => $this->resource->lastCountAt,
            'days_since_last_count' => $this->resource->daysSinceLastCount,
            'counts_this_month' => $this->resource->countsThisMonth,
        ];
    }
}
