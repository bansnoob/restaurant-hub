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
 * A closed day is corrected, never reopened.
 *
 * Reopening deleted the closure, which threw away the record of the day having been
 * closed at all and was the only way to fix a mistake. Editing keeps the record and
 * recomputes the figures from the rows that now exist.
 */
class DayClosureEditTest extends TestCase
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
    }

    private function sale(float $amount): Sale
    {
        return Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00',
            'closed_at' => $this->date.' 12:00:00',
            'grand_total' => $amount,
        ]);
    }

    private function expense(float $amount, string $description = 'Supplies'): Expense
    {
        return Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'approved',
            'payment_method' => 'cash',
            'expense_date' => $this->date,
            'description' => $description,
            'amount' => $amount,
        ]);
    }

    private function closure(array $attributes = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $this->date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 1000,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 200,
            'expected_cash' => 800,
            'counted_cash' => 800,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 1,
        ], $attributes));
    }

    public function test_the_reopen_route_is_gone(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('day-close.destroy'),
            'day-close.destroy still exists; a closure can still be deleted.'
        );
    }

    public function test_the_cash_report_offers_edit_not_reopen(): void
    {
        $this->sale(1000);
        $this->expense(200);
        $this->closure();

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Edit Day')
            ->assertDontSee('Reopen');
    }

    public function test_edit_returns_the_days_state_with_its_cash_expenses(): void
    {
        $this->sale(1000);
        $this->expense(200, 'Ice');
        $closure = $this->closure();

        $payload = $this->actingAs($this->owner)
            ->getJson(route('day-close.edit', $closure))
            ->assertOk()
            ->json();

        $this->assertSame($this->date, $payload['date']);
        $this->assertSame(1000.0, round((float) $payload['cash_sales_total'], 2));
        $this->assertCount(1, $payload['expenses']);
        $this->assertSame('Ice', $payload['expenses'][0]['description']);
    }

    /** Non-cash expenses never reached the drawer, so they are not offered for edit. */
    public function test_edit_only_returns_cash_expenses(): void
    {
        $this->sale(1000);
        $this->expense(200, 'Ice');
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'approved',
            'payment_method' => 'gcash',
            'expense_date' => $this->date,
            'description' => 'Load',
            'amount' => 999,
        ]);
        $closure = $this->closure();

        $payload = $this->actingAs($this->owner)
            ->getJson(route('day-close.edit', $closure))->assertOk()->json();

        $this->assertCount(1, $payload['expenses']);
    }

    public function test_editing_counted_cash_recomputes_variance(): void
    {
        $this->sale(1000);
        $this->expense(200);
        $closure = $this->closure();

        $this->actingAs($this->owner)
            ->put(route('day-close.update', $closure), ['counted_cash' => 750])
            ->assertRedirect();

        $fresh = $closure->fresh();
        $this->assertSame(750.0, round((float) $fresh->counted_cash, 2));
        $this->assertSame(800.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(-50.0, round((float) $fresh->variance, 2));
    }

    public function test_adding_an_expense_lowers_expected_and_moves_variance(): void
    {
        $this->sale(1000);
        $this->expense(200);
        $closure = $this->closure();

        $this->actingAs($this->owner)->put(route('day-close.update', $closure), [
            'counted_cash' => 800,
            'expenses' => [
                ['id' => Expense::firstOrFail()->id, 'description' => 'Supplies', 'amount' => 200],
                ['description' => 'Forgotten ice', 'amount' => 150],
            ],
        ])->assertRedirect();

        $fresh = $closure->fresh();
        $this->assertSame(350.0, round((float) $fresh->cash_expenses_total, 2));
        $this->assertSame(650.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(150.0, round((float) $fresh->variance, 2));
        $this->assertSame(2, (int) $fresh->expense_count);
    }

    public function test_deleting_an_expense_raises_expected(): void
    {
        $this->sale(1000);
        $expense = $this->expense(200);
        $closure = $this->closure();

        $this->actingAs($this->owner)->put(route('day-close.update', $closure), [
            'counted_cash' => 800,
            'deleted_expense_ids' => [$expense->id],
        ])->assertRedirect();

        $fresh = $closure->fresh();
        $this->assertSame(0.0, round((float) $fresh->cash_expenses_total, 2));
        $this->assertSame(1000.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(-200.0, round((float) $fresh->variance, 2));
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    /** An id from another day must not be deletable through this form. */
    public function test_an_expense_from_another_day_cannot_be_deleted(): void
    {
        $this->sale(1000);
        $closure = $this->closure();
        $otherDay = Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'approved',
            'payment_method' => 'cash',
            'expense_date' => now()->subDays(10)->toDateString(),
            'amount' => 500,
        ]);

        $this->actingAs($this->owner)->put(route('day-close.update', $closure), [
            'counted_cash' => 800,
            'deleted_expense_ids' => [$otherDay->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('expenses', ['id' => $otherDay->id]);
    }

    /** Expected is a function of the rows, never of what the form claimed. */
    public function test_expected_cash_cannot_be_driven_from_the_request(): void
    {
        $this->sale(1000);
        $this->expense(200);
        $closure = $this->closure();

        $this->actingAs($this->owner)->put(route('day-close.update', $closure), [
            'counted_cash' => 800,
            'expected_cash' => 999999,
            'cash_sales_total' => 999999,
        ])->assertRedirect();

        $this->assertSame(800.0, round((float) $closure->fresh()->expected_cash, 2));
    }

    public function test_the_opening_float_is_preserved_across_an_edit(): void
    {
        $this->sale(1000);
        $this->expense(200);
        $closure = $this->closure(['opening_float' => 500, 'expected_cash' => 1300]);

        $this->actingAs($this->owner)
            ->put(route('day-close.update', $closure), ['counted_cash' => 1300])
            ->assertRedirect();

        $fresh = $closure->fresh();
        $this->assertSame(500.0, round((float) $fresh->opening_float, 2));
        $this->assertSame(1300.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(0.0, round((float) $fresh->variance, 2));
    }

    public function test_a_cashier_cannot_edit_a_closed_day(): void
    {
        $this->sale(1000);
        $closure = $this->closure();
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->getJson(route('day-close.edit', $closure))->assertForbidden();
        $this->actingAs($cashier)
            ->put(route('day-close.update', $closure), ['counted_cash' => 1])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_edit_a_closed_day(): void
    {
        $this->sale(1000);
        $closure = $this->closure();

        $this->put(route('day-close.update', $closure), ['counted_cash' => 1])
            ->assertRedirect(route('login'));
    }
}
