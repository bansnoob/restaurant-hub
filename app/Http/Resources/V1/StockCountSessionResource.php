<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\InventoryService;
use App\Support\Inventory\StockCountSession;
use App\Support\Inventory\StockCountSessionRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open count session.
 *
 * `restock_cursor` is the fence for the movement claim — echo it back verbatim
 * on submit so a delivery logged mid-session is carried into the NEXT count
 * instead of being claimed by this one. 0 means nothing was pending; never null.
 *
 * @property-read StockCountSession $resource
 */
class StockCountSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scale = InventoryService::QUANTITY_SCALE;

        return [
            'branch_id' => $this->resource->branchId,
            'branch_name' => $this->resource->branchName,
            'today' => $this->resource->today,
            'restock_cursor' => $this->resource->restockCursor,
            'rows' => array_map(fn (StockCountSessionRow $row): array => [
                'ingredient_id' => $row->ingredientId,
                'name' => $row->name,
                'sku' => $row->sku,
                'unit' => $row->unit,
                // Flat scalars, not a nested object: a session is a 500-row
                // payload of pure values and the client only needs a group key,
                // a title and a sort key. Null = uncategorised, which sorts LAST
                // in `rows` and is still counted.
                'ingredient_category_id' => $row->ingredientCategoryId,
                'category_name' => $row->categoryName,
                'category_sort_order' => $row->categorySortOrder,
                'branch_id' => $row->branchId,
                'branch_name' => $row->branchName,
                'reorder_level' => round($row->reorderLevel, $scale),
                'previous_quantity' => round($row->previousQuantity, $scale),
                'restocked_quantity' => round($row->restockedQuantity, $scale),
                'expected_quantity' => round($row->expectedQuantity, $scale),
                'counted_quantity' => round($row->countedQuantity, $scale),
            ], $this->resource->rows),
        ];
    }
}
