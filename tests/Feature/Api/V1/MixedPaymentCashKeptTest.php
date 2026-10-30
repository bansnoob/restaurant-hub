<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A split payment's cash_amount is the cash the drawer KEEPS, not the cash handed over.
 *
 * The POS sends what the customer handed over. Stored as-is, a ₱450 bill paid with
 * ₱200 GCash and a ₱500 note recorded ₱500 of cash sales when ₱250 went straight back
 * as change, so the day's Expected was ₱250 too high and the drawer looked short by it.
 */
class MixedPaymentCashKeptTest extends TestCase
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
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        Employee::factory()->create([
            'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function openOrder(float $grandTotal): Sale
    {
        return Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => 'open',
            'grand_total' => $grandTotal,
        ]);
    }

    private function payMixed(Sale $sale, float $cash, float $gcash)
    {
        Sanctum::actingAs($this->cashier);

        return $this->postJson('/api/v1/orders/'.$sale->id.'/pay', [
            'payment_method' => 'mixed',
            'paid_total' => $cash + $gcash,
            'cash_amount' => $cash,
            'gcash_amount' => $gcash,
        ]);
    }

    public function test_change_is_not_recorded_as_cash_taken(): void
    {
        $sale = $this->openOrder(450);

        $this->payMixed($sale, 500, 200)->assertOk();

        $sale->refresh();
        $this->assertEquals(250.0, (float) $sale->cash_amount);
        $this->assertEquals(200.0, (float) $sale->gcash_amount);
        // What was handed over and given back is still on the receipt.
        $this->assertEquals(700.0, (float) $sale->paid_total);
        $this->assertEquals(250.0, (float) $sale->change_total);
    }

    public function test_an_exact_split_is_stored_as_given(): void
    {
        $sale = $this->openOrder(450);

        $this->payMixed($sale, 250, 200)->assertOk();

        $this->assertEquals(250.0, (float) $sale->fresh()->cash_amount);
    }

    public function test_a_gcash_part_equal_to_the_bill_keeps_no_cash(): void
    {
        // The whole bill on GCash, and a ₱100 note handed straight back as change.
        $sale = $this->openOrder(450);

        $this->payMixed($sale, 100, 450)->assertOk();

        $this->assertEquals(0.0, (float) $sale->fresh()->cash_amount);
    }

    public function test_the_day_close_counts_only_the_cash_kept(): void
    {
        $sale = $this->openOrder(450);
        $this->payMixed($sale, 500, 200)->assertOk();

        $totals = $this->getJson('/api/v1/day-close/preview?date='.now()->toDateString())
            ->assertOk()
            ->json('totals');

        $this->assertEquals(250.0, (float) $totals['mixed_cash_total']);
        $this->assertEquals(250.0, (float) $totals['expected_cash']);
    }

    public function test_a_split_that_does_not_cover_the_bill_is_refused(): void
    {
        $sale = $this->openOrder(450);

        $this->payMixed($sale, 200, 200)->assertUnprocessable();

        $this->assertSame('open', $sale->fresh()->status);
    }

    public function test_a_gcash_part_larger_than_the_bill_is_refused(): void
    {
        $sale = $this->openOrder(450);

        $this->payMixed($sale, 0, 500)->assertUnprocessable()
            ->assertJsonValidationErrors('gcash_amount');

        $this->assertSame('open', $sale->fresh()->status);
    }

    public function test_a_split_payment_needs_both_parts(): void
    {
        $sale = $this->openOrder(450);
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/orders/'.$sale->id.'/pay', [
            'payment_method' => 'mixed',
            'paid_total' => 500,
        ])->assertUnprocessable()->assertJsonValidationErrors(['cash_amount', 'gcash_amount']);
    }

    public function test_a_plain_cash_payment_is_unchanged(): void
    {
        $sale = $this->openOrder(450);
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/orders/'.$sale->id.'/pay', [
            'payment_method' => 'cash',
            'paid_total' => 500,
        ])->assertOk();

        $sale->refresh();
        $this->assertNull($sale->cash_amount);
        $this->assertEquals(50.0, (float) $sale->change_total);
    }
}
