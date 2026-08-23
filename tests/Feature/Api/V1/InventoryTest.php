<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end coverage for the mobile inventory API.
 *
 * The centrepiece is test_a_restock_between_two_counts_is_not_read_as_consumption:
 * the whole restock/claim design in App\Services\Inventory\MovementLedger exists
 * to make that one sequence of numbers come out right.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create(['name' => 'Main']);

        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('cashier');
        Employee::factory()->create([
            'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    // =====================================================================
    // Listing + payload shape
    // =====================================================================

    public function test_an_owner_and_a_cashier_can_both_list_ingredients(): void
    {
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Chicken thigh',
            'sku' => 'CHK-01',
            'unit' => 'kg',
            'current_stock' => 12.5,
            'reorder_level' => 5,
            'cost_per_unit' => 0,
        ]);

        foreach ([$this->owner, $this->cashier] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson('/api/v1/inventory/ingredients')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonStructure([
                    'data' => [[
                        'id', 'branch_id', 'name', 'sku', 'unit', 'current_stock',
                        'reorder_level', 'cost_per_unit', 'is_active', 'is_low_stock',
                        'daily_consumption', 'days_remaining', 'last_counted_at',
                        'pending_restock',
                    ]],
                ])
                ->assertJsonPath('data.0.name', 'Chicken thigh')
                ->assertJsonPath('data.0.sku', 'CHK-01');
        }
    }

    public function test_numeric_ingredient_fields_are_numbers_not_strings(): void
    {
        // Ingredient's `decimal:3` / `decimal:4` casts return STRINGS; the
        // resource has to cast them back or the mobile `number` types are lies.
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 12.5,
            'reorder_level' => 5,
            'cost_per_unit' => 1.25,
        ]);

        Sanctum::actingAs($this->cashier);

        $row = $this->getJson('/api/v1/inventory/ingredients')->assertOk()->json('data.0');

        foreach (['current_stock', 'reorder_level', 'cost_per_unit', 'pending_restock'] as $key) {
            $this->assertIsNotString($row[$key], "{$key} must be a JSON number, not a string.");
        }

        $this->assertEqualsWithDelta(12.5, $row['current_stock'], 0.0001);
        $this->assertEqualsWithDelta(5, $row['reorder_level'], 0.0001);
        $this->assertEqualsWithDelta(1.25, $row['cost_per_unit'], 0.0001);
        $this->assertIsBool($row['is_active']);
        $this->assertIsBool($row['is_low_stock']);
        // Never omitted, even when there is nothing to derive.
        $this->assertNull($row['daily_consumption']);
        $this->assertNull($row['last_counted_at']);
    }

    // =====================================================================
    // Branch scoping
    // =====================================================================

    public function test_a_cashier_only_sees_their_own_branchs_ingredients(): void
    {
        $other = Branch::factory()->create();
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Mine']);
        Ingredient::factory()->create(['branch_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($this->cashier);

        $names = collect($this->getJson('/api/v1/inventory/ingredients')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_a_cashier_passing_another_branch_id_is_refused_not_silently_rescoped(): void
    {
        $other = Branch::factory()->create();
        Ingredient::factory()->create(['branch_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($this->cashier);

        // A 200 carrying the caller's OWN rows would be an answer to a question
        // nobody asked; the request must fail loudly instead.
        $this->getJson('/api/v1/inventory/ingredients?branch_id='.$other->id)->assertForbidden();
        $this->getJson('/api/v1/inventory/summary?branch_id='.$other->id)->assertForbidden();
        $this->getJson('/api/v1/inventory/counts/start?branch_id='.$other->id)->assertForbidden();
    }

    public function test_a_cashier_passing_their_own_branch_id_is_allowed(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Mine']);

        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/inventory/ingredients?branch_id='.$this->branch->id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_an_owner_may_read_another_branch(): void
    {
        $other = Branch::factory()->create();
        Ingredient::factory()->create(['branch_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/inventory/ingredients?branch_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Theirs');
    }

    public function test_a_cashier_cannot_read_another_branchs_ingredient_by_id(): void
    {
        $foreign = Ingredient::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/inventory/ingredients/'.$foreign->id)->assertForbidden();
        $this->putJson('/api/v1/inventory/ingredients/'.$foreign->id, ['name' => 'Hijacked'])
            ->assertForbidden();
        $this->deleteJson('/api/v1/inventory/ingredients/'.$foreign->id)->assertForbidden();
    }

    // =====================================================================
    // Authentication + authorisation
    // =====================================================================

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/inventory/ingredients')->assertUnauthorized();
        $this->getJson('/api/v1/inventory/summary')->assertUnauthorized();
        $this->getJson('/api/v1/inventory/counts/start')->assertUnauthorized();
        $this->postJson('/api/v1/inventory/restocks', [])->assertUnauthorized();
        $this->postJson('/api/v1/inventory/counts', [])->assertUnauthorized();
    }

    public function test_a_user_with_neither_role_is_forbidden(): void
    {
        $stranger = User::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/inventory/ingredients')->assertForbidden();
        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice', 'unit' => 'kg', 'current_stock' => 1, 'reorder_level' => 1,
        ])->assertForbidden();
    }

    // =====================================================================
    // Ingredient validation
    // =====================================================================

    public function test_creating_an_ingredient_with_a_bad_unit_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice',
            'unit' => 'sacks',
            'current_stock' => 10,
            'reorder_level' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    }

    public function test_creating_an_ingredient_with_a_negative_reorder_level_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['reorder_level']);

        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice',
            'unit' => 'kg',
            'current_stock' => -5,
            'reorder_level' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_stock']);
    }

    public function test_a_duplicate_sku_within_a_branch_is_a_422_not_a_500(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'sku' => 'CHK-01']);

        Sanctum::actingAs($this->cashier);

        // The unique index would otherwise surface as a QueryException / 500.
        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Chicken thigh',
            'sku' => 'CHK-01',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku']);
    }

    public function test_the_same_sku_is_allowed_in_a_different_branch(): void
    {
        $other = Branch::factory()->create();
        Ingredient::factory()->create(['branch_id' => $other->id, 'sku' => 'CHK-01']);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Chicken thigh',
            'sku' => 'CHK-01',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 5,
        ])->assertCreated()->assertJsonPath('data.branch_id', $this->branch->id);
    }

    public function test_updating_an_ingredient_to_a_taken_sku_is_a_422(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'sku' => 'CHK-01']);
        $mine = Ingredient::factory()->create(['branch_id' => $this->branch->id, 'sku' => 'BEF-01']);

        Sanctum::actingAs($this->cashier);

        $this->putJson('/api/v1/inventory/ingredients/'.$mine->id, ['sku' => 'CHK-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        // Re-saving its OWN sku must still pass (the rule ignores itself).
        $this->putJson('/api/v1/inventory/ingredients/'.$mine->id, ['sku' => 'BEF-01'])
            ->assertOk()
            ->assertJsonPath('data.sku', 'BEF-01');
    }

    public function test_updating_with_a_bad_unit_is_rejected(): void
    {
        $ingredient = Ingredient::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($this->cashier);

        $this->putJson('/api/v1/inventory/ingredients/'.$ingredient->id, ['unit' => 'crates'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);
    }

    // =====================================================================
    // Quick restock
    // =====================================================================

    public function test_a_quick_restock_writes_a_movement_and_bumps_current_stock(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 5,
        ]);

        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $ingredient->id,
            'quantity' => 4.5,
            'unit_cost' => 12.5,
            'notes' => 'Morning delivery',
        ])->assertCreated();

        $response->assertJsonPath('data.movement.direction', 'in')
            ->assertJsonPath('data.movement.movement_type', 'purchase')
            ->assertJsonPath('data.movement.branch_id', $this->branch->id)
            ->assertJsonPath('data.movement.ingredient_id', $ingredient->id);

        $this->assertEqualsWithDelta(4.5, $response->json('data.movement.quantity'), 0.0001);
        $this->assertEqualsWithDelta(14.5, $response->json('data.ingredient.current_stock'), 0.0001);
        $this->assertEqualsWithDelta(4.5, $response->json('data.ingredient.pending_restock'), 0.0001);

        // The two writes are a pair: the movement is what makes the NEXT
        // count's consumption correct, the bump is what the browse screen shows.
        $this->assertSame(1, InventoryMovement::where('ingredient_id', $ingredient->id)->count());
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredient->id,
            'branch_id' => $this->branch->id,
            'direction' => 'in',
            'movement_type' => 'purchase',
            'reference_type' => null,
            'reference_id' => null,
            'created_by_user_id' => $this->cashier->id,
        ]);
        $this->assertEqualsWithDelta(14.5, (float) $ingredient->fresh()->current_stock, 0.0001);
    }

    public function test_a_cashier_cannot_restock_another_branchs_ingredient(): void
    {
        $foreign = Ingredient::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $foreign->id,
            'quantity' => 5,
        ])->assertForbidden();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_restocking_an_inactive_ingredient_is_rejected(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $ingredient->id,
            'quantity' => 5,
        ])->assertUnprocessable();
    }

    // =====================================================================
    // Count session pre-fill
    // =====================================================================

    public function test_count_start_prefills_previous_from_the_last_count_and_restocked_from_movements_since(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Rice',
            'unit' => 'kg',
            'current_stock' => 80,
            'reorder_level' => 10,
        ]);

        // A count that ended at 80kg...
        $count = StockCount::factory()->create([
            'branch_id' => $this->branch->id,
            'counted_at' => now()->subDays(3)->toDateString(),
            'recorded_by_user_id' => $this->owner->id,
        ]);
        StockCountEntry::factory()->create([
            'stock_count_id' => $count->id,
            'ingredient_id' => $ingredient->id,
            'previous_quantity' => 100,
            'restocked_quantity' => 0,
            'counted_quantity' => 80,
            'consumption' => 20,
        ]);

        Sanctum::actingAs($this->cashier);

        // ...followed by a 25kg delivery logged through quick restock.
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $ingredient->id,
            'quantity' => 25,
        ])->assertCreated();

        $session = $this->getJson('/api/v1/inventory/counts/start')->assertOk();

        $session->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', 'Main')
            ->assertJsonPath('data.today', now()->toDateString())
            ->assertJsonPath('data.rows.0.ingredient_id', $ingredient->id);

        $row = $session->json('data.rows.0');
        $this->assertEqualsWithDelta(80, $row['previous_quantity'], 0.0001);
        $this->assertEqualsWithDelta(25, $row['restocked_quantity'], 0.0001);
        // expected == book stock == previous + restocked, and the counted field
        // pre-fills to it.
        $this->assertEqualsWithDelta(105, $row['expected_quantity'], 0.0001);
        $this->assertEqualsWithDelta(105, $row['counted_quantity'], 0.0001);

        // The cursor fences exactly the movement that was folded into the row.
        $this->assertSame(
            (int) InventoryMovement::where('ingredient_id', $ingredient->id)->max('id'),
            $session->json('data.restock_cursor'),
        );
    }

    public function test_count_start_omits_inactive_ingredients(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Active one']);
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Retired one',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->cashier);

        $names = collect($this->getJson('/api/v1/inventory/counts/start')->assertOk()->json('data.rows'))
            ->pluck('name');

        $this->assertContains('Active one', $names);
        $this->assertNotContains('Retired one', $names);
    }

    // =====================================================================
    // THE CORE REGRESSION
    // =====================================================================

    /**
     * create ingredient -> count -> quick restock -> count again.
     *
     * Without the movement ledger the second count would start from
     * previous=80, restocked=0 and read the 25kg delivery as if it had been
     * eaten. Every number below is asserted explicitly for that reason.
     */
    public function test_a_restock_between_two_counts_is_not_read_as_consumption(): void
    {
        Sanctum::actingAs($this->cashier);

        // 1. Create the ingredient with an opening stock of 100kg.
        $ingredientId = $this->postJson('/api/v1/inventory/ingredients', [
            'name' => 'Rice',
            'sku' => 'RICE-01',
            'unit' => 'kg',
            'current_stock' => 100,
            'reorder_level' => 10,
        ])->assertCreated()->json('data.id');

        // No opening movement: the opening current_stock IS the baseline.
        $this->assertSame(0, InventoryMovement::count());

        // 2. First count, three days ago: 80kg on the shelf, so 20kg consumed.
        $firstSession = $this->getJson('/api/v1/inventory/counts/start')->assertOk();
        $this->assertSame(0, $firstSession->json('data.restock_cursor'));
        $this->assertEqualsWithDelta(100, $firstSession->json('data.rows.0.previous_quantity'), 0.0001);
        $this->assertEqualsWithDelta(0, $firstSession->json('data.rows.0.restocked_quantity'), 0.0001);

        $firstCountId = $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->subDays(3)->toDateString(),
            'restock_cursor' => $firstSession->json('data.restock_cursor'),
            'entries' => [['ingredient_id' => $ingredientId, 'counted_quantity' => 80]],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('stock_count_entries', [
            'stock_count_id' => $firstCountId,
            'ingredient_id' => $ingredientId,
            'previous_quantity' => 100,
            'restocked_quantity' => 0,
            'counted_quantity' => 80,
            'consumption' => 20,
        ]);
        $this->assertEqualsWithDelta(80, (float) Ingredient::find($ingredientId)->current_stock, 0.0001);

        // 3. A 25kg delivery arrives and is logged through quick restock.
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $ingredientId,
            'quantity' => 25,
        ])->assertCreated();

        $this->assertEqualsWithDelta(105, (float) Ingredient::find($ingredientId)->current_stock, 0.0001);

        // 4. Second count: the session must pre-fill the delivery, not hide it.
        $secondSession = $this->getJson('/api/v1/inventory/counts/start')->assertOk();
        $secondRow = $secondSession->json('data.rows.0');

        $this->assertEqualsWithDelta(80, $secondRow['previous_quantity'], 0.0001);
        $this->assertEqualsWithDelta(25, $secondRow['restocked_quantity'], 0.0001, 'The restock must be pre-filled, otherwise it is read as consumption.');
        $this->assertEqualsWithDelta(105, $secondRow['expected_quantity'], 0.0001);

        // 5. 75kg is actually on the shelf, so 30kg was consumed (80 + 25 - 75).
        $secondCountId = $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => $secondSession->json('data.restock_cursor'),
            'entries' => [['ingredient_id' => $ingredientId, 'counted_quantity' => 75]],
        ])->assertCreated()->json('data.id');

        $entry = StockCountEntry::where('stock_count_id', $secondCountId)
            ->where('ingredient_id', $ingredientId)
            ->firstOrFail();

        $this->assertEqualsWithDelta(80, (float) $entry->previous_quantity, 0.0001);
        $this->assertEqualsWithDelta(25, (float) $entry->restocked_quantity, 0.0001);
        $this->assertEqualsWithDelta(75, (float) $entry->counted_quantity, 0.0001);
        // The whole point: 30, not 5 (which is what previous - counted gives
        // when the delivery is invisible).
        $this->assertEqualsWithDelta(30, (float) $entry->consumption, 0.0001);

        // 6. The movement is now claimed by the second count and can never be
        //    folded into a third one.
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredientId,
            'reference_type' => 'stock_count',
            'reference_id' => $secondCountId,
        ]);
        $this->assertEqualsWithDelta(75, (float) Ingredient::find($ingredientId)->current_stock, 0.0001);

        $thirdSession = $this->getJson('/api/v1/inventory/counts/start')->assertOk();
        $this->assertEqualsWithDelta(75, $thirdSession->json('data.rows.0.previous_quantity'), 0.0001);
        $this->assertEqualsWithDelta(0, $thirdSession->json('data.rows.0.restocked_quantity'), 0.0001);
    }

    public function test_a_delivery_logged_mid_session_is_carried_into_the_next_count(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'unit' => 'kg',
            'current_stock' => 100,
            'reorder_level' => 10,
        ]);

        Sanctum::actingAs($this->cashier);

        $session = $this->getJson('/api/v1/inventory/counts/start')->assertOk();
        $cursor = $session->json('data.restock_cursor');

        // The delivery lands AFTER the session was opened, so its id is above
        // the fence and its quantity is in nobody's counted_quantity yet.
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $ingredient->id,
            'quantity' => 30,
        ])->assertCreated();

        $countId = $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => $cursor,
            'entries' => [['ingredient_id' => $ingredient->id, 'counted_quantity' => 90]],
        ])->assertCreated()->json('data.id');

        $entry = StockCountEntry::where('stock_count_id', $countId)->firstOrFail();
        $this->assertEqualsWithDelta(0, (float) $entry->restocked_quantity, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $entry->consumption, 0.0001);

        // The movement stays unclaimed...
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredient->id,
            'reference_type' => null,
        ]);
        // ...current_stock is counted + the unclaimed remainder, not a bare 90...
        $this->assertEqualsWithDelta(120, (float) $ingredient->fresh()->current_stock, 0.0001);
        // ...and the next session picks it up.
        $next = $this->getJson('/api/v1/inventory/counts/start')->assertOk();
        $this->assertEqualsWithDelta(90, $next->json('data.rows.0.previous_quantity'), 0.0001);
        $this->assertEqualsWithDelta(30, $next->json('data.rows.0.restocked_quantity'), 0.0001);
    }

    // =====================================================================
    // Count submission
    // =====================================================================

    public function test_submitting_a_count_updates_stock_and_derives_consumption_per_entry(): void
    {
        $rice = Ingredient::factory()->create([
            'branch_id' => $this->branch->id, 'name' => 'Rice',
            'unit' => 'kg', 'current_stock' => 100, 'reorder_level' => 10,
        ]);
        $oil = Ingredient::factory()->create([
            'branch_id' => $this->branch->id, 'name' => 'Oil',
            'unit' => 'l', 'current_stock' => 40, 'reorder_level' => 5,
        ]);

        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => 0,
            'notes' => 'Evening walk',
            'entries' => [
                ['ingredient_id' => $rice->id, 'counted_quantity' => 72.5],
                ['ingredient_id' => $oil->id, 'counted_quantity' => 33],
            ],
        ])->assertCreated();

        $response->assertJsonStructure([
            'data' => ['id', 'branch_id', 'counted_at', 'recorded_by', 'notes', 'entry_count', 'total_consumption', 'created_at'],
        ])
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.entry_count', 2)
            ->assertJsonPath('data.recorded_by', $this->cashier->name)
            ->assertJsonPath('data.notes', 'Evening walk');

        // 27.5 consumed rice + 7 consumed oil.
        $this->assertEqualsWithDelta(34.5, $response->json('data.total_consumption'), 0.0001);

        $countId = $response->json('data.id');
        $this->assertDatabaseHas('stock_count_entries', [
            'stock_count_id' => $countId, 'ingredient_id' => $rice->id,
            'previous_quantity' => 100, 'restocked_quantity' => 0,
            'counted_quantity' => 72.5, 'consumption' => 27.5,
        ]);
        $this->assertDatabaseHas('stock_count_entries', [
            'stock_count_id' => $countId, 'ingredient_id' => $oil->id,
            'previous_quantity' => 40, 'restocked_quantity' => 0,
            'counted_quantity' => 33, 'consumption' => 7,
        ]);

        $this->assertEqualsWithDelta(72.5, (float) $rice->fresh()->current_stock, 0.0001);
        $this->assertEqualsWithDelta(33, (float) $oil->fresh()->current_stock, 0.0001);
    }

    public function test_consumption_is_floored_at_zero_when_more_is_counted_than_expected(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'unit' => 'kg',
            'current_stock' => 50,
            'reorder_level' => 5,
        ]);

        Sanctum::actingAs($this->cashier);

        // 65 counted against a book stock of 50: a negative consumption is
        // meaningless, so it floors at 0 while current_stock still becomes 65.
        $countId = $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => 0,
            'entries' => [['ingredient_id' => $ingredient->id, 'counted_quantity' => 65]],
        ])->assertCreated()->json('data.id');

        $entry = StockCountEntry::where('stock_count_id', $countId)->firstOrFail();
        $this->assertEqualsWithDelta(50, (float) $entry->previous_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $entry->consumption, 0.0001);
        $this->assertEqualsWithDelta(65, (float) $ingredient->fresh()->current_stock, 0.0001);
    }

    public function test_an_ingredient_from_another_branch_cannot_be_slipped_into_a_count(): void
    {
        $mine = Ingredient::factory()->create(['branch_id' => $this->branch->id]);
        $foreign = Ingredient::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => 0,
            'entries' => [
                ['ingredient_id' => $mine->id, 'counted_quantity' => 5],
                ['ingredient_id' => $foreign->id, 'counted_quantity' => 5],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['entries.1.ingredient_id']);

        // The whole batch is rejected atomically.
        $this->assertSame(0, StockCount::count());
        $this->assertSame(0, StockCountEntry::count());
    }

    public function test_a_forged_restock_cursor_is_refused(): void
    {
        $ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 50,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->toDateString(),
            'restock_cursor' => PHP_INT_MAX,
            'entries' => [['ingredient_id' => $ingredient->id, 'counted_quantity' => 5]],
        ])->assertUnprocessable();

        $this->assertSame(0, StockCount::count());
    }

    public function test_a_count_dated_in_the_future_is_rejected(): void
    {
        $ingredient = Ingredient::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->addDay()->toDateString(),
            'restock_cursor' => 0,
            'entries' => [['ingredient_id' => $ingredient->id, 'counted_quantity' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['counted_at']);
    }

    // =====================================================================
    // Summary
    // =====================================================================

    public function test_the_summary_counts_low_stock_and_the_last_count_date(): void
    {
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id, 'current_stock' => 2, 'reorder_level' => 10,
        ]);
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id, 'current_stock' => 90, 'reorder_level' => 10,
        ]);
        Ingredient::factory()->create([
            'branch_id' => $this->branch->id, 'current_stock' => 5, 'reorder_level' => 10,
            'is_active' => false,
        ]);
        Ingredient::factory()->create([
            'branch_id' => Branch::factory()->create()->id, 'current_stock' => 1, 'reorder_level' => 99,
        ]);

        StockCount::factory()->create([
            'branch_id' => $this->branch->id,
            'counted_at' => now()->subDays(5)->toDateString(),
            'recorded_by_user_id' => $this->owner->id,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', 'Main')
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.active_items', 2)
            // Active-only: the third (5/10) row is deactivated, and the list the
            // low-stock banner links to never shows it.
            ->assertJsonPath('data.low_stock_count', 1)
            ->assertJsonPath('data.last_count_at', now()->subDays(5)->toDateString())
            ->assertJsonPath('data.days_since_last_count', 5)
            ->assertJsonPath('data.counts_this_month', 1);
    }

    // =====================================================================
    // Deactivation
    // =====================================================================

    public function test_delete_deactivates_rather_than_destroying_history(): void
    {
        $ingredient = Ingredient::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($this->cashier);

        $this->deleteJson('/api/v1/inventory/ingredients/'.$ingredient->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'is_active' => false,
        ]);
    }
}
