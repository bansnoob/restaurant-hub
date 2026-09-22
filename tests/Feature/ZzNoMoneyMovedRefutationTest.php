<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Services\DayClosureRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCRATCH VERIFICATION ONLY — adversarial test of the "no money moved" claim.
 */
class ZzNoMoneyMovedRefutationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function closure(string $date, array $overrides = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
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
        ], $overrides));
    }

    /** A: does a gcash move really leave the cash money columns identical? */
    public function test_gcash_move_leaves_cash_money_columns_identical(): void
    {
        $from = $this->closure('2026-09-11');
        $to = $this->closure('2026-09-12');

        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => '2026-09-11',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
            'paid_from' => 'drawer',
            'status' => 'approved',
        ]);

        app(DayClosureRecalculator::class)->recalculateFor($this->branch->id, '2026-09-11');
        app(DayClosureRecalculator::class)->recalculateFor($this->branch->id, '2026-09-12');
        $fromBefore = $from->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);
        $toBefore = $to->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);

        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-12',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $fromAfter = $from->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);
        $toAfter = $to->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);

        fwrite(STDERR, "\nA) from 09-11: ".json_encode($fromBefore).' -> '.json_encode($fromAfter));
        fwrite(STDERR, "\nA) to   09-12: ".json_encode($toBefore).' -> '.json_encode($toAfter)."\n");

        $this->assertSame($fromBefore['cash_expenses_total'], $fromAfter['cash_expenses_total']);
        $this->assertSame($fromBefore['expected_cash'], $fromAfter['expected_cash']);
        $this->assertSame($fromBefore['variance'], $fromAfter['variance']);
        $this->assertNotSame($fromBefore['expense_count'], $fromAfter['expense_count']);
    }

    /** B: the SAME write also rewrites gcash_sales_total and order_count from live rows. */
    public function test_same_recalculate_also_rewrites_gcash_sales_and_order_count(): void
    {
        // Stale snapshot: closure says 0 gcash sales / 0 orders, but a gcash sale exists.
        $closure = $this->closure('2026-09-12', ['gcash_sales_total' => 0, 'order_count' => 0]);

        Sale::create([
            'branch_id' => $this->branch->id,
            'order_number' => 'T-1',
            'sale_datetime' => '2026-09-12 18:00:00',
            'cashier_user_id' => $this->owner->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 777, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 777, 'paid_total' => 777, 'change_total' => 0,
            'payment_method' => 'gcash', 'gcash_amount' => 777,
        ]);

        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => '2026-09-11',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
            'paid_from' => 'drawer',
            'status' => 'approved',
        ]);

        $before = $closure->fresh()->only(['gcash_sales_total', 'order_count', 'expense_count', 'cash_expenses_total', 'expected_cash']);

        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-12',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $after = $closure->fresh()->only(['gcash_sales_total', 'order_count', 'expense_count', 'cash_expenses_total', 'expected_cash']);

        fwrite(STDERR, "\nB) 09-12 closure: ".json_encode($before).' -> '.json_encode($after)."\n");

        // "changed expense_count only" is NOT a property of the code.
        $this->assertNotEquals($before['gcash_sales_total'], $after['gcash_sales_total'], 'gcash_sales_total was rewritten too');
        $this->assertNotEquals($before['order_count'], $after['order_count'], 'order_count was rewritten too');
    }

    /** C: what the day closure cannot see — the GCash report for each day moved by P500. */
    public function test_gcash_report_per_day_figures_do_change(): void
    {
        $this->closure('2026-09-11');
        $this->closure('2026-09-12');

        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => '2026-09-11',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
            'paid_from' => 'drawer',
            'status' => 'approved',
        ]);

        $day11 = fn () => $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['date_from' => '2026-09-11', 'date_to' => '2026-09-11', 'branch_id' => $this->branch->id]))
            ->viewData('totals');
        $day12 = fn () => $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12', 'branch_id' => $this->branch->id]))
            ->viewData('totals');

        $b11 = $day11()['gcash_expenses_total'];
        $b12 = $day12()['gcash_expenses_total'];

        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-12',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $a11 = $day11()['gcash_expenses_total'];
        $a12 = $day12()['gcash_expenses_total'];

        fwrite(STDERR, "\nC) GCash Out 09-11: {$b11} -> {$a11}   |   09-12: {$b12} -> {$a12}\n");

        $this->assertSame(500.0, (float) $b11);
        $this->assertSame(0.0, (float) $a11);
        $this->assertSame(0.0, (float) $b12);
        $this->assertSame(500.0, (float) $a12);
    }
}
