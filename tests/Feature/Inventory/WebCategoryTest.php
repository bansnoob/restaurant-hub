<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The web module's half of inventory categories: the grouped browse list, the
 * category filter, the count walk the modal renders, the form's picker and
 * inline create, and the manager's CRUD + reorder.
 *
 * The count walk is the operational point of the whole feature — an owner counts
 * by where things are, not alphabetically — so the assertions below pin the
 * ORDER the server emits, not just the presence of a category name.
 */
class WebCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function category(string $name, int $sort, array $attrs = []): IngredientCategory
    {
        return IngredientCategory::create(array_merge([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sort,
            'is_active' => true,
        ], $attrs));
    }

    private function ingredient(array $attrs = []): Ingredient
    {
        return Ingredient::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'current_stock' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ], $attrs));
    }

    public function test_groups_arrive_in_walk_order_with_uncategorized_last(): void
    {
        $packaging = $this->category('Packaging', 50);
        $sauces = $this->category('Sauces', 10);
        $this->ingredient(['name' => 'Bowls', 'ingredient_category_id' => $packaging->id]);
        $this->ingredient(['name' => 'Shoyu', 'ingredient_category_id' => $sauces->id]);
        $this->ingredient(['name' => 'Mystery', 'ingredient_category_id' => null]);

        $response = $this->actingAs($this->owner)->get(route('inventory.index'))->assertOk();
        $groups = $response->viewData('groups');

        $this->assertSame(['Sauces', 'Packaging', 'Uncategorized'], array_column($groups, 'name'));
        $html = $response->getContent();
        $this->assertStringContainsString('Uncategorized', $html);
        $this->assertStringContainsString('rh-inv-group-head', $html);
        $this->assertStringContainsString('openCategories()', $html);
        $this->assertStringContainsString('categories:', $html);

    }

    public function test_the_category_filter_narrows_and_none_returns_the_tail(): void
    {
        $sauces = $this->category('Sauces', 10);
        $this->ingredient(['name' => 'Shoyu', 'ingredient_category_id' => $sauces->id]);
        $this->ingredient(['name' => 'Mystery']);

        $filtered = $this->actingAs($this->owner)
            ->get(route('inventory.index', ['category_id' => $sauces->id]))->assertOk();
        $this->assertSame(['Shoyu'], $filtered->viewData('ingredients')->pluck('name')->all());

        $tail = $this->actingAs($this->owner)
            ->get(route('inventory.index', ['category_id' => 'none']))->assertOk();
        $this->assertSame(['Mystery'], $tail->viewData('ingredients')->pluck('name')->all());

        // A deleted / foreign id is dropped rather than emptying the list.
        $stale = $this->actingAs($this->owner)
            ->get(route('inventory.index', ['category_id' => 9999]))->assertOk();
        $this->assertCount(2, $stale->viewData('ingredients'));
        $this->assertSame('', $stale->viewData('filters')['category_id']);
    }

    public function test_start_count_carries_the_category_and_walks_in_order(): void
    {
        $packaging = $this->category('Packaging', 50);
        $sauces = $this->category('Sauces', 10);
        $this->ingredient(['name' => 'Bowls', 'ingredient_category_id' => $packaging->id]);
        $this->ingredient(['name' => 'Shoyu', 'ingredient_category_id' => $sauces->id]);
        $this->ingredient(['name' => 'Mystery']);

        $rows = $this->actingAs($this->owner)
            ->getJson(route('inventory.counts.start', ['branch_id' => $this->branch->id]))
            ->assertOk()->json('ingredients');

        $this->assertSame(['Shoyu', 'Bowls', 'Mystery'], array_column($rows, 'name'));
        $this->assertSame(['Sauces', 'Packaging', null], array_column($rows, 'category_name'));
        $this->assertArrayHasKey('ingredient_category_id', $rows[0]);
        $this->assertArrayHasKey('category_sort_order', $rows[0]);
    }

    public function test_the_web_form_creates_reuses_and_clears_a_category(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Chili Oil', 'unit' => 'pcs', 'current_stock' => 5, 'reorder_level' => 1,
            'ingredient_category_id' => '', 'new_category_name' => 'Dry store',
        ])->assertRedirect();

        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Shoyu', 'unit' => 'pcs', 'current_stock' => 5, 'reorder_level' => 1,
            'ingredient_category_id' => '', 'new_category_name' => 'Dry store',
        ])->assertRedirect();

        $this->assertSame(1, IngredientCategory::where('name', 'Dry store')->count());
        $created = IngredientCategory::firstWhere('name', 'Dry store');
        $shoyu = Ingredient::firstWhere('name', 'Shoyu');
        $this->assertSame($created->id, $shoyu->ingredient_category_id);

        // Clearing: '' posts, the controller normalises it to null.
        $this->actingAs($this->owner)->put(route('inventory.update', $shoyu), [
            'name' => 'Shoyu', 'unit' => 'pcs', 'reorder_level' => 1, 'is_active' => 1,
            'ingredient_category_id' => '',
        ])->assertRedirect();
        $this->assertNull($shoyu->fresh()->ingredient_category_id);

        // A partial update that names neither key leaves the assignment alone.
        $shoyu = $shoyu->fresh();
        $shoyu->update(['ingredient_category_id' => $created->id]);
        $this->assertSame($created->id, $shoyu->fresh()->ingredient_category_id, 'setup did not stick');
        $this->actingAs($this->owner)->put(route('inventory.update', $shoyu), [
            'name' => 'Shoyu', 'unit' => 'pcs', 'reorder_level' => 2, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame($created->id, $shoyu->fresh()->ingredient_category_id);
    }

    public function test_a_foreign_category_is_rejected_on_the_web_form(): void
    {
        $other = Branch::factory()->create(['is_active' => true]);
        $foreign = IngredientCategory::create([
            'branch_id' => $other->id, 'name' => 'Theirs', 'slug' => 'theirs', 'sort_order' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Shoyu', 'unit' => 'pcs', 'current_stock' => 5, 'reorder_level' => 1,
            'ingredient_category_id' => $foreign->id,
        ])->assertSessionHasErrors('ingredient_category_id');
    }

    public function test_category_crud_and_reorder(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.categories.store'), [
            'branch_id' => $this->branch->id, 'name' => 'Dry store',
        ])->assertRedirect()->assertSessionHas('categories_open');

        $this->actingAs($this->owner)->post(route('inventory.categories.store'), [
            'branch_id' => $this->branch->id, 'name' => 'dry store',
        ])->assertSessionHasErrors('slug');

        $a = IngredientCategory::firstWhere('name', 'Dry store');
        $this->actingAs($this->owner)->post(route('inventory.categories.store'), [
            'branch_id' => $this->branch->id, 'name' => 'Walk-in',
        ])->assertRedirect();
        $b = IngredientCategory::firstWhere('name', 'Walk-in');
        $this->assertGreaterThan($a->sort_order, $b->sort_order);

        $this->actingAs($this->owner)->put(route('inventory.categories.update', $a), ['name' => 'Dry Store 2'])
            ->assertRedirect();
        $this->assertSame('dry-store-2', $a->fresh()->slug);

        $this->actingAs($this->owner)->put(route('inventory.categories.update', $a), ['is_active' => '0'])
            ->assertRedirect();
        $this->assertFalse($a->fresh()->is_active);

        $this->actingAs($this->owner)->post(route('inventory.categories.reorder'), [
            'branch_id' => $this->branch->id,
            'categories' => [['id' => $b->id, 'sort_order' => 10], ['id' => $a->id, 'sort_order' => 20]],
        ])->assertRedirect();
        $this->assertSame(10, $b->fresh()->sort_order);
        $this->assertSame(20, $a->fresh()->sort_order);

        // Deleting frees the ingredients; no stock row is touched.
        $ing = $this->ingredient(['name' => 'Shoyu', 'ingredient_category_id' => $a->id]);
        $this->actingAs($this->owner)->delete(route('inventory.categories.destroy', $a))->assertRedirect();
        $this->assertNull($ing->fresh()->ingredient_category_id);
        $this->assertDatabaseHas('ingredients', ['id' => $ing->id]);
    }

    public function test_shared_categories_are_read_only_and_foreign_reorders_bounce(): void
    {
        $shared = IngredientCategory::create([
            'branch_id' => null, 'name' => 'Shared', 'slug' => 'shared', 'sort_order' => 10, 'is_active' => true,
        ]);
        $this->actingAs($this->owner)->put(route('inventory.categories.update', $shared), ['name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($this->owner)->delete(route('inventory.categories.destroy', $shared))->assertForbidden();

        $this->actingAs($this->owner)->post(route('inventory.categories.reorder'), [
            'branch_id' => $this->branch->id,
            'categories' => [['id' => $shared->id, 'sort_order' => 10]],
        ])->assertSessionHasErrors('categories.0.id');
    }

    public function test_show_ingredient_and_show_count_expose_the_category(): void
    {
        $sauces = $this->category('Sauces', 10);
        $ing = $this->ingredient(['name' => 'Shoyu', 'ingredient_category_id' => $sauces->id]);

        $payload = $this->actingAs($this->owner)->getJson(route('inventory.show', $ing))->assertOk()->json();
        $this->assertSame($sauces->id, $payload['ingredient']['ingredient_category_id']);
        $this->assertSame('Sauces', $payload['ingredient']['category_name']);

        $count = app(InventoryService::class)->recordCount(
            $this->branch->id, now()->toDateString(), [$ing->id => ['counted' => 90]], null, null, $this->owner
        );
        $detail = $this->actingAs($this->owner)->getJson(route('inventory.counts.show', $count))->assertOk()->json();
        $this->assertSame('Sauces', $detail['entries'][0]['category_name']);
    }

    public function test_the_page_renders_with_no_categories_and_no_ingredients(): void
    {
        $this->actingAs($this->owner)->get(route('inventory.index'))->assertOk();
    }
}
