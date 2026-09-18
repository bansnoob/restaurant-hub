<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An expense paid from outside the till is real cash out of the business, but it never
 * passed through the drawer, so it must not be part of any day's reconciliation.
 *
 * The production case this models: a P17,200 "Cash Advance - Chicken" backdated onto a
 * day whose till counted P2,000. Charged to the day, expected_cash goes negative and a
 * correct close reads as a P17,000 surplus.
 */
class ExpensePaidFromTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->date = now()->subDay()->toDateString();

        Sale::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'completed', 'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00', 'closed_at' => $this->date.' 12:00:00',
            'grand_total' => 2000,
        ]);
    }

    private function closure(): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id, 'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id, 'closed_at' => $this->date.' 22:00:00',
            'opening_float' => 0, 'cash_sales_total' => 2000, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 0, 'expected_cash' => 2000,
            'counted_cash' => 2000, 'variance' => 0, 'order_count' => 1, 'expense_count' => 0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => $this->date,
            'description' => 'Cash Advance - Chicken',
            'amount' => 17200,
            'payment_method' => 'cash',
            'paid_from' => 'outside',
        ], $overrides);
    }

    public function test_expenses_default_to_drawer(): void
    {
        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload(['paid_from' => null, 'amount' => 100]));

        $this->assertSame('drawer', Expense::firstOrFail()->paid_from);
    }

    /** The production case: the till could not have funded it, so the day must not wear it. */
    public function test_an_outside_paid_expense_does_not_move_a_days_expected_or_variance(): void
    {
        $closure = $this->closure();

        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload())
            ->assertSessionHas('success');

        $fresh = $closure->fresh();
        $this->assertSame(2000.0, round((float) $fresh->expected_cash, 2), 'expected went negative');
        $this->assertSame(0.0, round((float) $fresh->variance, 2), 'a correct close was turned into a surplus');
        $this->assertSame(0.0, round((float) $fresh->cash_expenses_total, 2));
    }

    public function test_a_drawer_paid_expense_still_moves_the_day(): void
    {
        $closure = $this->closure();

        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload(['paid_from' => 'drawer', 'amount' => 150]));

        $fresh = $closure->fresh();
        $this->assertSame(1850.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(150.0, round((float) $fresh->variance, 2));
    }

    /** Reclassifying is how the four backdated production days get fixed. */
    public function test_switching_an_expense_to_outside_restores_the_day(): void
    {
        $closure = $this->closure();
        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload(['paid_from' => 'drawer']));
        $expense = Expense::firstOrFail();

        $this->assertSame(-15200.0, round((float) $closure->fresh()->expected_cash, 2));

        $this->actingAs($this->owner)
            ->put(route('expenses.update', $expense), $this->payload(['paid_from' => 'outside']))
            ->assertSessionHas('success');

        $this->assertSame(2000.0, round((float) $closure->fresh()->expected_cash, 2));
        $this->assertSame(0.0, round((float) $closure->fresh()->variance, 2));
    }

    /** It left the business's cash even though it never touched a till. */
    public function test_outside_paid_cash_reduces_cash_on_hand(): void
    {
        $this->closure();
        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload(['amount' => 500]));

        $totals = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))->assertOk()->viewData('totals');

        $this->assertSame(500.0, round((float) $totals['paid_outside_total'], 2));
        $this->assertSame(1500.0, round((float) $totals['cash_on_hand'], 2));
        // The day's own column still shows only what the drawer paid.
        $this->assertSame(0.0, round((float) $totals['cash_expenses_total'], 2));
    }

    /** Non-cash has no drawer to come from, so the flag is meaningless there. */
    public function test_a_non_cash_expense_is_always_recorded_as_drawer(): void
    {
        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload([
            'payment_method' => 'gcash', 'paid_from' => 'outside', 'amount' => 300,
        ]));

        $this->assertSame('drawer', Expense::firstOrFail()->paid_from);
    }

    public function test_an_unknown_paid_from_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload(['paid_from' => 'pocket']))
            ->assertSessionHasErrors('paid_from');
    }

    /** The backfill must be targetable, since a drifted day is not always one to recompute. */
    public function test_the_recalculate_command_can_target_specific_dates(): void
    {
        $closure = $this->closure();
        Expense::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'approved', 'payment_method' => 'cash',
            'paid_from' => 'drawer', 'expense_date' => $this->date, 'amount' => 300,
        ]);

        $this->artisan('closures:recalculate --date='.now()->subDays(9)->toDateString())
            ->expectsOutputToContain('0 disagree')
            ->assertSuccessful();
        $this->assertSame(2000.0, round((float) $closure->fresh()->expected_cash, 2));

        $this->artisan('closures:recalculate --apply --date='.$this->date)->assertSuccessful();
        $this->assertSame(1700.0, round((float) $closure->fresh()->expected_cash, 2));
    }
}
