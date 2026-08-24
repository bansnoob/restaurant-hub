<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stock count header. Expects the model to carry `entries_count` and
 * `entries_sum_consumption` (InventoryService::recentCounts adds both); a
 * freshly created count is re-loaded with the same aggregates before wrapping.
 */
class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'branch_id' => (int) $this->branch_id,
            'counted_at' => $this->counted_at?->toDateString(),
            'recorded_by' => $this->recordedBy?->name,
            'notes' => $this->notes,
            'entry_count' => (int) ($this->entries_count ?? 0),
            'total_consumption' => round(
                (float) ($this->entries_sum_consumption ?? 0),
                InventoryService::QUANTITY_SCALE
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
