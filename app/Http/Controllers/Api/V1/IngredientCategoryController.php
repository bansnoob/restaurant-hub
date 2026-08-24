<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBranch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IngredientCategoryResource;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Services\InventoryService;
use App\Support\Inventory\CategorySlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * User-managed inventory categories: the PHYSICAL walk order a stock count
 * follows. Reachable by owners and cashiers, always scoped to the caller's
 * branch through ResolvesBranch.
 */
class IngredientCategoryController extends Controller
{
    use ResolvesBranch;

    private const NAME_MAX = 100;

    private const SLUG_MAX = 140;

    /** One gesture's worth of reordering; far above any real walk. */
    private const MAX_REORDER = 100;

    private const DUPLICATE_NAME = 'A category with that name already exists in this branch.';

    private const SHARED_DENIED = 'Shared categories are managed centrally and cannot be changed from a branch.';

    private const CROSS_BRANCH_DENIED = 'You cannot modify a category from another branch.';

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);

        $branchId = $this->resolveBranchId($request);

        return IngredientCategoryResource::collection(
            $this->inventory->listCategories($branchId, $request->boolean('include_inactive'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Two-phase, matching IngredientController::store — branch_id must
        // clear `exists` BEFORE it is resolved, and the slug unique rule has to
        // be composed against the branch the row will actually land in.
        $request->validate(['branch_id' => ['sometimes', 'integer', 'exists:branches,id']]);
        $branchId = $this->resolveBranchId($request);

        $validated = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'slug' => ['required', 'string', 'max:'.self::SLUG_MAX, $this->uniqueSlugRule($branchId)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.IngredientCategory::MAX_SORT_ORDER],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = DB::transaction(fn (): IngredientCategory => IngredientCategory::create([
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            // Omitted sort_order appends to the END of the walk. A new shelf
            // must never silently jump to the front of everybody's count.
            'sort_order' => $validated['sort_order'] ?? IngredientCategory::nextSortOrder($branchId),
            'is_active' => $validated['is_active'] ?? true,
        ]));

        return (new IngredientCategoryResource($this->withBranchCount($category, $branchId)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, IngredientCategory $ingredientCategory): IngredientCategoryResource
    {
        $branchId = $this->authorizeCategory($request, $ingredientCategory);

        // A rename RE-DERIVES the slug, so CategorySlug stays the single source.
        $validated = $this->validatePayload($request, [
            'name' => ['sometimes', 'required', 'string', 'max:'.self::NAME_MAX],
            'slug' => [
                'sometimes', 'required', 'string', 'max:'.self::SLUG_MAX,
                $this->uniqueSlugRule($branchId)->ignore($ingredientCategory->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.IngredientCategory::MAX_SORT_ORDER],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($ingredientCategory, $validated): IngredientCategory {
            if ($validated !== []) {
                $ingredientCategory->update($validated);
            }

            return $ingredientCategory->fresh();
        });

        return new IngredientCategoryResource($this->withBranchCount($updated, $branchId));
    }

    /**
     * HARD delete. Its ingredients SURVIVE and become uncategorised — they
     * reappear in the trailing "Uncategorized" section of every list and of
     * every count. No stock row is ever touched.
     */
    public function destroy(Request $request, IngredientCategory $ingredientCategory): JsonResponse
    {
        $this->authorizeCategory($request, $ingredientCategory);

        $result = DB::transaction(function () use ($ingredientCategory): array {
            $id = (int) $ingredientCategory->id;

            // Read BEFORE the delete, and unlink explicitly rather than trusting
            // the nullOnDelete FK: SQLite only enforces it with the foreign_keys
            // pragma on, and a dangling id would hide rows from every picker.
            $affected = Ingredient::where('ingredient_category_id', $id)->count();
            Ingredient::where('ingredient_category_id', $id)->update(['ingredient_category_id' => null]);
            $ingredientCategory->delete();

            return ['id' => $id, 'uncategorized_count' => $affected];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Rewrite the whole walk order in ONE write.
     *
     * A separate endpoint rather than N PUTs: a dropped connection mid-reorder
     * would leave the walk half-renumbered, and N PUTs would burn N of the
     * 60/min throttle for a single drag.
     */
    public function reorder(Request $request): AnonymousResourceCollection
    {
        $request->validate(['branch_id' => ['sometimes', 'integer', 'exists:branches,id']]);
        $branchId = $this->resolveBranchId($request);

        // Rule::in over the branch's OWN ids: a cashier can never renumber
        // another branch's walk, and a shared category cannot be reordered
        // from one branch because it belongs to all of them.
        $ownIds = $this->inventory->ownCategoryIds($branchId);

        $validated = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:'.self::MAX_REORDER],
            'categories.*.id' => ['required', 'integer', 'distinct', Rule::in($ownIds)],
            'categories.*.sort_order' => ['required', 'integer', 'min:0', 'max:'.IngredientCategory::MAX_SORT_ORDER],
        ]);

        $categories = DB::transaction(function () use ($validated, $branchId) {
            foreach ($validated['categories'] as $row) {
                IngredientCategory::whereKey($row['id'])
                    ->where('branch_id', $branchId)
                    ->update(['sort_order' => $row['sort_order']]);
            }

            return $this->inventory->listCategories($branchId, true);
        });

        return IngredientCategoryResource::collection($categories);
    }

    /**
     * Both branch guards for a route-model-bound category, in the order they
     * must fire. Returns the category's branch id.
     */
    private function authorizeCategory(Request $request, IngredientCategory $category): int
    {
        abort_if($category->isShared(), 403, self::SHARED_DENIED);
        $this->authorizeBranchId($request, (int) $category->branch_id, self::CROSS_BRANCH_DENIED);

        return (int) $category->branch_id;
    }

    /**
     * Validate a category write with the slug DERIVED from `name`, never
     * accepted from the client — otherwise two surfaces could disagree about
     * what "Dry Store" slugs to, and a client could aim the unique check at a
     * row it does not own.
     *
     * The payload is rebuilt rather than merged into the Request because a
     * JSON body and a form body live in different bags; overwriting the array
     * key is the one form that works for both.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, array $rules): array
    {
        $payload = $request->all();

        // is_string, not a cast. `{"name": {"a": "b"}}` is a client mistake the
        // `string` rule below is there to reject as a 422 — but a (string) cast
        // here raises "Array to string conversion", which Laravel promotes to an
        // ErrorException, so the rule never runs and the operator gets a 500 with
        // "Something went wrong." Leaving the value alone lets validation speak.
        if (array_key_exists('name', $payload) && is_string($payload['name'])) {
            $payload['slug'] = CategorySlug::for($payload['name']);
        } else {
            // The slug is DERIVED, never accepted from the client: dropping it
            // also stops a hand-posted slug from aiming the unique check at a
            // row the caller does not own.
            unset($payload['slug']);
        }

        return validator($payload, $rules, ['slug.unique' => self::DUPLICATE_NAME])->validate();
    }

    /**
     * Unique across everything the branch can RESOLVE — its own rows and the
     * shared ones. A branch-local twin of a shared category would put two
     * identically named shelves in every picker.
     */
    private function uniqueSlugRule(int $branchId): Unique
    {
        return Rule::unique('ingredient_categories', 'slug')
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
    }

    /** ingredient_count must be the BRANCH's count, never a cross-branch total. */
    private function withBranchCount(IngredientCategory $category, int $branchId): IngredientCategory
    {
        $category->loadCount(['ingredients' => fn ($q) => $q->where('ingredients.branch_id', $branchId)]);

        return $category;
    }
}
