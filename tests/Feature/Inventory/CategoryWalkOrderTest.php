<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Inventory\CategorySlug;
use App\Support\Inventory\IngredientView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The walk order itself, and the one invariant the whole feature is built to
 * protect: a category is a HEADER, never a filter.
 *
 * An ingredient must never disappear from a list or, far worse, from a count
 * session because of anything that happened to its category — being
 * uncategorised, having its category deactivated, or having it deleted out
 * from under it. A shortened walk is stock that silently stops being counted.
 *
 * Fixture names are deliberately distinct in their FIRST letter and uniform in
 * case: SQLite's default BINARY collation sorts 'NOODLES' before 'noodles'
 * while MySQL's utf8mb4_*_ci does not, so an assertion over mixed-case names
 * would pass on production and fail here.
 */
class CategoryWalkOrderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('cashier');
        $this->inventory = app(InventoryService::class);
    }

    public function test_a_count_session_walks_by_sort_order_then_name_with_the_uncategorized_tail_last(): void
    {
        // Deliberately created out of walk order, and the alphabetically first
        // name ('Apron') is parked in the LAST shelf so a stray orderBy('name')
        // cannot pass this test by accident.
        $packaging = $this->category('Packaging', 50);
        $sauces = $this->category('Sauces', 10);

        $this->ingredient('Apron', $packaging);
        $this->ingredient('Shoyu', $sauces);
        $this->ingredient('Chili Oil', $sauces);
        $this->ingredient('Mystery Powder', null);

        $rows = $this->inventory->buildCountSession($this->branch->id)->rows;

        $this->assertSame(
            ['Chili Oil', 'Shoyu', 'Apron', 'Mystery Powder'],
            array_map(fn ($row): string => $row->name, $rows),
            'sort_order first, then name within a shelf, then the uncategorized tail'
        );
        $this->assertSame(
            ['Sauces', 'Sauces', 'Packaging', null],
            array_map(fn ($row): ?string => $row->categoryName, $rows)
        );
        $this->assertSame([10, 10, 50, null], array_map(fn ($row): ?int => $row->categorySortOrder, $rows));
    }

    public function test_an_uncategorized_ingredient_appears_in_both_the_list_and_the_count(): void
    {
        $sauces = $this->category('Sauces', 10);
        $this->ingredient('Shoyu', $sauces);
        $orphan = $this->ingredient('Mystery Powder', null);

        // listIngredients hands back IngredientView objects, not models.
        $listed = $this->listedNames();
        $this->assertContains('Mystery Powder', $listed, 'no category is not a reason to vanish');
        $this->assertSame('Mystery Powder', end($listed), 'and it sorts into the trailing tail');
        $this->assertNotNull($orphan->id);

        $rows = $this->inventory->buildCountSession($this->branch->id)->rows;
        $this->assertSame([$orphan->id], array_values(array_map(
            fn ($row): int => $row->ingredientId,
            array_filter($rows, fn ($row): bool => $row->ingredientCategoryId === null)
        )));
    }

    public function test_deleting_a_category_leaves_its_ingredients_in_the_list_and_the_count(): void
    {
        $sauces = $this->category('Sauces', 10);
        $shoyu = $this->ingredient('Shoyu', $sauces);
        $stockBefore = $shoyu->current_stock;

        Sanctum::actingAs($this->cashier);
        $this->deleteJson("/api/v1/inventory/categories/{$sauces->id}")
            ->assertOk()
            ->assertJsonPath('data.uncategorized_count', 1);

        $this->assertDatabaseHas('ingredients', ['id' => $shoyu->id, 'ingredient_category_id' => null]);
        $this->assertSame($stockBefore, $shoyu->fresh()->current_stock, 'no stock row is ever touched');

        $this->assertContains('Shoyu', $this->listedNames());
        $this->assertContains($shoyu->id, array_map(
            fn ($row): int => $row->ingredientId,
            $this->inventory->buildCountSession($this->branch->id)->rows
        ));
    }

    public function test_deactivating_a_category_never_removes_its_ingredients_from_a_count(): void
    {
        $sauces = $this->category('Sauces', 10);
        $shoyu = $this->ingredient('Shoyu', $sauces);

        Sanctum::actingAs($this->cashier);
        $this->putJson("/api/v1/inventory/categories/{$sauces->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // The shelf is retired from the PICKERS, not from the stockroom.
        $this->assertSame([], $this->inventory->listCategories($this->branch->id)->pluck('name')->all());
        $this->assertSame(['Sauces'], $this->inventory->listCategories($this->branch->id, true)->pluck('name')->all());

        $rows = $this->inventory->buildCountSession($this->branch->id)->rows;
        $this->assertSame([$shoyu->id], array_map(fn ($row): int => $row->ingredientId, $rows));
        $this->assertSame('Sauces', $rows[0]->categoryName, 'an inactive shelf still labels the row it holds');

        $this->assertContains('Shoyu', $this->listedNames());
    }

    public function test_an_inline_new_category_name_is_reused_rather_than_duplicated(): void
    {
        Sanctum::actingAs($this->cashier);

        foreach (['Shoyu', 'Miso Paste', 'Katsu Sauce'] as $index => $name) {
            // Same shelf, spelled three different ways — the kind of thing that
            // happens when three people type it on three phones.
            $spelling = ['Dry Store', 'dry store', '  Dry   Store  '][$index];

            $this->postJson('/api/v1/inventory/ingredients', [
                'name' => $name, 'unit' => 'pcs', 'current_stock' => 1, 'reorder_level' => 1,
                'new_category_name' => $spelling,
            ])->assertStatus(201);
        }

        $this->assertSame(1, IngredientCategory::count(), 'three spellings, one shelf');
        $this->assertSame(
            3,
            IngredientCategory::first()->ingredients()->count(),
            'and all three ingredients land on it'
        );
    }

    public function test_an_inline_name_reuses_a_category_the_owner_already_made(): void
    {
        $existing = $this->category('Dry Store', 70);

        Sanctum::actingAs($this->cashier);
        $created = $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Shoyu', 'unit' => 'pcs', 'current_stock' => 1, 'reorder_level' => 1,
            'new_category_name' => 'DRY STORE',
        ])->assertStatus(201)->json('data');

        $this->assertSame($existing->id, $created['ingredient_category_id']);
        $this->assertSame(1, IngredientCategory::count());
        $this->assertSame(70, $existing->fresh()->sort_order, "the owner's walk position survives a reuse");
    }

    public function test_a_duplicate_name_is_a_422_and_not_a_500(): void
    {
        $this->category('Dry Store', 10);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/categories', ['name' => 'Dry Store'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug')
            ->assertJsonPath('errors.slug.0', 'A category with that name already exists in this branch.');

        // A different CASE still slugs to the same shelf, so it collides too.
        $this->postJson('/api/v1/inventory/categories', ['name' => 'dry store'])->assertStatus(422);

        $this->assertSame(1, IngredientCategory::count());
    }

    public function test_a_rename_onto_an_existing_name_is_a_422_but_renaming_itself_is_fine(): void
    {
        $this->category('Dry Store', 10);
        $chiller = $this->category('Chiller', 20);

        Sanctum::actingAs($this->cashier);

        $this->putJson("/api/v1/inventory/categories/{$chiller->id}", ['name' => 'Dry Store'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // ->ignore($id) — a category may always be renamed to what it already is.
        $this->putJson("/api/v1/inventory/categories/{$chiller->id}", ['name' => 'Chiller'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Chiller');
    }

    public function test_a_sort_order_out_of_range_is_a_422_and_not_a_database_error(): void
    {
        Sanctum::actingAs($this->cashier);

        // unsignedSmallInteger: MySQL raises 1264 out-of-range, SQLite stores
        // it happily. Both are wrong answers; validation must catch it first.
        $this->postJson('/api/v1/inventory/categories', ['name' => 'Too Far', 'sort_order' => 70000])
            ->assertStatus(422)->assertJsonValidationErrors('sort_order');

        $this->postJson('/api/v1/inventory/categories', ['name' => 'Backwards', 'sort_order' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('sort_order');

        $this->postJson('/api/v1/inventory/categories', ['name' => 'Wordy', 'sort_order' => 'first'])
            ->assertStatus(422)->assertJsonValidationErrors('sort_order');

        $this->postJson('/api/v1/inventory/categories', ['name' => str_repeat('x', 101)])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/inventory/categories', ['name' => '  '])
            ->assertStatus(422);

        $this->assertSame(0, IngredientCategory::count());
    }

    public function test_a_reorder_naming_another_branchs_category_is_a_422_and_renumbers_nothing(): void
    {
        $mine = $this->category('Sauces', 10);
        $foreign = IngredientCategory::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'sort_order' => 10,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/categories/reorder', ['categories' => [
            ['id' => $mine->id, 'sort_order' => 20],
            ['id' => $foreign->id, 'sort_order' => 10],
        ]])->assertStatus(422);

        $this->assertSame(10, $mine->fresh()->sort_order, 'a rejected reorder is all-or-nothing');
        $this->assertSame(10, $foreign->fresh()->sort_order);
    }

    public function test_a_cashier_never_reads_another_branchs_shelves(): void
    {
        $mine = $this->category('Sauces', 10);
        $other = Branch::factory()->create();
        IngredientCategory::factory()->create(['branch_id' => $other->id, 'name' => 'Their Shelf']);
        $shared = IngredientCategory::factory()->shared()->create(['name' => 'Shared Shelf']);

        Sanctum::actingAs($this->cashier);

        $listed = $this->getJson('/api/v1/inventory/categories?include_inactive=1')->assertOk()->json('data');
        $this->assertSame(
            [$mine->id, $shared->id],
            collect($listed)->pluck('id')->sort()->values()->all(),
            'own categories plus the shared ones, and nothing else'
        );

        // Naming another branch is refused outright rather than quietly ignored.
        $this->getJson("/api/v1/inventory/categories?branch_id={$other->id}")->assertForbidden();
        $this->postJson('/api/v1/inventory/categories', ['name' => 'Theirs', 'branch_id' => $other->id])
            ->assertForbidden();
    }

    public function test_the_category_filter_never_hides_a_row_from_the_count(): void
    {
        $sauces = $this->category('Sauces', 10);
        $this->ingredient('Shoyu', $sauces);
        $this->ingredient('Mystery Powder', null);

        // Filtering is a BROWSE concern...
        $this->assertSame(['Shoyu'], $this->listedNames(['category_id' => $sauces->id]));
        $this->assertSame(['Mystery Powder'], $this->listedNames(['category_id' => 'none']));

        // ...and it must never leak into the walk.
        $this->assertCount(2, $this->inventory->buildCountSession($this->branch->id)->rows);
    }

    /**
     * The names listIngredients returns, in the order it returns them.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function listedNames(array $filters = []): array
    {
        return $this->inventory->listIngredients($this->branch->id, $filters)
            ->map(fn (IngredientView $view): string => $view->ingredient->name)
            ->all();
    }

    private function category(string $name, int $sortOrder): IngredientCategory
    {
        return IngredientCategory::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'slug' => CategorySlug::for($name),
            'sort_order' => $sortOrder,
        ]);
    }

    private function ingredient(string $name, ?IngredientCategory $category): Ingredient
    {
        return Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'unit' => 'pcs',
            'current_stock' => 10,
            'reorder_level' => 2,
            'ingredient_category_id' => $category?->id,
        ]);
    }
}
