<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\IngredientCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only access to the category walk. Split out of InventoryService because
 * that file is already at the project's 800-line ceiling, and because a
 * category read has nothing to do with the stock arithmetic that file guards.
 */
final class CategoryReader
{
    /**
     * The categories a branch may use: its own plus the SHARED (branch_id
     * NULL) ones, in walk order.
     *
     * $branchId null means "no branch filter", which is the web module's
     * all-branches view. The decision there is explicit: it returns EVERY
     * branch's categories, because that view also lists every branch's
     * ingredients — showing one without the other would leave rows the filter
     * cannot reach. Names may therefore repeat across branches; ids do not,
     * and the filter is keyed on the id.
     *
     * ingredient_count is BRANCH-SCOPED whenever a branch is known. A bare
     * withCount('ingredients') would make a shared category report every
     * branch's total, which leaks one branch's size to another's cashier and
     * puts the wrong number in the delete confirmation.
     *
     * @return Collection<int, IngredientCategory>
     */
    public function listCategories(?int $branchId, bool $includeInactive = false): Collection
    {
        return IngredientCategory::query()
            ->forBranch($branchId)
            ->when(! $includeInactive, fn (Builder $q): Builder => $q->where('is_active', true))
            ->withCount(['ingredients' => fn ($q) => $branchId === null
                ? $q
                : $q->where('ingredients.branch_id', $branchId)])
            ->orderedForWalk()
            ->get();
    }

    /**
     * The branch's OWN category ids — the only ones a branch-scoped write may
     * name. Shared categories are excluded on purpose: they are managed
     * centrally and cannot be renamed, reordered or deleted from a branch.
     *
     * @return list<int>
     */
    public function ownCategoryIds(int $branchId): array
    {
        return IngredientCategory::query()
            ->where('branch_id', $branchId)
            ->orderedForWalk()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
