<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * One pre-filled line of a stock count session.
 *
 * Invariant: expectedQuantity === previousQuantity + restockedQuantity, i.e.
 * the book stock the counter is being asked to verify.
 */
final readonly class StockCountSessionRow
{
    public function __construct(
        public int $ingredientId,
        public string $name,
        public ?string $sku,
        public string $unit,
        public int $branchId,
        public ?string $branchName,
        public float $reorderLevel,
        public float $previousQuantity,
        public float $restockedQuantity,
        public float $expectedQuantity,
        public float $countedQuantity,
    ) {}
}
