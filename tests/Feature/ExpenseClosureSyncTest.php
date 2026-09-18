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
 * The Expenses page never blocked edits to a closed day and nothing refreshed the
 * closure afterwards, so the stored figures quietly stopped describing the day. That
 * had already happened to 8 of 80 production closures, three of them by more than
 * P15,000. A closure now follows its rows wherever they are changed from.
 */
class ExpenseClosureSyncTest extends TestCase
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
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00',
            'closed_at' => $this->date.' 12:00:00',
            'grand_total' => 1000,
        ]);
    }

    private function closure(?string $date = null): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date ?? $this->date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => ($date ?? $this->date).' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 1000,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 1000,
            'counted_cash' => 1000,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => $this->date,
            'description' => 'Ice',
            'amount' => 150,
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_adding_an_expense_to_a_closed_day_updates_the_closure(): void
    {
        $closure = $this->closure();

        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload())
            ->assertSessionHas('success');

        $fresh = $closure->fresh();
        $this->assertSame(150.0, round((float) $fresh->cash_expenses_total, 2));
        $this->assertSame(850.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(150.0, round((float) $fresh->variance, 2));
    }

    public function test_editing_an_expense_on_a_closed_day_updates_the_closure(): void
    {
        $closure = $this->closure();
        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());
        $expense = Expense::firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('expenses.update', $expense), $this->payload(['amount' => 400]))
            ->assertSessionHas('success');

        $this->assertSame(400.0, round((float) $closure->fresh()->cash_expenses_total, 2));
        $this->assertSame(600.0, round((float) $closure->fresh()->expected_cash, 2));
    }

    public function test_deleting_an_expense_on_a_closed_day_updates_the_closure(): void
    {
        $closure = $this->closure();
        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());
        $expense = Expense::firstOrFail();

        $this->actingAs($this->owner)
            ->delete(route('expenses.destroy', $expense))
            ->assertSessionHas('success');

        $this->assertSame(0.0, round((float) $closure->fresh()->cash_expenses_total, 2));
        $this->assertSame(1000.0, round((float) $closure->fresh()->expected_cash, 2));
    }

    /**
     * Moving an expense off a closed day must fix BOTH days. Recomputing only where it
     * landed leaves the day it left permanently overstating its expenses.
     */
    public function test_moving_an_expense_between_two_closed_days_fixes_both(): void
    {
        $otherDate = now()->subDays(2)->toDateString();
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $otherDate.' 12:00:00',
            'closed_at' => $otherDate.' 12:00:00',
            'grand_total' => 1000,
        ]);

        $from = $this->closure();
        $to = $this->closure($otherDate);

        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());
        $expense = Expense::firstOrFail();
        $this->assertSame(150.0, round((float) $from->fresh()->cash_expenses_total, 2));

        $this->actingAs($this->owner)
            ->put(route('expenses.update', $expense), $this->payload(['expense_date' => $otherDate]))
            ->assertSessionHas('success');

        $this->assertSame(0.0, round((float) $from->fresh()->cash_expenses_total, 2), 'origin day still claims the expense');
        $this->assertSame(150.0, round((float) $to->fresh()->cash_expenses_total, 2), 'destination day did not pick it up');
    }

    /** A non-cash expense does not touch the drawer, so expected must not move. */
    public function test_a_gcash_expense_does_not_change_expected_cash(): void
    {
        $closure = $this->closure();

        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload(['payment_method' => 'gcash']))
            ->assertSessionHas('success');

        $this->assertSame(1000.0, round((float) $closure->fresh()->expected_cash, 2));
        $this->assertSame(0.0, round((float) $closure->fresh()->cash_expenses_total, 2));
    }

    /** Counted cash is a human observation and is never recomputed away. */
    public function test_counted_cash_survives_a_recompute(): void
    {
        $closure = $this->closure();
        $closure->update(['counted_cash' => 977]);

        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());

        $this->assertSame(977.0, round((float) $closure->fresh()->counted_cash, 2));
    }

    /** An expense on an open day must not conjure a closure. */
    public function test_an_expense_on_an_open_day_creates_no_closure(): void
    {
        $this->actingAs($this->owner)
            ->post(route('expenses.store'), $this->payload())
            ->assertSessionHas('success');

        $this->assertSame(0, DayClosure::count());
    }
}
