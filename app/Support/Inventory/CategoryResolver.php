<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\IngredientCategory;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The ONE definition of "which category does this ingredient land in", shared
 * by App\Http\Controllers\Api\V1\IngredientController and the web
 * App\Http\Controllers\InventoryController — the same reason CountEntryRules
 * exists. Two hand-maintained copies of a branch-scoping rule drift; this one
 * cannot.
 *
 * IMPORTANT for callers: this function cannot tell "the key was absent" from
 * "the key was an explicit null" — both arrive as null and both resolve to
 * null (uncategorised). A partial update must therefore only CALL it when the
 * request actually carried one of the two keys, or an unrelated PATCH would
 * silently clear the assignment.
 */
final class CategoryResolver
{
    /**
     * @param  int|null  $categoryId  the picked id; null means "no category"
     * @param  string|null  $newCategoryName  inline create; WINS over $categoryId
     * @return int|null the id to write, or null for uncategorised
     */
    public static function resolve(int $branchId, ?int $categoryId, ?string $newCategoryName): ?int
    {
        $name = trim((string) $newCategoryName);

        if ($name === '') {
            return $categoryId;
        }

        $slug = CategorySlug::for($name);

        $existing = self::findResolvable($branchId, $slug);

        return (int) ($existing?->id ?? self::create($branchId, $name, $slug)->id);
    }

    /**
     * The one lookup for "which existing category does this slug mean here".
     *
     * Resolved through forBranch, not `where(branch_id = X)`: a SHARED category
     * with this slug is already visible to the branch, and creating a
     * branch-local twin would put two identically named shelves in every picker.
     *
     * The ordering is not decoration. A branch-local row and a shared row CAN
     * legally carry the same slug — UNIQUE(branch_id, slug) treats a null
     * branch_id as distinct — and without an explicit order the winner would be
     * whatever the driver returned first, so two saves of the same name could
     * file their ingredients on two different shelves. The branch's OWN row
     * wins, always.
     *
     * $lock is for the duplicate-key recovery read only: see create().
     */
    private static function findResolvable(int $branchId, string $slug, bool $lock = false): ?IngredientCategory
    {
        $query = IngredientCategory::query()
            ->forBranch($branchId)
            ->where('slug', $slug)
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * firstOrCreate is a SELECT then an INSERT with no lock, so the double tap
     * this inline-create flow exists to survive (flaky phone connection, two
     * taps on "Save") loses the race and the second INSERT hits
     * UNIQUE(branch_id, slug) — a 500 with the operator's half-typed
     * ingredient gone. Catch the violation and read the winner back instead.
     */
    private static function create(int $branchId, string $name, string $slug): IngredientCategory
    {
        try {
            return IngredientCategory::create([
                'branch_id' => $branchId,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => IngredientCategory::nextSortOrder($branchId),
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException $violation) {
            // LOCKING read, deliberately. Callers run this inside a transaction
            // (both ingredient controllers do), and on MySQL's default
            // REPEATABLE READ a plain SELECT still answers from the snapshot
            // taken BEFORE the racing transaction committed — so the recovery
            // read would find nothing and turn the race it exists to survive
            // into a 404. A locking read always sees the latest committed row.
            $winner = self::findResolvable($branchId, $slug, lock: true);

            // Not found means the violation was something OTHER than this slug
            // losing the race; rethrowing keeps the real cause instead of
            // reporting a missing model.
            if ($winner === null) {
                throw $violation;
            }

            return $winner;
        }
    }
}
