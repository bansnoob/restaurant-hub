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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The service-level invariants the API tests cannot reach: the
 * `current_stock == lastCounted + pendingSum` identity across every writer,
 * and the web (all-branch, null-cursor) code paths.
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private Branch $branch;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->service = app(InventoryService::class);
        $this->branch = Branch::factory()->create();
        $this->actor = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actor->assignRole('owner');
    }

    private function ingredient(float $stock = 100): Ingredient
    {
        return Ingredient::factory()->create([
            'branch_id' => $this->branch->id,
            'current_stock' => $stock,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
    }

    /** current_stock must always equal the last counted quantity + the unclaimed net. */
    private function assertBookStockHolds(Ingredient $ingredient, float $lastCounted): void
    {
        $pending = $this->service->pendingRestocks([$ingredient->id])[$ingredient->id] ?? 0.0;

        $this->assertSame(
            round($lastCounted + $pending, 3),
            round((float) $ingredient->fresh()->current_stock, 3),
            'current_stock drifted from lastCounted + pendingSum'
        );
    }

    public function test_the_invariant_holds_after_a_restock(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordRestock($ingredient, 15, null, null, $this->actor);

        $this->assertBookStockHolds($ingredient, 100);
    }

    public function test_the_invariant_holds_after_an_adjustment(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordAdjustment($ingredient, 80, null, $this->actor);

        $this->assertBookStockHolds($ingredient, 100);
        $movement = InventoryMovement::sole();
        $this->assertSame('adjustment', $movement->movement_type);
        $this->assertSame('out', $movement->direction);
        $this->assertSame(20.0, (float) $movement->quantity);
    }

    public function test_a_downward_adjustment_makes_the_pending_sum_negative(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordAdjustment($ingredient, 80, null, $this->actor);

        // Signed, so a correction is not silently re-read as consumption.
        $this->assertSame(-20.0, $this->service->pendingRestocks([$ingredient->id])[$ingredient->id]);
    }

    public function test_a_no_op_adjustment_writes_nothing(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordAdjustment($ingredient, 100, null, $this->actor);

        $this->assertSame(0, InventoryMovement::count(), 'a no-op edit polluted the ledger');
    }

    public function test_the_invariant_holds_after_a_count_with_a_mid_session_restock(): void
    {
        $ingredient = $this->ingredient(100);
        $session = $this->service->buildCountSession($this->branch->id);
        $cursor = $session->restockCursor;

        // Delivery lands AFTER the fence.
        $this->service->recordRestock($ingredient, 30, null, null, $this->actor);

        $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 90.0, 'declared_restock' => null]],
            $cursor,
            null,
            $this->actor,
        );

        $this->assertBookStockHolds($ingredient, 90);
        $this->assertSame(120.0, (float) $ingredient->fresh()->current_stock);
    }

    public function test_deleting_a_count_unclaims_its_movements_and_restores_stock(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordRestock($ingredient, 20, null, null, $this->actor);
        $first = $this->service->recordCount(
            $this->branch->id,
            now()->subDay()->toDateString(),
            [$ingredient->id => ['counted' => 110.0, 'declared_restock' => null]],
            null,
            null,
            $this->actor,
        );

        $this->service->recordRestock($ingredient, 5, null, null, $this->actor);
        $second = $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 100.0, 'declared_restock' => null]],
            null,
            null,
            $this->actor,
        );

        $this->assertTrue($this->service->isLatestCount($second));

        $this->service->deleteCount($second);

        // Back to the first count's baseline, with the second count's
        // movements returned to the pending pool rather than stranded.
        $this->assertSame(
            'stock_count',
            InventoryMovement::orderBy('id')->first()->reference_type,
            "the FIRST count's claim must be untouched"
        );
        $this->assertNull(
            InventoryMovement::orderByDesc('id')->first()->reference_type,
            'the deleted count must release its movements'
        );

        $this->assertBookStockHolds($ingredient, 110);
        $this->assertDatabaseMissing('stock_counts', ['id' => $second->id]);
        $this->assertDatabaseHas('stock_counts', ['id' => $first->id]);
    }

    public function test_a_count_backdated_behind_the_branchs_latest_is_rejected(): void
    {
        $ingredient = $this->ingredient(100);

        $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 90.0, 'declared_restock' => null]],
            null,
            null,
            $this->actor,
        );

        // counted_at is backdatable; the claim fence is a monotonic id. Mixing
        // the two orderings would strand a delivery as consumption forever.
        $this->expectException(HttpException::class);

        $this->service->recordCount(
            $this->branch->id,
            now()->subWeek()->toDateString(),
            [$ingredient->id => ['counted' => 80.0, 'declared_restock' => null]],
            null,
            null,
            $this->actor,
        );
    }

    public function test_a_web_declared_restock_is_added_on_top_of_the_ledger_sum(): void
    {
        $ingredient = $this->ingredient(100);

        // The web blade's per-row "Restocked" input is an operator observation
        // the movements ledger does not know about; it must not be dropped.
        $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 105.0, 'declared_restock' => 25.0]],
            null,
            null,
            $this->actor,
        );

        $entry = StockCountEntry::sole();
        $this->assertSame(25.0, (float) $entry->restocked_quantity);
        // 100 + 25 - 105 = 20 consumed.
        $this->assertSame(20.0, (float) $entry->consumption);
    }

    public function test_the_null_cursor_web_sentinel_claims_everything_unclaimed(): void
    {
        $ingredient = $this->ingredient(100);
        $this->service->recordRestock($ingredient, 10, null, null, $this->actor);

        $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 110.0, 'declared_restock' => null]],
            null,          // <- the WEB sentinel
            null,
            $this->actor,
        );

        $this->assertSame('stock_count', InventoryMovement::sole()->reference_type);
    }

    public function test_a_zero_cursor_claims_nothing(): void
    {
        $ingredient = $this->ingredient(100);
        $this->service->recordRestock($ingredient, 10, null, null, $this->actor);

        $this->service->recordCount(
            $this->branch->id,
            now()->toDateString(),
            [$ingredient->id => ['counted' => 110.0, 'declared_restock' => null]],
            0,             // id <= 0 matches no row
            null,
            $this->actor,
        );

        $this->assertNull(InventoryMovement::sole()->reference_type);
    }

    public function test_build_count_session_accepts_null_for_the_webs_all_branch_walk(): void
    {
        $other = Branch::factory()->create();
        $this->ingredient(100);
        Ingredient::factory()->create(['branch_id' => $other->id, 'is_active' => true]);

        $session = $this->service->buildCountSession(null);

        $this->assertCount(2, $session->rows);
        // The blade renders row.branch_name in the row meta line.
        foreach ($session->rows as $row) {
            $this->assertNotNull($row->branchName);
        }
    }

    public function test_the_restock_cursor_is_zero_when_nothing_is_pending(): void
    {
        $this->ingredient(100);

        $this->assertSame(0, $this->service->restockCursor($this->branch->id));
    }

    public function test_daily_consumption_is_null_until_there_are_two_counts(): void
    {
        $ingredient = $this->ingredient(100);

        $view = $this->service->viewIngredient($ingredient);
        $this->assertNull($view->stats->dailyConsumption);

        $this->service->recordCount(
            $this->branch->id, now()->subDays(2)->toDateString(),
            [$ingredient->id => ['counted' => 90.0, 'declared_restock' => null]],
            null, null, $this->actor,
        );
        $this->assertNull($this->service->viewIngredient($ingredient->fresh())->stats->dailyConsumption);

        $this->service->recordCount(
            $this->branch->id, now()->toDateString(),
            [$ingredient->id => ['counted' => 70.0, 'declared_restock' => null]],
            null, null, $this->actor,
        );

        // 20 consumed over 2 days.
        $this->assertSame(10.0, $this->service->viewIngredient($ingredient->fresh())->stats->dailyConsumption);
    }
}
