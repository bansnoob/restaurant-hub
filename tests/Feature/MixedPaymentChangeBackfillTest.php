<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Past split payments stored the cash handed over, change included. The migration
 * rewrites them to the cash the drawer kept and re-derives every closed day they
 * touched, so those days' Expected and Variance stop showing a shortfall that was
 * really change. Counted cash is a human observation and is never touched.
 */
class MixedPaymentChangeBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_30_120000_store_cash_kept_on_mixed_sales.php';

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function sale(array $attributes): Sale
    {
        return Sale::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => '2026-10-20 12:00:00',
            'closed_at' => '2026-10-20 12:05:00',
        ], $attributes));
    }

    private function overpaidSplit(): Sale
    {
        // ₱450 bill, ₱200 GCash + a ₱500 note, ₱250 change.
        return $this->sale([
            'payment_method' => 'mixed',
            'grand_total' => 450,
            'paid_total' => 700,
            'change_total' => 250,
            'cash_amount' => 500,
            'gcash_amount' => 200,
        ]);
    }

    public function test_an_overpaid_split_is_rewritten_to_the_cash_kept(): void
    {
        $sale = $this->overpaidSplit();

        $this->migration()->up();

        $sale->refresh();
        $this->assertEquals(250.0, (float) $sale->cash_amount);
        $this->assertEquals(200.0, (float) $sale->gcash_amount);
        $this->assertEquals(700.0, (float) $sale->paid_total);
    }

    public function test_an_exact_split_and_a_cash_sale_are_left_alone(): void
    {
        $exact = $this->sale([
            'payment_method' => 'mixed', 'grand_total' => 450, 'paid_total' => 450,
            'cash_amount' => 250, 'gcash_amount' => 200,
        ]);
        $cash = $this->sale(['payment_method' => 'cash', 'grand_total' => 300, 'paid_total' => 500, 'change_total' => 200]);

        $this->migration()->up();

        $this->assertEquals(250.0, (float) $exact->fresh()->cash_amount);
        $this->assertNull($cash->fresh()->cash_amount);
    }

    public function test_a_split_whose_gcash_part_exceeds_the_bill_is_left_alone(): void
    {
        // No safe reading: the cash kept would be negative. Left for a person to judge.
        $sale = $this->sale([
            'payment_method' => 'mixed', 'grand_total' => 450, 'paid_total' => 520,
            'cash_amount' => 20, 'gcash_amount' => 500,
        ]);

        $this->migration()->up();

        $this->assertEquals(20.0, (float) $sale->fresh()->cash_amount);
    }

    public function test_the_closed_day_it_touched_is_recalculated(): void
    {
        $this->overpaidSplit();
        $closure = DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => '2026-10-20',
            'closed_by_user_id' => User::factory()->create()->id,
            'closed_at' => '2026-10-20 21:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 0,
            'mixed_cash_total' => 500,
            'gcash_sales_total' => 200,
            'cash_expenses_total' => 0,
            'expected_cash' => 500,
            'counted_cash' => 250,
            'variance' => -250,
            'order_count' => 1,
            'expense_count' => 0,
        ]);

        $this->migration()->up();

        $closure->refresh();
        $this->assertEquals(250.0, (float) $closure->mixed_cash_total);
        $this->assertEquals(250.0, (float) $closure->expected_cash);
        $this->assertEquals(0.0, (float) $closure->variance);
        $this->assertEquals(250.0, (float) $closure->counted_cash);
    }

    public function test_rolling_back_restores_the_cash_handed_over(): void
    {
        $sale = $this->overpaidSplit();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals(500.0, (float) $sale->fresh()->cash_amount);
    }
}
