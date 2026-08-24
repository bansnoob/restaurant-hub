<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBranch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IngredientDetailResource;
use App\Http\Resources\V1\IngredientResource;
use App\Http\Resources\V1\InventorySummaryResource;
use App\Models\Ingredient;
use App\Services\InventoryService;
use App\Support\Inventory\CategoryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    use ResolvesBranch;

    private const MODIFY_DENIED = 'You cannot modify an ingredient from another branch.';

    /** Matches ingredient_categories.name and the category endpoints. */
    private const CATEGORY_NAME_MAX = 100;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /** Cheap branch-scoped stats for the Home tile and the list header. */
    public function summary(Request $request): InventorySummaryResource
    {
        $request->validate(['branch_id' => ['sometimes', 'integer', 'exists:branches,id']]);

        return new InventorySummaryResource($this->inventory->summary($this->resolveBranchId($request)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'search' => ['sometimes', 'string', 'max:120'],
            'unit' => ['sometimes', Rule::in(InventoryService::UNITS)],
            'category_id' => ['sometimes', 'string', 'regex:/^(none|\d+)$/'],
            'low_only' => ['sometimes', 'boolean'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);

        $views = $this->inventory->listIngredients($this->resolveBranchId($request), [
            'search' => (string) $request->query('search', ''),
            'unit' => (string) $request->query('unit', ''),
            'category_id' => $this->categoryFilter($request),
            'low_only' => $request->boolean('low_only'),
            'include_inactive' => $request->boolean('include_inactive'),
        ]);

        return IngredientResource::collection($views);
    }

    public function show(Request $request, Ingredient $ingredient): IngredientDetailResource
    {
        $this->authorizeBranchId($request, (int) $ingredient->branch_id);

        return new IngredientDetailResource(
            $this->inventory->viewIngredient($ingredient),
            $this->inventory->ingredientHistory($ingredient),
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Two-phase, matching StockCountController::store — branch_id must clear
        // `exists` BEFORE it is resolved, otherwise an unchecked id reaches
        // Ingredient::create and the branch_id foreign key fails as a 500
        // instead of a 422 the client can act on.
        $request->validate(['branch_id' => ['sometimes', 'integer', 'exists:branches,id']]);

        // Resolved BEFORE the rest of the rules so the sku unique rule is
        // composed against the branch the record will actually land in.
        $branchId = $this->resolveBranchId($request);
        $this->normaliseSku($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:40', Rule::unique('ingredients', 'sku')->where('branch_id', $branchId)],
            'unit' => ['required', Rule::in(InventoryService::UNITS)],
            'current_stock' => $this->quantityRules(),
            'reorder_level' => $this->quantityRules(),
            'cost_per_unit' => array_merge(['sometimes'], $this->costRules()),
            'ingredient_category_id' => $this->categoryIdRules($branchId),
            'new_category_name' => ['sometimes', 'nullable', 'string', 'max:'.self::CATEGORY_NAME_MAX],
        ]);

        // Built key by key on purpose: spreading $validated would let a
        // client-supplied branch_id win over the resolved one.
        // The inline category create runs INSIDE this transaction, so a failed
        // ingredient insert cannot leave an orphan category behind.
        $ingredient = DB::transaction(fn (): Ingredient => Ingredient::create([
            'branch_id' => $branchId,
            'ingredient_category_id' => CategoryResolver::resolve(
                $branchId,
                $this->nullableInt($validated['ingredient_category_id'] ?? null),
                $validated['new_category_name'] ?? null,
            ),
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'unit' => $validated['unit'],
            'current_stock' => $validated['current_stock'],
            'reorder_level' => $validated['reorder_level'],
            'cost_per_unit' => $validated['cost_per_unit'] ?? 0,
            'is_active' => true,
        ]));

        $ingredient->load('category');

        // No opening inventory_movement: the opening current_stock IS the baseline.
        return (new IngredientResource($this->inventory->viewIngredient($ingredient)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Ingredient $ingredient): IngredientResource
    {
        $this->authorizeBranchId($request, (int) $ingredient->branch_id, self::MODIFY_DENIED);
        $this->normaliseSku($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'sku' => [
                'sometimes', 'nullable', 'string', 'max:40',
                Rule::unique('ingredients', 'sku')->where('branch_id', $ingredient->branch_id)->ignore($ingredient->id),
            ],
            'unit' => ['sometimes', 'required', Rule::in(InventoryService::UNITS)],
            'current_stock' => array_merge(['sometimes'], $this->quantityRules()),
            'expected_current_stock' => ['sometimes', 'numeric'],
            'reorder_level' => array_merge(['sometimes'], $this->quantityRules()),
            'cost_per_unit' => array_merge(['sometimes'], $this->costRules()),
            'is_active' => ['sometimes', 'boolean'],
            'ingredient_category_id' => $this->categoryIdRules((int) $ingredient->branch_id),
            'new_category_name' => ['sometimes', 'nullable', 'string', 'max:'.self::CATEGORY_NAME_MAX],
        ]);

        $stock = Arr::pull($validated, 'current_stock');
        $expected = Arr::pull($validated, 'expected_current_stock');
        $categoryId = Arr::pull($validated, 'ingredient_category_id');
        // Pulled out because it is NOT a column — left in $validated it would
        // reach $ingredient->update() and, the day it is added to $fillable,
        // become an "Unknown column" 500.
        $newCategoryName = Arr::pull($validated, 'new_category_name');
        // branch_id is not in the rule set; excluded defensively so an
        // ingredient can never be moved between branches over the API.
        $attributes = Arr::except($validated, ['branch_id']);

        // Only touched when the request actually CARRIED one of the two keys.
        // CategoryResolver cannot tell "key absent" from "explicit null", so a
        // partial update like {is_active: false} would otherwise silently
        // clear the assignment. An explicit null still clears it, by design.
        $resolvesCategory = $request->has('ingredient_category_id') || $request->filled('new_category_name');

        $updated = DB::transaction(function () use ($ingredient, $attributes, $stock, $expected, $request, $resolvesCategory, $categoryId, $newCategoryName): Ingredient {
            // INSIDE the transaction, like store(): recordAdjustment below can
            // abort 409 on a stale expected_current_stock and unwind everything,
            // and an inline category resolved outside would survive that
            // rollback as an empty shelf the operator never got to keep.
            if ($resolvesCategory) {
                $attributes['ingredient_category_id'] = CategoryResolver::resolve(
                    (int) $ingredient->branch_id,
                    $this->nullableInt($categoryId),
                    $newCategoryName,
                );
            }

            if ($attributes !== []) {
                $ingredient->update($attributes);
            }

            if ($stock === null) {
                return $ingredient->fresh();
            }

            // A current_stock edit is a MANUAL ADJUSTMENT: it must land in
            // inventory_movements, never as a bare column write.
            return $this->inventory->recordAdjustment(
                $ingredient,
                (float) $stock,
                null,
                $request->user(),
                $expected === null ? null : (float) $expected,
            );
        });

        return new IngredientResource($this->inventory->viewIngredient($updated->load('category')));
    }

    /**
     * DEACTIVATE, not destroy. inventory_movements.ingredient_id and
     * stock_count_entries.ingredient_id are both restrictOnDelete, and a real
     * delete would silently rewrite past counts.
     */
    public function destroy(Request $request, Ingredient $ingredient): IngredientResource
    {
        $this->authorizeBranchId($request, (int) $ingredient->branch_id, self::MODIFY_DENIED);

        $updated = DB::transaction(function () use ($ingredient): Ingredient {
            $ingredient->update(['is_active' => false]);

            return $ingredient->fresh();
        });

        return new IngredientResource($this->inventory->viewIngredient($updated));
    }

    /**
     * ingredients has UNIQUE(branch_id, sku) and MySQL treats NULLs as
     * distinct — but two rows saved with an EMPTY STRING sku collide. Normalise
     * '' to null before the unique rule ever sees it.
     */
    private function normaliseSku(Request $request): void
    {
        if (! $request->has('sku')) {
            return;
        }

        $sku = trim((string) $request->input('sku'));
        $request->merge(['sku' => $sku === '' ? null : $sku]);
    }

    /**
     * `category_id` arrives as a STRING ('12' or 'none'); the service branches
     * on is_int(), so an uncast value would silently no-op the filter.
     */
    private function categoryFilter(Request $request): int|string|null
    {
        $raw = trim((string) $request->query('category_id', ''));

        if ($raw === '') {
            return null;
        }

        return $raw === 'none' ? 'none' : (int) $raw;
    }

    /**
     * BRANCH-SCOPED existence, never a bare `exists:ingredient_categories,id`.
     * Without the scope a cashier could attach another branch's category to
     * their own ingredient and read that branch's category name straight back
     * out of IngredientResource.
     *
     * `sometimes` is load-bearing: without it an OMITTED key validates as null
     * and every partial update would clear the category.
     *
     * @return list<mixed>
     */
    private function categoryIdRules(int $branchId): array
    {
        return [
            'sometimes', 'nullable', 'integer',
            Rule::exists('ingredient_categories', 'id')
                ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @return list<string> */
    private function quantityRules(): array
    {
        return ['required', 'numeric', 'min:0', 'max:'.InventoryService::MAX_QUANTITY, 'decimal:0,'.InventoryService::QUANTITY_SCALE];
    }

    /** @return list<string> */
    private function costRules(): array
    {
        return ['numeric', 'min:0', 'max:'.InventoryService::MAX_UNIT_COST, 'decimal:0,'.InventoryService::COST_SCALE];
    }
}
