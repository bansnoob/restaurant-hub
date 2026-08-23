<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * Cheap branch-scoped inventory stats. $branchId is null when the summary
 * spans every branch (the web dashboard).
 */
final readonly class InventorySummary
{
    public function __construct(
        public ?int $branchId,
        public ?string $branchName,
        public int $totalItems,
        public int $activeItems,
        public int $lowStockCount,
        public ?string $lastCountAt,
        public ?int $daysSinceLastCount,
        public int $countsThisMonth,
    ) {}
}
