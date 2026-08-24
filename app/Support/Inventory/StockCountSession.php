<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * An open stock count session: the walk list plus the restock claim fence.
 *
 * $rows IS the walk order: grouped by category sort_order, then by ingredient
 * name, with the uncategorised tail last. Every consumer — the API resource,
 * the web count modal, the phone — must group consecutive runs and NEVER
 * re-sort, or two surfaces will walk the same branch's shelves differently.
 *
 * $branchId / $branchName are nullable for historical reasons only. Both HTTP
 * surfaces now require a branch (the web startCount() made branch_id
 * mandatory), so nothing should be designed around a branchless session.
 */
final readonly class StockCountSession
{
    /** @param list<StockCountSessionRow> $rows */
    public function __construct(
        public ?int $branchId,
        public ?string $branchName,
        public string $today,
        public int $restockCursor,
        public array $rows,
    ) {}
}
