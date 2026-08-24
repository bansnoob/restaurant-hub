<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Support\Inventory\InventorySummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Branch-level snapshots over inventory: the Home tile's summary, the recent
 * count list, and the "is this the newest count?" guard deleteCount leans on.
 *
 * Pure reads with no stock arithmetic, extracted from InventoryService so that
 * file stays inside the project's 800-line ceiling. InventoryService still
 * exposes all three — every caller keeps its existing entry point.
 */
final class InventorySnapshotReader
{
    /** @return Collection<int, StockCount> */
    public function recentCounts(?int $branchId, int $limit): Collection
    {
        return StockCount::with(['branch:id,name', 'recordedBy:id,name'])
            ->withCount('entries')
            ->withSum('entries', 'consumption')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function summary(?int $branchId): InventorySummary
    {
        $scope = fn ($q) => $branchId === null ? $q : $q->where('branch_id', $branchId);

        $totalItems = $scope(Ingredient::query())->count();
        $activeItems = $scope(Ingredient::query())->where('is_active', true)->count();
        // Active-only, matching Ingredient::isLowStock() applied to the list the
        // banner links to. A deactivated ingredient keeps its reorder_level, so
        // without this filter the tile counts rows the list will never show.
        $lowStockCount = $scope(Ingredient::query())
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        $latestCount = $scope(StockCount::query())
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();

        $countsThisMonth = $scope(StockCount::query())
            ->whereDate('counted_at', '>=', now()->startOfMonth()->toDateString())
            ->count();

        return new InventorySummary(
            branchId: $branchId,
            branchName: $branchId === null ? null : Branch::find($branchId)?->name,
            totalItems: $totalItems,
            activeItems: $activeItems,
            lowStockCount: $lowStockCount,
            lastCountAt: $latestCount?->counted_at?->toDateString(),
            daysSinceLastCount: $latestCount
                ? (int) Carbon::parse($latestCount->counted_at)->diffInDays(now())
                : null,
            countsThisMonth: $countsThisMonth,
        );
    }

    /** True when the given count is its BRANCH's most recent (guards deleteCount). */
    public function isLatestCount(StockCount $stockCount): bool
    {
        $latest = StockCount::where('branch_id', $stockCount->branch_id)
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->id === $stockCount->id;
    }
}
