<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Services\Inventory\CategoryReader;
use App\Support\Inventory\CategorySlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The owner-facing category manager: rename, add, reorder, deactivate, delete.
 *
 * A category exists to make the count walk match the shelves, so the write that
 * matters most here is the REORDER, and it is one atomic post rather than N
 * saves: a connection dropped halfway through N would leave the walk
 * half-renumbered for whoever counts next.
 *
 * Its own controller rather than four more methods on InventoryController,
 * which already carries the module's densest read.
 */
class InventoryCategoryController extends Controller
{
    private const NAME_MAX = 100;

    private const SLUG_MAX = 140;

    /** unsignedSmallInteger — anything above this is a MySQL 1264 out-of-range. */
    private const SORT_ORDER_MAX = IngredientCategory::MAX_SORT_ORDER;

    private const REORDER_MAX = 100;

    private const DUPLICATE_MESSAGE = 'A category with that name already exists in this branch.';

    /**
     * A shared category (branch_id null) spans every branch, so changing it from
     * one branch's page would silently reshape every other branch's walk.
     */
    private const SHARED_MESSAGE = 'Shared categories are managed centrally and cannot be changed from a branch.';

    public function __construct(
        private readonly CategoryReader $categories,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        // Two-phase: the slug's uniqueness rule is scoped to the branch, so the
        // branch has to be known before the rest is validated.
        $scalars = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $branchId = (int) $scalars['branch_id'];

        $this->deriveSlug($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'slug' => [
                'required',
                'string',
                'max:'.self::SLUG_MAX,
                $this->uniqueSlugRule($branchId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.self::SORT_ORDER_MAX],
        ], ['slug.unique' => self::DUPLICATE_MESSAGE]);

        IngredientCategory::create([
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            // Omitted means "append to the END of the walk". Landing at the
            // front would silently reroute everybody's next count.
            'sort_order' => isset($validated['sort_order'])
                ? (int) $validated['sort_order']
                : IngredientCategory::nextSortOrder($branchId),
            'is_active' => true,
        ]);

        return $this->back('Category added.');
    }

    public function update(Request $request, IngredientCategory $ingredientCategory): RedirectResponse
    {
        abort_if($ingredientCategory->branch_id === null, 403, self::SHARED_MESSAGE);

        $branchId = (int) $ingredientCategory->branch_id;

        // A rename RE-DERIVES the slug, so CategorySlug stays the single source of
        // a category's slug on every surface.
        if ($request->has('name')) {
            $this->deriveSlug($request);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.self::NAME_MAX],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:'.self::SLUG_MAX,
                $this->uniqueSlugRule($branchId)->ignore($ingredientCategory->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.self::SORT_ORDER_MAX],
            'is_active' => ['sometimes', 'boolean'],
        ], ['slug.unique' => self::DUPLICATE_MESSAGE]);

        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $ingredientCategory->update($validated);

        return $this->back('Category updated.');
    }

    /**
     * Hard delete. NOTHING is deleted from stock: the FK is nullOnDelete, so the
     * freed ingredients simply reappear in the Uncategorized tail where somebody
     * can file them again.
     */
    public function destroy(IngredientCategory $ingredientCategory): RedirectResponse
    {
        abort_if($ingredientCategory->branch_id === null, 403, self::SHARED_MESSAGE);

        $freed = DB::transaction(function () use ($ingredientCategory): int {
            // Read INSIDE the transaction and BEFORE the delete, or the count is
            // always zero.
            $count = Ingredient::where('ingredient_category_id', $ingredientCategory->id)->count();
            $ingredientCategory->delete();

            return $count;
        });

        return $this->back(
            'Category deleted. '.$freed.' '.Str::plural('ingredient', $freed).' now uncategorized.'
        );
    }

    /**
     * Rewrite the whole walk in ONE transaction.
     *
     * Only the branch's OWN categories may be renumbered — shared rows are
     * filtered out client-side and rejected here, because reordering one would
     * change every other branch's walk.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $scalars = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $branchId = (int) $scalars['branch_id'];

        // The branch's OWN ids only. A shared category is managed centrally, so
        // renumbering one here would reshape every other branch's walk.
        $ownIds = $this->categories->ownCategoryIds($branchId);

        $validated = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:'.self::REORDER_MAX],
            'categories.*.id' => ['required', 'integer', 'distinct', Rule::in($ownIds)],
            'categories.*.sort_order' => ['required', 'integer', 'min:0', 'max:'.self::SORT_ORDER_MAX],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['categories'] as $row) {
                IngredientCategory::where('id', (int) $row['id'])
                    ->update(['sort_order' => (int) $row['sort_order']]);
            }
        });

        return $this->back('Count order saved.');
    }

    /**
     * Derive the slug from `name`, the ONE derivation shared with the API.
     *
     * The is_string guard is load-bearing: `name[]=x` posted from the drawer is
     * a client mistake the `string` rule exists to reject, but casting it here
     * raises "Array to string conversion" — promoted to an ErrorException — so
     * the owner gets a whole 500 page and loses the drawer instead of a
     * validation message. Left unmerged, the rules produce the 422 they should.
     */
    private function deriveSlug(Request $request): void
    {
        $raw = $request->input('name');

        if (! is_string($raw)) {
            // Nulled rather than left alone so a hand-posted slug can never
            // satisfy the `required` rule (or aim the unique check) on a
            // request whose name is about to be rejected.
            $request->merge(['slug' => null]);

            return;
        }

        $name = trim($raw);
        $request->merge(['name' => $name, 'slug' => CategorySlug::for($name)]);
    }

    /**
     * Unique across everything the branch can RESOLVE — its own rows AND the
     * shared ones, exactly as Api\V1\IngredientCategoryController does.
     *
     * Scoping this to the branch alone let the web module create a branch-local
     * twin of a shared category that the API rejects: UNIQUE(branch_id, slug)
     * treats a null branch_id as distinct, so both rows survive, scopeForBranch
     * returns both, and every picker, filter row and count header shows the same
     * name twice.
     */
    private function uniqueSlugRule(int $branchId): Unique
    {
        return Rule::unique('ingredient_categories', 'slug')
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'));
    }

    /**
     * Every write redirects back with the drawer reopened, so the owner lands
     * where they were instead of at the top of the page.
     */
    private function back(string $message): RedirectResponse
    {
        return back()->with('success', $message)->with('categories_open', true);
    }
}
