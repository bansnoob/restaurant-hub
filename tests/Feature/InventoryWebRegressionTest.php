<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\StockCountEntry;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The web inventory module was refactored onto InventoryService. These lock in
 * the contract the blade actually consumes, since it was not touched.
 */
class InventoryWebRegressionTest extends TestCase
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

    private function ingredient(array $attributes = []): Ingredient
    {
        return Ingredient::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'current_stock' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_index_passes_the_blades_view_data(): void
    {
        $this->ingredient(['current_stock' => 1, 'reorder_level' => 50]);

        $this->actingAs($this->owner)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertViewHas('branches')
            ->assertViewHas('ingredients')
            ->assertViewHas('allIngredients')
            ->assertViewHas('lowStock')
            ->assertViewHas('recentCounts')
            ->assertViewHas('filters')
            ->assertViewHas('stats', fn ($stats) => array_keys($stats) === [
                'total_items', 'active_items', 'low_stock_count',
                'days_since_last_count', 'last_count_at', 'counts_this_month',
            ]);
    }

    public function test_index_ingredients_still_carry_the_decorated_attributes(): void
    {
        $this->ingredient();

        $ingredients = $this->actingAs($this->owner)
            ->get(route('inventory.index'))
            ->viewData('ingredients');

        $model = $ingredients->first();
        $this->assertInstanceOf(Ingredient::class, $model);
        // The blade reads these as attributes on the model.
        foreach (['daily_consumption', 'days_remaining', 'last_counted_at'] as $key) {
            $this->assertTrue(
                array_key_exists($key, $model->getAttributes()),
                "the blade's {$key} attribute was dropped by the extraction"
            );
        }
        $this->assertIsBool($model->isLowStock());
    }

    public function test_a_branch_filter_string_does_not_blow_up_strict_types(): void
    {
        $this->ingredient();

        // $request->query() returns a STRING; the service takes ?int.
        $this->actingAs($this->owner)
            ->get(route('inventory.index', ['branch_id' => (string) $this->branch->id]))
            ->assertOk();

        $this->actingAs($this->owner)
            ->get(route('inventory.index', ['branch_id' => 'not-a-number']))
            ->assertOk();
    }

    public function test_start_count_walks_every_branch_when_none_is_given(): void
    {
        $other = Branch::factory()->create(['is_active' => true]);
        $this->ingredient(['name' => 'Aaa']);
        Ingredient::factory()->create(['branch_id' => $other->id, 'is_active' => true, 'name' => 'Bbb']);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('inventory.counts.start'))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('branches', $payload);
        $this->assertArrayHasKey('today', $payload);
        $this->assertCount(2, $payload['ingredients']);

        foreach ($payload['ingredients'] as $row) {
            // blade line 392 renders row.branch_name in the row meta line.
            $this->assertNotNull($row['branch_name']);
            $this->assertArrayHasKey('previous_quantity', $row);
            $this->assertArrayHasKey('counted_quantity', $row);
        }
    }

    public function test_the_editable_restocked_input_is_seeded_zero_and_the_derived_sum_is_separate(): void
    {
        $ingredient = $this->ingredient();
        app(InventoryService::class)->recordRestock($ingredient, 12, null, null, $this->owner);

        $row = $this->actingAs($this->owner)
            ->getJson(route('inventory.counts.start'))
            ->assertOk()
            ->json('ingredients.0');

        // Seeded 0 so an operator's manual figure is not added on top of the
        // figure already derived from inventory_movements.
        $this->assertSame(0.0, (float) $row['restocked_quantity']);
        $this->assertSame(12.0, (float) $row['pending_restock']);
        $this->assertSame(112.0, (float) $row['counted_quantity']);
    }

    /**
     * The blade's Consumed column computes
     * `expected_quantity + restocked_quantity - counted_quantity`. Reading only
     * previous_quantity rendered a phantom GAIN of pending_restock on every row
     * with a logged delivery, contradicting what the server then stored.
     */
    public function test_the_count_modals_consumed_column_matches_the_stored_consumption(): void
    {
        $ingredient = $this->ingredient();
        app(InventoryService::class)->recordRestock($ingredient, 30, null, null, $this->owner);

        $row = $this->actingAs($this->owner)
            ->getJson(route('inventory.counts.start'))
            ->assertOk()
            ->json('ingredients.0');

        // The keys the blade's rowConsumed() reads.
        $this->assertSame(100.0, (float) $row['previous_quantity']);
        $this->assertSame(30.0, (float) $row['pending_restock']);
        $this->assertSame(130.0, (float) $row['expected_quantity']);

        $counted = 120.0;
        $rendered = (float) $row['expected_quantity']
            + (float) $row['restocked_quantity']
            - $counted;

        $this->actingAs($this->owner)->post(route('inventory.counts.store'), [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'entries' => [[
                'ingredient_id' => $ingredient->id,
                'previous_quantity' => $row['previous_quantity'],
                'restocked_quantity' => $row['restocked_quantity'],
                'counted_quantity' => $counted,
            ]],
        ])->assertRedirect(route('inventory.index'));

        $this->assertSame($rendered, (float) StockCountEntry::sole()->consumption);
    }

    public function test_store_count_honours_the_blades_manual_restocked_input(): void
    {
        $ingredient = $this->ingredient();

        $this->actingAs($this->owner)->post(route('inventory.counts.store'), [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'entries' => [[
                'ingredient_id' => $ingredient->id,
                'previous_quantity' => 100,
                'restocked_quantity' => 30,
                'counted_quantity' => 110,
            ]],
        ])->assertRedirect(route('inventory.index'))->assertSessionHas('success', 'Stock count saved.');

        $entry = StockCountEntry::sole();
        // The operator typed 30; dropping it would understate consumption by 30.
        $this->assertSame(30.0, (float) $entry->restocked_quantity);
        $this->assertSame(20.0, (float) $entry->consumption);
        $this->assertSame(110.0, (float) $ingredient->fresh()->current_stock);
    }

    public function test_store_count_folds_a_quick_restock_in_on_top_of_the_manual_figure(): void
    {
        $ingredient = $this->ingredient();
        app(InventoryService::class)->recordRestock($ingredient, 10, null, null, $this->owner);

        $this->actingAs($this->owner)->post(route('inventory.counts.store'), [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'entries' => [[
                'ingredient_id' => $ingredient->id,
                'previous_quantity' => 100,
                'restocked_quantity' => 5,
                'counted_quantity' => 100,
            ]],
        ])->assertRedirect(route('inventory.index'));

        $entry = StockCountEntry::sole();
        $this->assertSame(15.0, (float) $entry->restocked_quantity);
        $this->assertSame(15.0, (float) $entry->consumption);
        // The web sentinel claims everything unclaimed at commit time.
        $this->assertSame('stock_count', InventoryMovement::sole()->reference_type);
    }

    public function test_show_ingredient_json_keeps_the_alpine_drawers_keys(): void
    {
        $ingredient = $this->ingredient();

        $this->actingAs($this->owner)->post(route('inventory.counts.store'), [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'entries' => [[
                'ingredient_id' => $ingredient->id,
                'previous_quantity' => 100,
                'restocked_quantity' => 0,
                'counted_quantity' => 90,
            ]],
        ]);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('inventory.show', $ingredient))
            ->assertOk()
            ->json();

        // blade 520/559: ingredientData.ingredient.branch_name
        $this->assertArrayHasKey('branch_name', $payload['ingredient']);
        $this->assertNotNull($payload['ingredient']['branch_name']);
        // The drawer has always shown 0, never null, for too-few-counts.
        $this->assertNotNull($payload['ingredient']['daily_consumption']);
        // blade 586: row.counted_at_label
        $this->assertNotEmpty($payload['history']);
        $this->assertArrayHasKey('counted_at_label', $payload['history'][0]);
    }

    public function test_destroy_count_reverts_stock_and_keeps_the_flash_messages(): void
    {
        $ingredient = $this->ingredient();
        $service = app(InventoryService::class);

        $first = $service->recordCount(
            $this->branch->id, now()->subDay()->toDateString(),
            [$ingredient->id => ['counted' => 95.0, 'declared_restock' => null]],
            null, null, $this->owner,
        );
        $second = $service->recordCount(
            $this->branch->id, now()->toDateString(),
            [$ingredient->id => ['counted' => 80.0, 'declared_restock' => null]],
            null, null, $this->owner,
        );

        $this->actingAs($this->owner)
            ->delete(route('inventory.counts.destroy', $second))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(95.0, (float) $ingredient->fresh()->current_stock);

        // Only the most recent count may be deleted.
        $this->actingAs($this->owner)
            ->delete(route('inventory.counts.destroy', $first))
            ->assertRedirect();
        $this->assertDatabaseMissing('stock_counts', ['id' => $second->id]);
    }

    public function test_only_the_most_recent_count_can_be_deleted(): void
    {
        $ingredient = $this->ingredient();
        $service = app(InventoryService::class);

        $first = $service->recordCount(
            $this->branch->id, now()->subDay()->toDateString(),
            [$ingredient->id => ['counted' => 95.0, 'declared_restock' => null]],
            null, null, $this->owner,
        );
        $service->recordCount(
            $this->branch->id, now()->toDateString(),
            [$ingredient->id => ['counted' => 80.0, 'declared_restock' => null]],
            null, null, $this->owner,
        );

        $this->actingAs($this->owner)
            ->delete(route('inventory.counts.destroy', $first))
            ->assertRedirect()
            ->assertSessionHas('error', 'Only the most recent count can be deleted.');

        $this->assertDatabaseHas('stock_counts', ['id' => $first->id]);
    }

    public function test_the_web_ingredient_crud_still_works(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'Web ingredient',
            'unit' => 'kg',
            'current_stock' => 10,
            'reorder_level' => 2,
        ])->assertRedirect();

        $ingredient = Ingredient::where('name', 'Web ingredient')->sole();

        $this->actingAs($this->owner)->put(route('inventory.update', $ingredient), [
            'branch_id' => $this->branch->id,
            'name' => 'Renamed',
            'unit' => 'kg',
            'current_stock' => 12,
            'reorder_level' => 2,
        ])->assertRedirect();

        $this->assertSame('Renamed', $ingredient->fresh()->name);

        $this->actingAs($this->owner)
            ->delete(route('inventory.destroy', $ingredient))
            ->assertRedirect();
    }

    public function test_a_cashier_cannot_reach_the_web_inventory_module(): void
    {
        Role::findOrCreate('cashier');
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get(route('inventory.index'))->assertForbidden();
    }
}
