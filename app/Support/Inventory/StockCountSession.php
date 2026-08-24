<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * An open stock count session: the walk list plus the restock claim fence.
 *
 * $branchId / $branchName are nullable because the WEB flow opens a session
 * across every branch (the blade fetches /inventory/counts/start with no
 * branch_id and lets the owner pick the branch afterwards). The mobile API
 * always resolves a concrete branch.
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
