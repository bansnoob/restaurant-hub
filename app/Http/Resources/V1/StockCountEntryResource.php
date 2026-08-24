<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One historical stock_count_entries row.
 *
 * `restocked_quantity` may be NEGATIVE: it is the signed net of the movements
 * the count claimed, so a downward manual adjustment keeps the
 * `previous + restocked == book stock` invariant intact.
 */
class StockCountEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scale = InventoryService::QUANTITY_SCALE;

        return [
            'id' => (int) $this->id,
            'stock_count_id' => (int) $this->stock_count_id,
            'counted_at' => $this->stockCount?->counted_at?->toDateString(),
            'previous_quantity' => round((float) $this->previous_quantity, $scale),
            'restocked_quantity' => round((float) $this->restocked_quantity, $scale),
            'counted_quantity' => round((float) $this->counted_quantity, $scale),
            'consumption' => round((float) $this->consumption, $scale),
        ];
    }
}
