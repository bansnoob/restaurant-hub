<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The web module and the mobile API validate a posted count entry through ONE
 * object (App\Support\Inventory\CountEntryRules). While the rules were
 * copy-pasted into both controllers they drifted immediately — the API
 * enforced the decimal(14,3) scale and the web module silently rounded, the
 * web module had an actionable cross-branch message and the API did not.
 *
 * Every case below posts the SAME payload to both surfaces and asserts the same
 * outcome, so the next tightening cannot land on one surface only.
 */
class CountEntrySurfaceParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->ingredient = Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function postWeb(array $entries): TestResponse
    {
        return $this->actingAs($this->owner)->postJson(route('inventory.counts.store'), [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'entries' => $entries,
        ]);
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function postApi(array $entries): TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/inventory/counts', [
            'branch_id' => $this->branch->id,
            'counted_at' => now()->toDateString(),
            'restock_cursor' => 0,
            'entries' => $entries,
        ]);
    }

    public function test_both_surfaces_reject_a_quantity_finer_than_the_stored_scale(): void
    {
        $row = ['ingredient_id' => $this->ingredient->id, 'counted_quantity' => 90.123456789];

        $this->postWeb([$row])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.counted_quantity');
        $this->postApi([$row])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.counted_quantity');

        // The web surface used to accept this and silently round it to 3dp.
        $this->assertSame(0, StockCountEntry::count());
    }

    public function test_both_surfaces_reject_a_foreign_ingredient_with_the_same_actionable_message(): void
    {
        $other = Branch::factory()->create(['is_active' => true]);
        $theirs = Ingredient::factory()->create(['branch_id' => $other->id, 'is_active' => true]);
        $row = ['ingredient_id' => $theirs->id, 'counted_quantity' => 5];

        $expected = 'One of the counted ingredients does not belong to the selected branch. Reopen the count for the right branch.';

        $this->postWeb([$row])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.ingredient_id' => $expected]);
        $this->postApi([$row])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.ingredient_id' => $expected]);

        $this->assertSame(0, StockCountEntry::count());
    }

    /**
     * previous_quantity is recomputed inside the transaction and the posted
     * figure is discarded, so hard-rejecting a payload for omitting it was a
     * required-but-unused parameter on the web surface only.
     */
    public function test_both_surfaces_accept_a_payload_without_previous_quantity(): void
    {
        $row = ['ingredient_id' => $this->ingredient->id, 'counted_quantity' => 90];

        $this->postWeb([$row])->assertRedirect(route('inventory.index'));
        $this->assertSame(100.0, (float) StockCountEntry::sole()->previous_quantity);

        StockCountEntry::query()->delete();
        StockCount::query()->delete();

        $this->postApi([$row])->assertStatus(201);
        $this->assertSame(1, StockCountEntry::count());
    }

    /** A posted previous_quantity is accepted for wire compatibility, then ignored. */
    public function test_a_posted_previous_quantity_is_never_trusted(): void
    {
        $this->postWeb([[
            'ingredient_id' => $this->ingredient->id,
            'previous_quantity' => 999999,
            'counted_quantity' => 90,
        ]])->assertRedirect(route('inventory.index'));

        $this->assertSame(100.0, (float) StockCountEntry::sole()->previous_quantity);
    }
}
