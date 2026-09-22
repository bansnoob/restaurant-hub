<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The mobile API is the path most expenses are actually entered through, and it was the
 * one path that could neither record where the cash came from nor keep a closed day in
 * step with the rows it was computed from.
 *
 * Both halves matter together. `paid_from` defaults to 'drawer' in the database, so an
 * endpoint that cannot set it books every outside-paid peso against the till; and an
 * endpoint that never recalculates leaves the closure describing a day that no longer
 * exists. The web form has had both since the paid_from work shipped — these pin the
 * API to the same contract.
 */
class ExpenseClosureParityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->assignRole('cashier');
        Sanctum::actingAs($this->user);
    }

    private function closure(string $date, array $attributes = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->user->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 1000,
            'cash_sales_total' => 0,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 1000,
            'counted_cash' => 1000,
            'variance' => 0,
            'order_count' => 0,
            'expense_count' => 0,
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-10',
            'description' => 'Cooking oil',
            'amount' => 220,
            'payment_method' => 'cash',
        ], $overrides);
    }

    // ---- paid_from parity -------------------------------------------------

    public function test_store_records_paid_from_outside(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'outside']))
            ->assertCreated();

        $this->assertSame('outside', Expense::latest('id')->first()->paid_from);
    }

    public function test_store_defaults_cash_to_drawer_when_paid_from_is_omitted(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload())->assertCreated();

        $this->assertSame('drawer', Expense::latest('id')->first()->paid_from);
    }

    public function test_store_forces_drawer_for_non_cash_payment(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload([
            'payment_method' => 'gcash',
            'paid_from' => 'outside',
        ]))->assertCreated();

        $this->assertSame('drawer', Expense::latest('id')->first()->paid_from);
    }

    public function test_store_rejects_an_unknown_paid_from(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'petty_cash']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('paid_from');
    }

    public function test_update_can_change_paid_from(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload())->assertCreated();
        $expense = Expense::latest('id')->first();

        $this->putJson("/api/v1/expenses/{$expense->id}", ['paid_from' => 'outside'])
            ->assertOk();

        $this->assertSame('outside', $expense->fresh()->paid_from);
    }

    public function test_update_to_non_cash_resets_paid_from_to_drawer(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'outside']))
            ->assertCreated();
        $expense = Expense::latest('id')->first();

        $this->putJson("/api/v1/expenses/{$expense->id}", ['payment_method' => 'bank_transfer'])
            ->assertOk();

        $this->assertSame('drawer', $expense->fresh()->paid_from);
    }

    public function test_partial_update_preserves_paid_from(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'outside']))
            ->assertCreated();
        $expense = Expense::latest('id')->first();

        $this->putJson("/api/v1/expenses/{$expense->id}", ['description' => 'Renamed'])
            ->assertOk();

        $this->assertSame('outside', $expense->fresh()->paid_from);
    }

    public function test_the_api_reads_paid_from_back(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'outside']))
            ->assertCreated()
            ->assertJsonPath('data.paid_from', 'outside');
    }

    // ---- closure recalculation parity -------------------------------------

    public function test_store_recalculates_a_closed_day(): void
    {
        $closure = $this->closure('2026-09-10');

        $this->postJson('/api/v1/expenses', $this->payload(['amount' => 220]))
            ->assertCreated();

        $closure->refresh();
        $this->assertEquals(220.0, (float) $closure->cash_expenses_total);
        $this->assertEquals(780.0, (float) $closure->expected_cash);
        $this->assertSame(1, $closure->expense_count);
    }

    public function test_an_outside_paid_expense_does_not_touch_the_drawer(): void
    {
        $closure = $this->closure('2026-09-10');

        $this->postJson('/api/v1/expenses', $this->payload(['paid_from' => 'outside']))
            ->assertCreated();

        $closure->refresh();
        $this->assertEquals(0.0, (float) $closure->cash_expenses_total);
        $this->assertEquals(1000.0, (float) $closure->expected_cash);
    }

    public function test_update_recalculates_both_days_when_an_expense_moves(): void
    {
        $from = $this->closure('2026-09-10');
        $to = $this->closure('2026-09-11');

        $this->postJson('/api/v1/expenses', $this->payload(['amount' => 300]))->assertCreated();
        $expense = Expense::latest('id')->first();

        $this->putJson("/api/v1/expenses/{$expense->id}", ['expense_date' => '2026-09-11'])
            ->assertOk();

        $from->refresh();
        $to->refresh();
        $this->assertEquals(0.0, (float) $from->cash_expenses_total, 'the day it left is still overstated');
        $this->assertEquals(300.0, (float) $to->cash_expenses_total, 'the day it landed on was not updated');
    }

    public function test_destroy_recalculates_the_closed_day(): void
    {
        $closure = $this->closure('2026-09-10');

        $this->postJson('/api/v1/expenses', $this->payload(['amount' => 300]))->assertCreated();
        $expense = Expense::latest('id')->first();

        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertOk();

        $closure->refresh();
        $this->assertEquals(0.0, (float) $closure->cash_expenses_total);
        $this->assertEquals(1000.0, (float) $closure->expected_cash);
        $this->assertSame(0, $closure->expense_count);
    }

    public function test_writing_to_an_open_day_is_still_fine(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload(['expense_date' => '2026-09-12']))
            ->assertCreated();

        $this->assertDatabaseCount('day_closures', 0);
    }

    // ---- the day is closed, and the caller is told so ----------------------

    public function test_the_response_says_when_the_day_was_already_closed(): void
    {
        $this->closure('2026-09-10');

        $this->postJson('/api/v1/expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('meta.day_closed', true);
    }

    public function test_an_open_day_is_not_flagged_as_closed(): void
    {
        $this->postJson('/api/v1/expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('meta.day_closed', false);
    }
}
