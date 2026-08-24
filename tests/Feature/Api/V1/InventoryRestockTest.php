<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryRestockTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('cashier');
        $this->ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 10,
            'reorder_level' => 5,
            'is_active' => true,
        ]);
    }

    public function test_a_restock_bumps_stock_and_writes_an_unclaimed_purchase_movement(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 4.5,
        ])->assertCreated();

        $response->assertJsonStructure(['data' => [
            'movement' => [
                'id', 'branch_id', 'ingredient_id', 'direction', 'movement_type',
                'quantity', 'unit_cost', 'notes', 'moved_at', 'created_at',
            ],
            'ingredient' => ['id', 'current_stock', 'pending_restock'],
        ]]);

        $response->assertJsonPath('data.movement.direction', 'in');
        $response->assertJsonPath('data.movement.movement_type', 'purchase');
        $response->assertJsonPath('data.movement.branch_id', $this->branch->id);

        // The response must already carry the bumped stock and the pending sum,
        // so the client can replace the row without a refetch.
        $this->assertSame(14.5, (float) $response->json('data.ingredient.current_stock'));
        $this->assertSame(4.5, (float) $response->json('data.ingredient.pending_restock'));

        $movement = InventoryMovement::sole();
        $this->assertNull($movement->reference_type, 'a fresh restock must be UNCLAIMED');
        $this->assertNull($movement->reference_id);
        $this->assertSame($this->cashier->id, $movement->created_by_user_id);
        $this->assertNotNull($movement->moved_at);
        $this->assertSame(14.5, (float) $this->ingredient->fresh()->current_stock);
    }

    public function test_the_stock_bump_and_the_movement_are_one_atomic_pair(): void
    {
        Sanctum::actingAs($this->cashier);

        foreach ([2, 3] as $qty) {
            $this->postJson('/api/v1/inventory/restocks', [
                'ingredient_id' => $this->ingredient->id,
                'quantity' => $qty,
            ])->assertCreated();
        }

        $this->assertSame(2, InventoryMovement::count());
        $this->assertSame(15.0, (float) $this->ingredient->fresh()->current_stock);
    }

    public function test_an_inactive_ingredient_cannot_be_restocked(): void
    {
        $this->ingredient->update(['is_active' => false]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('message', 'This ingredient is inactive.');

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_cashier_cannot_restock_another_branchs_ingredient(): void
    {
        $foreign = Ingredient::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $foreign->id,
            'quantity' => 1,
        ])->assertForbidden();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_quantity_is_validated_at_the_boundary(): void
    {
        Sanctum::actingAs($this->cashier);

        // Zero / negative are not deliveries.
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id, 'quantity' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        // A 4th decimal exceeds decimal(14,3).
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id, 'quantity' => 1.2345,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_restock_that_would_overflow_the_column_is_rejected_not_500(): void
    {
        $this->ingredient->update(['current_stock' => 99999999999.000]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 1000,
        ])->assertStatus(422);

        $this->assertSame(0, InventoryMovement::count());
    }
}
