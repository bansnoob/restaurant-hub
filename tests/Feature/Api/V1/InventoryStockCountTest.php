<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\StockCountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryStockCountTest extends TestCase
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
            'current_stock' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
    }

    private function restock(float $qty): void
    {
        $this->postJson('/api/v1/inventory/restocks', [
            'ingredient_id' => $this->ingredient->id,
            'quantity' => $qty,
        ])->assertCreated();
    }

    private function openSession(): array
    {
        return $this->getJson('/api/v1/inventory/counts/start')->assertOk()->json('data');
    }

    private function submit(array $session, float $counted): TestResponse
    {
        return $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => $session['today'],
            'restock_cursor' => $session['restock_cursor'],
            'entries' => [[
                'ingredient_id' => $this->ingredient->id,
                'counted_quantity' => $counted,
            ]],
        ]);
    }

    public function test_the_session_shape_matches_the_contract(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/inventory/counts/start')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'branch_id', 'branch_name', 'today', 'restock_cursor',
                'rows' => [['ingredient_id', 'name', 'sku', 'unit', 'reorder_level',
                    'previous_quantity', 'restocked_quantity', 'expected_quantity',
                    'counted_quantity']],
            ]])
            // 0, never null — the wire type is `number`.
            ->assertJsonPath('data.restock_cursor', 0);
    }

    public function test_the_session_only_walks_active_ingredients(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);

        Sanctum::actingAs($this->cashier);

        $rows = $this->openSession()['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame($this->ingredient->id, $rows[0]['ingredient_id']);
    }

    public function test_expected_quantity_always_equals_previous_plus_restocked(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->restock(25);

        $row = $this->openSession()['rows'][0];

        $this->assertSame(
            round($row['previous_quantity'] + $row['restocked_quantity'], 3),
            round($row['expected_quantity'], 3),
            'the book-stock invariant is broken'
        );
        // Pre-fill == expected.
        $this->assertSame($row['expected_quantity'], $row['counted_quantity']);
        // And expected == the live book stock.
        $this->assertSame(125.0, (float) $row['expected_quantity']);
    }

    public function test_the_no_prior_count_baseline_does_not_double_count_a_restock(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->restock(25); // current_stock 100 -> 125, pending +25

        $row = $this->openSession()['rows'][0];

        // current_stock is LIVE and already includes the restock, so previous
        // must be current_stock - pending, not current_stock.
        $this->assertSame(100.0, (float) $row['previous_quantity']);
        $this->assertSame(25.0, (float) $row['restocked_quantity']);
    }

    /**
     * THE bug the whole design exists to fix: a quick restock must not be
     * re-read as consumption by the next count.
     */
    public function test_a_quick_restock_is_not_booked_as_consumption(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->restock(20); // 100 -> 120

        $session = $this->openSession();
        // Everything delivered is still on the shelf; nothing was used.
        $this->submit($session, 120)->assertCreated();

        $entry = StockCountEntry::sole();
        $this->assertSame(100.0, (float) $entry->previous_quantity);
        $this->assertSame(20.0, (float) $entry->restocked_quantity);
        $this->assertSame(120.0, (float) $entry->counted_quantity);
        $this->assertSame(0.0, (float) $entry->consumption, 'the delivery was eaten by the ledger');
    }

    public function test_consumption_is_previous_plus_restocked_minus_counted(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->restock(20);           // book stock 120
        $session = $this->openSession();
        $this->submit($session, 95)->assertCreated();  // 25 actually used

        $this->assertSame(25.0, (float) StockCountEntry::sole()->consumption);
    }

    public function test_submitting_a_count_claims_the_movements_it_counted(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->restock(20);
        $session = $this->openSession();
        $this->submit($session, 120)->assertCreated();

        $movement = InventoryMovement::sole();
        $this->assertSame('stock_count', $movement->reference_type);
        $this->assertNotNull($movement->reference_id);

        // Claimed once, so the NEXT session starts clean.
        $next = $this->openSession()['rows'][0];
        $this->assertSame(0.0, (float) $next['restocked_quantity']);
        $this->assertSame(120.0, (float) $next['previous_quantity']);
    }

    /**
     * A delivery logged AFTER the session opened gets an id above the fence, so
     * it must survive into the next count rather than being silently claimed.
     */
    public function test_a_mid_session_delivery_is_carried_into_the_next_count(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();   // cursor 0, book stock 100
        $this->restock(30);                 // logged mid-walk: 100 -> 130
        $this->submit($session, 90)->assertCreated(); // counted 90 of the pre-restock shelf

        $this->assertSame(10.0, (float) StockCountEntry::sole()->consumption);

        $movement = InventoryMovement::sole();
        $this->assertNull($movement->reference_type, 'a mid-session delivery must stay unclaimed');

        // current_stock must be restored to counted + the unclaimed sum above
        // the fence, or the browse screen under-reports by the delivery.
        $this->assertSame(120.0, (float) $this->ingredient->fresh()->current_stock);

        $next = $this->openSession()['rows'][0];
        $this->assertSame(90.0, (float) $next['previous_quantity']);
        $this->assertSame(30.0, (float) $next['restocked_quantity']);
        $this->assertSame(120.0, (float) $next['expected_quantity']);
    }

    public function test_a_forged_cursor_cannot_claim_uncounted_deliveries(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();
        $this->restock(30);

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => $session['today'],
            'restock_cursor' => PHP_INT_MAX,
            'entries' => [[
                'ingredient_id' => $this->ingredient->id,
                'counted_quantity' => 90,
            ]],
        ])->assertStatus(422)->assertJsonPath('message', 'Stale count session. Reopen the count.');

        $this->assertNull(InventoryMovement::sole()->reference_type);
    }

    public function test_the_count_response_matches_the_contract(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();

        $this->submit($session, 90)
            ->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'branch_id', 'counted_at', 'recorded_by', 'notes',
                'entry_count', 'total_consumption', 'created_at',
            ]])
            ->assertJsonPath('data.entry_count', 1)
            ->assertJsonPath('data.recorded_by', $this->cashier->name)
            ->assertJsonPath('data.branch_id', $this->branch->id);
    }

    public function test_the_count_sets_current_stock_to_the_counted_quantity(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();
        $this->submit($session, 88)->assertCreated();

        $this->assertSame(88.0, (float) $this->ingredient->fresh()->current_stock);
    }

    public function test_a_cashier_cannot_slip_another_branchs_ingredient_into_the_batch(): void
    {
        $foreign = Ingredient::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        Sanctum::actingAs($this->cashier);
        $session = $this->openSession();

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => $session['today'],
            'restock_cursor' => $session['restock_cursor'],
            'entries' => [
                ['ingredient_id' => $this->ingredient->id, 'counted_quantity' => 90],
                ['ingredient_id' => $foreign->id, 'counted_quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('entries.1.ingredient_id');

        $this->assertSame(0, StockCountEntry::count());
    }

    public function test_an_ingredient_deactivated_mid_session_does_not_422_the_batch(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();
        $this->ingredient->update(['is_active' => false]);

        $this->submit($session, 90)->assertCreated();
        $this->assertSame(1, StockCountEntry::count());
    }

    public function test_a_future_counted_at_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/inventory/counts', [
            'counted_at' => now()->addDay()->toDateString(),
            'restock_cursor' => 0,
            'entries' => [['ingredient_id' => $this->ingredient->id, 'counted_quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('counted_at');
    }

    public function test_recent_counts_lists_the_branchs_history(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();
        $this->submit($session, 90)->assertCreated();

        $this->getJson('/api/v1/inventory/counts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entry_count', 1)
            ->assertJsonPath('data.0.total_consumption', 10);
    }

    public function test_the_summary_reflects_a_completed_count(): void
    {
        Sanctum::actingAs($this->cashier);

        $session = $this->openSession();
        $this->submit($session, 90)->assertCreated();

        $this->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->assertJsonPath('data.last_count_at', $session['today'])
            ->assertJsonPath('data.days_since_last_count', 0)
            ->assertJsonPath('data.counts_this_month', 1);
    }
}
