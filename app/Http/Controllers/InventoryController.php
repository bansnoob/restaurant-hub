<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Services\InventoryService;
use App\Support\Inventory\CategoryResolver;
use App\Support\Inventory\CountEntryRules;
use App\Support\Inventory\StockCountSessionRow;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\View\View;

/**
 * The owner-facing web inventory module.
 *
 * All inventory arithmetic lives in App\Services\InventoryService, shared with
 * the mobile API controllers, so the two surfaces can never drift on
 * consumption, previous quantities or the restock claim.
 */
class InventoryController extends Controller
{
    private const UNITS = InventoryService::UNITS;

    /**
     * The ONE label for "no category", identical to UNCATEGORIZED_LABEL in
     * resources/js/inventory/inventory-page.js and to the phone's, so the same
     * shelf is never named two different things on two screens.
     */
    public const UNCATEGORIZED_LABEL = 'Uncategorized';

    /** Group key for the uncategorized tail. A category id cannot collide. */
    private const UNCATEGORIZED_KEY = 'uncategorized';

    /** The query-string sentinel the service reads as "no category at all". */
    private const UNCATEGORIZED_FILTER = 'none';

    private const CATEGORY_NAME_MAX = 100;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $branchFilter = $request->query('branch_id');
        $branchId = $this->branchId($branchFilter);
        $unitFilter = (string) $request->query('unit', '');
        $search = trim((string) $request->query('search', ''));
        $lowOnly = (bool) $request->query('low_only', false);

        // Inactive categories are loaded too: the manager drawer is the only
        // place they are reachable, and the pickers filter them out client-side.
        $categories = $this->inventory->listCategories($branchId, includeInactive: true);
        $categoryFilter = $this->categoryFilter($request->query('category_id'), $categories);

        // `include_inactive` is deliberately absent: the web index lists active
        // and inactive ingredients together, as it always has.
        $views = $this->inventory->listIngredients($branchId, [
            'search' => $search,
            'unit' => in_array($unitFilter, self::UNITS, true) ? $unitFilter : '',
            'low_only' => $lowOnly,
            'category_id' => $categoryFilter,
        ]);

        // The decorated clones inherit whatever the service eager-loaded;
        // loadMissing fires at most ONE extra query for the whole page, never
        // one per row.
        $ingredients = EloquentCollection::make($views->map->toDecoratedModel())->loadMissing('category');
        $groups = $this->groupByCategory($ingredients);

        $allIngredients = Ingredient::with('branch')->orderBy('name')->get();
        $lowStock = $allIngredients->filter(fn (Ingredient $i) => $i->isLowStock());

        $summary = $this->inventory->summary(null);
        $stats = [
            'total_items' => $summary->totalItems,
            'active_items' => $summary->activeItems,
            'low_stock_count' => $summary->lowStockCount,
            'days_since_last_count' => $summary->daysSinceLastCount,
            'last_count_at' => $summary->lastCountAt,
            'counts_this_month' => $summary->countsThisMonth,
        ];

        $recentCounts = $this->inventory->recentCounts(null);

        $filters = [
            'search' => $search,
            'branch_id' => $branchFilter,
            'unit' => $unitFilter,
            'low_only' => $lowOnly,
            // The SANITISED value, never the raw one: a category that has since
            // been deleted is dropped by categoryFilter(), and the toolbar has
            // to fall back to "All categories" rather than showing a filter the
            // query is no longer applying.
            'category_id' => $categoryFilter === null ? '' : (string) $categoryFilter,
        ];

        // Categories belong to a branch, so the manager edits ONE branch's walk:
        // the filtered branch, or the only branch there is.
        $manageBranchId = $branchId !== null
            ? (string) $branchId
            : ($branches->count() === 1 ? (string) $branches->first()->id : '');

        return view('modules.inventory.index', compact(
            'branches',
            'ingredients',
            'groups',
            'categories',
            'manageBranchId',
            'allIngredients',
            'lowStock',
            'recentCounts',
            'stats',
            'filters',
        ));
    }

    /**
     * The query string gives us a string; the service takes an id, the literal
     * 'none', or null for "do not filter on category at all".
     *
     * An id the branch cannot resolve (deleted, or another branch's) is DROPPED
     * rather than passed through — otherwise the list would narrow to nothing
     * while the toolbar showed no filter to clear.
     *
     * @param  SupportCollection<int, IngredientCategory>  $categories
     */
    private function categoryFilter(mixed $raw, SupportCollection $categories): int|string|null
    {
        $value = is_string($raw) || is_int($raw) ? trim((string) $raw) : '';

        if ($value === '') {
            return null;
        }

        if ($value === self::UNCATEGORIZED_FILTER) {
            return self::UNCATEGORIZED_FILTER;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $categories->contains(fn (IngredientCategory $c): bool => (int) $c->id === $id) ? $id : null;
    }

    /**
     * Cut the ALREADY-ORDERED ingredient list into category sections.
     *
     * The service returned the rows in walk order, so this only cuts the run —
     * it must NOT sort, or the blade and the count modal would disagree about
     * the same branch. The uncategorized tail is appended LAST wherever it
     * appeared, matching Ingredient::scopeOrderedForWalk, groupCountRows() and
     * the phone's buildIngredientSections.
     *
     * @param  EloquentCollection<int, Ingredient>  $ingredients
     * @return list<array{key: string, name: string, sort_order: int|null, items: list<Ingredient>}>
     */
    private function groupByCategory(EloquentCollection $ingredients): array
    {
        $groups = [];
        $tail = [];

        foreach ($ingredients as $ingredient) {
            $category = $ingredient->category;

            if ($category === null) {
                $tail[] = $ingredient;

                continue;
            }

            $key = (string) $category->id;
            $groups[$key] ??= [
                'key' => $key,
                'name' => $category->name,
                'sort_order' => (int) $category->sort_order,
                'items' => [],
            ];
            $groups[$key]['items'][] = $ingredient;
        }

        $walk = array_values($groups);

        if ($tail !== []) {
            $walk[] = [
                'key' => self::UNCATEGORIZED_KEY,
                'name' => self::UNCATEGORIZED_LABEL,
                'sort_order' => null,
                'items' => $tail,
            ];
        }

        return $walk;
    }

    public function showIngredient(Ingredient $ingredient): JsonResponse
    {
        $ingredient->load(['branch', 'category']);

        $view = $this->inventory->viewIngredient($ingredient);
        $stats = $view->stats;
        $entries = $this->inventory->ingredientHistory($ingredient);

        return response()->json([
            'ingredient' => [
                'id' => $ingredient->id,
                'branch_id' => $ingredient->branch_id,
                'branch_name' => $ingredient->branch?->name,
                'name' => $ingredient->name,
                'sku' => $ingredient->sku,
                'unit' => $ingredient->unit,
                // Both keys are ALWAYS present: the drawer renders the name, and
                // "Edit" seeds the form's picker from the id. A missing id there
                // posted a blank and silently cleared the assignment.
                'ingredient_category_id' => $ingredient->ingredient_category_id === null
                    ? null
                    : (int) $ingredient->ingredient_category_id,
                'category_name' => $ingredient->category?->name,
                'current_stock' => (float) $ingredient->current_stock,
                'reorder_level' => (float) $ingredient->reorder_level,
                'is_active' => (bool) $ingredient->is_active,
                'is_low_stock' => $ingredient->isLowStock(),
                // The drawer has always received 0 (never null) for an
                // ingredient with too few counts to derive a rate from.
                'daily_consumption' => $stats->dailyConsumption ?? 0.0,
                'days_remaining' => $stats->daysRemaining,
                'last_counted_at' => $stats->lastCountedAt,
                'pending_restock' => $stats->pendingRestock,
            ],
            'history' => $entries->map(fn (StockCountEntry $e) => [
                'id' => $e->id,
                'counted_at' => $e->stockCount?->counted_at?->toDateString(),
                'counted_at_label' => $e->stockCount?->counted_at?->format('M j, Y'),
                'previous_quantity' => (float) $e->previous_quantity,
                'restocked_quantity' => (float) $e->restocked_quantity,
                'counted_quantity' => (float) $e->counted_quantity,
                'consumption' => (float) $e->consumption,
            ])->values(),
        ]);
    }

    public function startCount(Request $request): JsonResponse
    {
        // branch_id is REQUIRED: a count may only contain its own branch's
        // ingredients, so there is no such thing as a branchless session. The
        // blade sends ?branch_id=<selected> and refetches when the modal's
        // picker changes. A bare call used to walk every branch, which both
        // leaked other branches' stock levels and built a session that can no
        // longer be submitted.
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $session = $this->inventory->buildCountSession((int) $validated['branch_id']);

        return response()->json([
            'today' => $session->today,
            // The branch list is NOT repeated here: the page already owns it as
            // the `branches` Alpine config key rendered by the blade.
            'restock_cursor' => $session->restockCursor,
            'ingredients' => array_map(fn (StockCountSessionRow $row): array => [
                'ingredient_id' => $row->ingredientId,
                'name' => $row->name,
                'sku' => $row->sku,
                'unit' => $row->unit,
                'branch_id' => $row->branchId,
                'branch_name' => $row->branchName,
                'reorder_level' => $row->reorderLevel,
                'previous_quantity' => $row->previousQuantity,
                // The web count table's "Restocked" column is an EDITABLE
                // operator field. It is seeded with 0 so a manually typed
                // delivery is never added on top of the quantity already
                // derived from inventory_movements; the derived figure is
                // exposed read-only as pending_restock and folded in server-side.
                'restocked_quantity' => 0.0,
                'pending_restock' => $row->restockedQuantity,
                'expected_quantity' => $row->expectedQuantity,
                'counted_quantity' => $row->countedQuantity,
                // The walk. `rows` already arrives in category order, so the
                // component only cuts it into sections — these three keys are
                // what let it name them. Drop them and the whole count collapses
                // into one mislabelled "Uncategorized" group.
                'ingredient_category_id' => $row->ingredientCategoryId,
                'category_name' => $row->categoryName,
                'category_sort_order' => $row->categorySortOrder,
            ], $session->rows),
        ]);
    }

    public function storeCount(Request $request): RedirectResponse
    {
        // Two-phase, matching Api\V1\StockCountController::store(): the branch
        // has to be resolved BEFORE the entries array, because every
        // ingredient_id is validated against that branch's allow-list. A bare
        // `exists:ingredients,id` accepted another branch's ingredient and let
        // it be written under this branch's count.
        $scalars = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'counted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $branchId = (int) $scalars['branch_id'];
        $validated = $this->validateCountEntries($request, $branchId);

        $entries = [];
        foreach ($validated['entries'] as $row) {
            $entries[(int) $row['ingredient_id']] = [
                'counted' => (float) $row['counted_quantity'],
                // The operator's manual figure is an observation the movements
                // ledger does not know about, so the service ADDS it to the
                // claimed movement sum rather than replacing it.
                'declared_restock' => isset($row['restocked_quantity'])
                    ? (float) $row['restocked_quantity']
                    : null,
            ];
        }

        $this->inventory->recordCount(
            $branchId,
            (string) $scalars['counted_at'],
            $entries,
            // null is the WEB sentinel: claim every unclaimed movement at
            // commit time. The blade posts no cursor.
            null,
            $scalars['notes'] ?? null,
            $request->user(),
        );

        return redirect()->route('inventory.index')->with('success', 'Stock count saved.');
    }

    /**
     * Validate the posted rows against the counted branch's ingredients.
     *
     * The rules themselves live in App\Support\Inventory\CountEntryRules,
     * shared with the mobile API so the branch allow-list can never drift
     * between the two surfaces.
     *
     * @return array{entries: array<int, array<string, mixed>>}
     */
    private function validateCountEntries(Request $request, int $branchId): array
    {
        $rules = CountEntryRules::for($branchId);

        /** @var array{entries: array<int, array<string, mixed>>} $validated */
        $validated = $request->validate($rules->rules(), $rules->messages());

        return $validated;
    }

    public function showCount(StockCount $stockCount): JsonResponse
    {
        $stockCount->load(['branch:id,name', 'recordedBy:id,name']);
        // Walk order, from the shared reader: reading a saved count back
        // reproduces the physical walk it was taken in.
        $entries = $this->inventory->countEntries($stockCount);

        $countedAt = Carbon::parse($stockCount->counted_at);
        $previousCount = StockCount::where('counted_at', '<', $stockCount->counted_at)
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();
        $daysSincePrevious = $previousCount ? (int) Carbon::parse($previousCount->counted_at)->diffInDays($countedAt) : null;

        return response()->json([
            'count' => [
                'id' => $stockCount->id,
                'counted_at' => $stockCount->counted_at?->toDateString(),
                'counted_at_label' => $stockCount->counted_at?->format('M j, Y'),
                'branch' => $stockCount->branch ? ['id' => $stockCount->branch->id, 'name' => $stockCount->branch->name] : null,
                'recorded_by' => $stockCount->recordedBy?->name,
                'notes' => $stockCount->notes,
                'days_since_previous' => $daysSincePrevious,
            ],
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->ingredient?->name ?? '—',
                'sku' => $e->ingredient?->sku,
                // Reading a saved count back reproduces the walk it was taken
                // in; null is the uncategorized tail, which sorts last.
                'category_name' => $e->ingredient?->category?->name,
                'unit' => $e->ingredient?->unit ?? '—',
                'previous_quantity' => (float) $e->previous_quantity,
                'restocked_quantity' => (float) $e->restocked_quantity,
                'counted_quantity' => (float) $e->counted_quantity,
                'consumption' => (float) $e->consumption,
            ])->values(),
        ]);
    }

    /**
     * The "most recent count" guard is now BRANCH-SCOPED (it used to be global),
     * matching the branch-scoped rollback the service performs. Deleting also
     * un-claims the movements this count claimed, so a delivery is returned to
     * the pending pool instead of being stranded.
     */
    public function destroyCount(StockCount $stockCount): RedirectResponse
    {
        if (! $this->inventory->isLatestCount($stockCount)) {
            return back()->with('error', 'Only the most recent count can be deleted.');
        }

        $this->inventory->deleteCount($stockCount);

        return back()->with('success', 'Stock count deleted.');
    }

    public function store(Request $request): RedirectResponse
    {
        // Two-phase, matching Api\V1\IngredientController::store(): the branch
        // has to be resolved BEFORE the category rule is built, because a bare
        // `exists:ingredient_categories,id` would accept another branch's
        // category and leak its name straight back out of this page.
        $scalars = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $branchId = (int) $scalars['branch_id'];

        $this->normaliseCategoryInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Branch-scoped unique, matching Api\V1\IngredientController: the
            // table carries UNIQUE(branch_id, sku), so without this rule a
            // duplicate SKU is a QueryException 500 instead of a field error.
            'sku' => ['nullable', 'string', 'max:40', $this->uniqueSkuRule($branchId)],
            'unit' => ['required', 'in:g,kg,ml,l,pcs'],
            'current_stock' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'ingredient_category_id' => ['nullable', 'integer', $this->categoryExistsRule($branchId)],
            'new_category_name' => ['nullable', 'string', 'max:'.self::CATEGORY_NAME_MAX],
        ]);

        // The inline category create runs INSIDE this transaction, exactly as in
        // Api\V1\IngredientController::store, so a failed ingredient insert
        // cannot leave an orphan category behind — an empty shelf nobody asked
        // for, in every picker, filter and count header, created by a request
        // that errored.
        DB::transaction(function () use ($validated, $branchId): void {
            // Neither key is a column: mass-assigning them would either be
            // silently dropped (new_category_name) or write '' into an integer FK.
            Ingredient::create(Arr::except($validated, ['ingredient_category_id', 'new_category_name']) + [
                'branch_id' => $branchId,
                'ingredient_category_id' => $this->resolveCategory($branchId, $validated),
                'cost_per_unit' => 0,
                'is_active' => true,
            ]);
        });

        return back()->with('success', 'Ingredient added.');
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $this->normaliseCategoryInput($request);

        // Built from the INGREDIENT's branch, not from the request: the form
        // does not repost branch_id on an edit, and an unscoped rule would let a
        // category from another branch be attached here.
        $branchId = (int) $ingredient->branch_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:40', $this->uniqueSkuRule($branchId)->ignore($ingredient->id)],
            'unit' => ['required', 'in:g,kg,ml,l,pcs'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'ingredient_category_id' => ['sometimes', 'nullable', 'integer', $this->categoryExistsRule($branchId)],
            'new_category_name' => ['nullable', 'string', 'max:'.self::CATEGORY_NAME_MAX],
        ]);

        $attributes = Arr::except($validated, ['ingredient_category_id', 'new_category_name'])
            + ['is_active' => $request->boolean('is_active')];

        // Same atomicity as store(): an inline category is only real if the
        // ingredient write that asked for it survives.
        DB::transaction(function () use ($request, $ingredient, $attributes, $branchId, $validated): void {
            // "Key absent" and "explicit null" both reach the resolver as null,
            // so a partial update that names NEITHER key must leave the
            // assignment alone rather than silently clearing it.
            if ($request->has('ingredient_category_id') || filled($request->input('new_category_name'))) {
                $attributes['ingredient_category_id'] = $this->resolveCategory($branchId, $validated);
            }

            $ingredient->update($attributes);
        });

        return back()->with('success', 'Ingredient updated.');
    }

    /**
     * The picker's "— none —" option posts the empty STRING. `nullable|integer`
     * lets it through, and writing '' to an integer FK is a 500 on MySQL in
     * strict mode and silent garbage on SQLite, so it is normalised to null
     * BEFORE validation.
     */
    private function normaliseCategoryInput(Request $request): void
    {
        $merge = [];

        if ($request->input('ingredient_category_id') === '') {
            $merge['ingredient_category_id'] = null;
        }

        $newName = $request->input('new_category_name');
        if (is_string($newName)) {
            $merge['new_category_name'] = trim($newName) === '' ? null : trim($newName);
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * The ingredients table carries UNIQUE(branch_id, sku), so uniqueness is
     * per branch — two branches may stock the same SKU.
     */
    private function uniqueSkuRule(int $branchId): Unique
    {
        return Rule::unique('ingredients', 'sku')->where('branch_id', $branchId);
    }

    /**
     * Branch-scoped on purpose: a category is either this branch's own or a
     * shared one, and nothing else may be attached.
     */
    private function categoryExistsRule(int $branchId): Exists
    {
        return Rule::exists('ingredient_categories', 'id')
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'));
    }

    /**
     * Both surfaces go through the SAME resolver, so the web form and the phone
     * can never drift on precedence (a non-blank new name WINS over the picker).
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveCategory(int $branchId, array $validated): ?int
    {
        return CategoryResolver::resolve(
            $branchId,
            isset($validated['ingredient_category_id']) ? (int) $validated['ingredient_category_id'] : null,
            $validated['new_category_name'] ?? null,
        );
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        $ingredient->delete();

        return back()->with('success', 'Ingredient deleted.');
    }

    /**
     * The query string gives us string|null; the service is strictly typed and
     * treats null as "every branch".
     */
    private function branchId(mixed $raw): ?int
    {
        return (is_string($raw) || is_int($raw)) && is_numeric($raw) ? (int) $raw : null;
    }
}
