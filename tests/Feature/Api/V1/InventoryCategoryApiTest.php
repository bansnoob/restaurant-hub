<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('cashier');
        Sanctum::actingAs($this->cashier);
    }

    public function test_mobile_payloads_round_trip(): void
    {
        // --- createIngredientCategory({ name }) : the mobile payload verbatim
        $created = $this->postJson('/api/v1/inventory/categories', ['name' => 'Dry Store'])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'id', 'branch_id', 'name', 'slug', 'sort_order', 'is_active', 'ingredient_count',
            ]])->json('data');
        $this->assertSame(10, $created['sort_order'], 'first category appends at step 10');
        $this->assertIsInt($created['ingredient_count']);
        $this->assertIsBool($created['is_active']);

        $second = $this->postJson('/api/v1/inventory/categories', ['name' => 'Walk-in'])
            ->assertStatus(201)->json('data');
        $this->assertSame(20, $second['sort_order'], 'new shelf lands at the END of the walk');

        // --- getIngredientCategories({ include_inactive: true }) -> '1'
        $this->getJson('/api/v1/inventory/categories?include_inactive=1')
            ->assertOk()->assertJsonCount(2, 'data');

        // --- createIngredient with ingredient_category_id (picker path)
        $ing = $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Shoyu', 'unit' => 'pcs', 'current_stock' => 5, 'reorder_level' => 1,
            'ingredient_category_id' => $created['id'],
        ])->assertStatus(201)->json('data');
        $this->assertSame($created['id'], $ing['ingredient_category_id']);
        $this->assertSame(['id', 'name', 'slug', 'sort_order'], array_keys($ing['category']));
        $this->assertIsFloat($ing['current_stock'] + 0.0);
        $this->assertIsNumeric($ing['current_stock']);
        $this->assertNotIsString($ing['current_stock']);

        // --- createIngredient with new_category_name (inline create; WINS)
        $ing2 = $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Miso Paste', 'unit' => 'pcs', 'current_stock' => 2, 'reorder_level' => 1,
            'new_category_name' => 'Chiller',
        ])->assertStatus(201)->json('data');
        $this->assertSame('Chiller', $ing2['category']['name']);

        // reuse, not duplicate
        $ing3 = $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Kikoman', 'unit' => 'pcs', 'current_stock' => 2, 'reorder_level' => 1,
            'new_category_name' => 'chiller',
        ])->assertStatus(201)->json('data');
        $this->assertSame($ing2['ingredient_category_id'], $ing3['ingredient_category_id'], 'inline create reuses by slug');

        // --- partial update must NOT clear the category
        $this->putJson("/api/v1/inventory/ingredients/{$ing['id']}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.ingredient_category_id', $created['id']);

        // --- explicit null CLEARS
        $this->putJson("/api/v1/inventory/ingredients/{$ing['id']}", ['ingredient_category_id' => null])
            ->assertOk()->assertJsonPath('data.ingredient_category_id', null)
            ->assertJsonPath('data.category', null);

        // --- reorder payload from resequence()
        $this->postJson('/api/v1/inventory/categories/reorder', ['categories' => [
            ['id' => $second['id'], 'sort_order' => 10],
            ['id' => $created['id'], 'sort_order' => 20],
        ]])->assertOk()->assertJsonPath('data.0.id', $second['id']);

        // --- delete returns { data: { id, uncategorized_count } }
        $this->deleteJson("/api/v1/inventory/categories/{$ing2['ingredient_category_id']}")
            ->assertOk()->assertJsonPath('data.uncategorized_count', 2);
        $this->assertDatabaseHas('ingredients', ['id' => $ing2['id'], 'ingredient_category_id' => null]);

        // --- count session carries the three flat scalars
        $row = $this->getJson('/api/v1/inventory/counts/start')->assertOk()->json('data.rows.0');
        $this->assertArrayHasKey('ingredient_category_id', $row);
        $this->assertArrayHasKey('category_name', $row);
        $this->assertArrayHasKey('category_sort_order', $row);
    }

    public function test_a_cashier_cannot_touch_another_branchs_category(): void
    {
        $other = Branch::factory()->create();
        $foreign = IngredientCategory::factory()->create(['branch_id' => $other->id]);

        $this->putJson("/api/v1/inventory/categories/{$foreign->id}", ['name' => 'Stolen'])->assertForbidden();
        $this->deleteJson("/api/v1/inventory/categories/{$foreign->id}")->assertForbidden();
        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'X', 'unit' => 'pcs', 'current_stock' => 1, 'reorder_level' => 1,
            'ingredient_category_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_a_shared_category_is_read_only_and_counts_only_this_branch(): void
    {
        $shared = IngredientCategory::factory()->create(['branch_id' => null, 'name' => 'Shared Shelf']);
        $other = Branch::factory()->create();
        Ingredient::factory()->count(3)->create(['branch_id' => $other->id, 'ingredient_category_id' => $shared->id]);
        Ingredient::factory()->count(1)->create(['branch_id' => $this->branch->id, 'ingredient_category_id' => $shared->id]);

        $this->putJson("/api/v1/inventory/categories/{$shared->id}", ['is_active' => false])->assertForbidden();

        $row = collect($this->getJson('/api/v1/inventory/categories')->assertOk()->json('data'))
            ->firstWhere('id', $shared->id);
        $this->assertSame(1, $row['ingredient_count'], 'ingredient_count must be branch-scoped, never cross-branch');
        $this->assertFalse($row['is_editable']);
    }

    private function assertNotIsString(mixed $v): void
    {
        $this->assertFalse(is_string($v), 'numeric must not arrive as a decimal: string');
    }
}
