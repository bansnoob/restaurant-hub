<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryIngredientTest extends TestCase
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
    }

    public function test_summary_returns_the_documented_shape(): void
    {
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 1,
            'reorder_level' => 5,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'branch_id', 'branch_name', 'total_items', 'active_items',
                'low_stock_count', 'last_count_at', 'days_since_last_count',
                'counts_this_month',
            ]])
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.low_stock_count', 1);
    }

    public function test_ingredient_list_emits_numbers_not_decimal_strings(): void
    {
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 12.5,
            'reorder_level' => 5,
            'cost_per_unit' => 1.25,
        ]);

        Sanctum::actingAs($this->cashier);

        $row = $this->getJson('/api/v1/inventory/ingredients')
            ->assertOk()
            ->json('data.0');

        // Ingredient's decimal: casts return STRINGS ("12.500"); the resource
        // must cast to float or the mobile `number` types silently receive
        // strings. (A whole-number float encodes as `5`, which is the same
        // Number in JS — so assert "not a string", not "is a float".)
        foreach (['current_stock', 'reorder_level', 'cost_per_unit', 'pending_restock'] as $key) {
            $this->assertIsNotString($row[$key], "{$key} leaked a decimal-cast string");
            $this->assertIsNumeric($row[$key]);
        }
        $this->assertIsBool($row['is_active']);
        $this->assertIsBool($row['is_low_stock']);
        $this->assertSame(12.5, $row['current_stock']);

        // Shape is invariant: nullable, never absent.
        foreach (['daily_consumption', 'days_remaining', 'last_counted_at'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_list_is_hard_scoped_to_the_callers_branch(): void
    {
        $other = Branch::factory()->create();
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Mine']);
        Ingredient::factory()->create(['branch_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($this->cashier);

        $names = collect($this->getJson('/api/v1/inventory/ingredients')->json('data'))
            ->pluck('name');

        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_a_cashier_cannot_write_into_another_branch_by_posting_branch_id(): void
    {
        $other = Branch::factory()->create();

        Sanctum::actingAs($this->cashier);

        // A foreign branch_id from a non-owner is refused outright rather than
        // quietly rewritten to the caller's own branch: the client would
        // otherwise believe it had written where it asked.
        $this->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $other->id,
            'name' => 'Chicken thigh',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 5,
        ])->assertForbidden();

        $this->assertDatabaseMissing('ingredients', ['name' => 'Chicken thigh']);
    }

    public function test_a_cashiers_own_branch_id_in_the_body_is_accepted_and_never_wins_over_the_resolved_one(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $this->branch->id,
            'name' => 'Chicken thigh',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 5,
        ])->assertCreated();

        $this->assertSame($this->branch->id, $response->json('data.branch_id'));
        $this->assertDatabaseHas('ingredients', [
            'name' => 'Chicken thigh',
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_creating_an_ingredient_writes_no_movement(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice', 'unit' => 'kg', 'current_stock' => 40, 'reorder_level' => 10,
        ])->assertCreated();

        // The opening current_stock IS the baseline.
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_an_empty_sku_is_normalised_to_null_so_two_rows_do_not_collide(): void
    {
        Sanctum::actingAs($this->cashier);

        foreach (['Salt', 'Pepper'] as $name) {
            $this->postJson('/api/v1/inventory/ingredients', [
                'name' => $name, 'sku' => '', 'unit' => 'g',
                'current_stock' => 1, 'reorder_level' => 1,
            ])->assertCreated();
        }

        $this->assertSame(2, Ingredient::whereNull('sku')->count());
    }

    public function test_changing_current_stock_records_exactly_one_adjustment_movement(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 10,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->putJson("/api/v1/inventory/ingredients/{$ingredient->id}", [
            'current_stock' => 7,
            'expected_current_stock' => 10,
        ])->assertOk();

        $this->assertSame(7.0, (float) $ingredient->fresh()->current_stock);

        $movements = InventoryMovement::all();
        $this->assertCount(1, $movements);
        $this->assertSame('adjustment', $movements[0]->movement_type);
        $this->assertSame('out', $movements[0]->direction);
        $this->assertSame(3.0, (float) $movements[0]->quantity);
    }

    public function test_a_name_only_edit_writes_no_movement(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 10,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->putJson("/api/v1/inventory/ingredients/{$ingredient->id}", [
            'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(10.0, (float) $ingredient->fresh()->current_stock);
    }

    public function test_a_stale_expected_current_stock_is_rejected_with_409(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 25,
        ]);

        Sanctum::actingAs($this->cashier);

        // The client last saw 10; another device has since restocked to 25.
        $this->putJson("/api/v1/inventory/ingredients/{$ingredient->id}", [
            'current_stock' => 10,
            'expected_current_stock' => 10,
        ])->assertStatus(409);

        $this->assertSame(25.0, (float) $ingredient->fresh()->current_stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_delete_deactivates_and_returns_the_row(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->deleteJson("/api/v1/inventory/ingredients/{$ingredient->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id, 'is_active' => false]);
    }

    public function test_a_cashier_cannot_touch_another_branchs_ingredient(): void
    {
        $other = Branch::factory()->create();
        $foreign = Ingredient::factory()->create(['branch_id' => $other->id]);

        Sanctum::actingAs($this->cashier);

        $this->getJson("/api/v1/inventory/ingredients/{$foreign->id}")->assertForbidden();
        $this->putJson("/api/v1/inventory/ingredients/{$foreign->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/inventory/ingredients/{$foreign->id}")->assertForbidden();
    }

    public function test_a_cashier_linked_only_through_an_employee_record_is_still_scoped(): void
    {
        $user = User::factory()->create(['branch_id' => null]);
        $user->assignRole('cashier');
        Employee::factory()->create(['user_id' => $user->id, 'branch_id' => $this->branch->id]);

        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Mine']);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/inventory/ingredients')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Mine');

        // UserResource must report the RESOLVED branch, otherwise the mobile
        // cache (keyed on branch_id) is dead for exactly these users.
        // /auth/me is NOT {data:...} wrapped — it returns {user, employee}.
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.branch_id', $this->branch->id);
    }

    /**
     * store() resolves branch_id before validating it. Without an `exists` rule
     * an owner's unchecked id reached Ingredient::create and the foreign key
     * blew up as a 500 instead of a 422.
     */
    public function test_store_rejects_a_branch_id_that_does_not_exist(): void
    {
        $owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $owner->assignRole('owner');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => 99999,
            'name' => 'Ghost branch item',
            'unit' => 'kg',
            'current_stock' => 1,
            'reorder_level' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');

        // A non-integer casts to 0 under $request->integer(); it must 422 too.
        $this->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => 'abc',
            'name' => 'Bad branch item',
            'unit' => 'kg',
            'current_stock' => 1,
            'reorder_level' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseMissing('ingredients', ['name' => 'Ghost branch item']);
    }

    public function test_the_detail_endpoint_nests_ingredient_and_history(): void
    {
        $ingredient = Ingredient::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($this->cashier);

        $this->getJson("/api/v1/inventory/ingredients/{$ingredient->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['ingredient' => ['id', 'current_stock'], 'history']])
            ->assertJsonPath('data.ingredient.id', $ingredient->id)
            ->assertJsonPath('data.history', []);
    }
}
