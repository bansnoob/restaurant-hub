<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a category write must NOT leave behind.
 *
 * The inline "+ New section" flow creates a category as a side effect of saving
 * an ingredient, so every one of these cases is the same question: when the
 * ingredient write fails, does the shelf it asked for survive? An empty shelf
 * nobody asked for shows up in the picker, the filter row, every count header
 * and every delete confirmation — created by a request that ERRORED.
 *
 * The validation cases are the other half: a malformed name must reach the
 * `string` rule as a field error, never as a 500.
 */
class CategoryWriteSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    public function test_a_duplicate_sku_is_a_field_error_and_leaves_no_orphan_category(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'sku' => 'ING0001']);

        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Chili Oil',
            'sku' => 'ING0001',
            'unit' => 'pcs',
            'current_stock' => 5,
            'reorder_level' => 1,
            'new_category_name' => 'Dry Store',
        ])->assertSessionHasErrors('sku');

        $this->assertDatabaseMissing('ingredients', ['name' => 'Chili Oil']);
        // The whole point: the failed save must not have left a shelf behind.
        $this->assertDatabaseMissing('ingredient_categories', ['name' => 'Dry Store']);
    }

    public function test_the_same_sku_is_free_in_another_branch_and_on_the_row_itself(): void
    {
        $other = Branch::factory()->create(['is_active' => true]);
        Ingredient::factory()->create(['branch_id' => $other->id, 'sku' => 'ING0001']);

        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Chili Oil', 'sku' => 'ING0001', 'unit' => 'pcs',
            'current_stock' => 5, 'reorder_level' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $mine = Ingredient::firstWhere('name', 'Chili Oil');

        // Re-saving a row with its OWN sku is not a duplicate.
        $this->actingAs($this->owner)->put(route('inventory.update', $mine), [
            'name' => 'Chili Oil', 'sku' => 'ING0001', 'unit' => 'pcs', 'reorder_level' => 2, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_stale_stock_guard_rolls_the_inline_category_back_too(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');
        Sanctum::actingAs($cashier);

        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 25,
            'ingredient_category_id' => null,
        ]);

        // The phone sends the inline category and the optimistic guard in ONE
        // PUT, so a 409 must unwind both.
        $this->putJson("/api/v1/inventory/ingredients/{$ingredient->id}", [
            'current_stock' => 12,
            'expected_current_stock' => 8,
            'new_category_name' => 'Walk-in',
        ])->assertStatus(409);

        $this->assertSame(25.0, (float) $ingredient->fresh()->current_stock);
        $this->assertNull($ingredient->fresh()->ingredient_category_id);
        $this->assertSame(0, IngredientCategory::where('name', 'Walk-in')->count());
    }

    public function test_a_non_scalar_name_is_a_validation_error_on_both_surfaces(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');
        Sanctum::actingAs($cashier);

        // A (string) cast before validation raises "Array to string conversion",
        // which Laravel promotes to an ErrorException — a 500 where the `string`
        // rule should have spoken.
        $this->postJson('/api/v1/inventory/categories', ['name' => ['a' => 'b']])->assertStatus(422);

        $own = IngredientCategory::factory()->create(['branch_id' => $this->branch->id]);
        $this->putJson("/api/v1/inventory/categories/{$own->id}", ['name' => ['x']])->assertStatus(422);
        $this->assertSame($own->name, $own->fresh()->name, 'a rejected rename must change nothing');

        $this->actingAs($this->owner)
            ->post(route('inventory.categories.store'), ['branch_id' => $this->branch->id, 'name' => ['a']])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)
            ->put(route('inventory.categories.update', $own), ['name' => ['a']])
            ->assertSessionHasErrors('name');
    }

    public function test_the_web_module_cannot_twin_a_shared_category(): void
    {
        $shared = IngredientCategory::create([
            'branch_id' => null, 'name' => 'Packaging', 'slug' => 'packaging',
            'sort_order' => 10, 'is_active' => true,
        ]);

        // Both rows would be resolvable by this branch, so the picker, the filter
        // row and the count headers would all show "Packaging" twice — and the
        // inline-create resolver would bind new ingredients to only one of them.
        $this->actingAs($this->owner)
            ->post(route('inventory.categories.store'), ['branch_id' => $this->branch->id, 'name' => 'Packaging'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, IngredientCategory::where('slug', 'packaging')->count());

        $mine = IngredientCategory::create([
            'branch_id' => $this->branch->id, 'name' => 'Dry Store', 'slug' => 'dry-store',
            'sort_order' => 20, 'is_active' => true,
        ]);

        // A RENAME into the same collision is refused for the same reason.
        $this->actingAs($this->owner)
            ->put(route('inventory.categories.update', $mine), ['name' => 'Packaging'])
            ->assertSessionHasErrors('slug');
        $this->assertSame('dry-store', $mine->fresh()->slug);

        $this->assertSame($shared->id, IngredientCategory::firstWhere('slug', 'packaging')->id);
    }

    public function test_an_inline_create_prefers_the_branchs_own_shelf_over_a_shared_twin(): void
    {
        $shared = IngredientCategory::create([
            'branch_id' => null, 'name' => 'Packaging', 'slug' => 'packaging',
            'sort_order' => 10, 'is_active' => true,
        ]);
        // A twin can only be inserted centrally now, but historic data may hold
        // one, and which shelf an ingredient lands on must not be driver luck.
        $own = IngredientCategory::create([
            'branch_id' => $this->branch->id, 'name' => 'Packaging', 'slug' => 'packaging',
            'sort_order' => 20, 'is_active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Take-out Bowls', 'unit' => 'pcs', 'current_stock' => 5, 'reorder_level' => 1,
            'new_category_name' => 'Packaging',
        ])->assertRedirect();

        $bowls = Ingredient::firstWhere('name', 'Take-out Bowls');
        $this->assertSame($own->id, $bowls->ingredient_category_id);
        $this->assertNotSame($shared->id, $bowls->ingredient_category_id);
    }
}
