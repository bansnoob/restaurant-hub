<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use App\Services\DayClosureRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SCRATCH VERIFICATION ONLY - delete after reading.
 *
 * Adversarial check of the "no money moved / nothing to unwind" claim, whose entire
 * evidence base is day_closures rows.
 */
class ZzNoMoneyMovedClaimRefutationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Branch $branch;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->date = '2026-09-12';
    }

    private function closure(string $date, array $over = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 0,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'variance' => 0,
            'order_count' => 0,
            'expense_count' => 0,
        ], $over));
    }

    private function gcashExpense(string $date, float $amount, string $desc = 'GCash out'): Expense
    {
        return Expense::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => $date,
            'description' => $desc,
            'amount' => $amount,
            'payment_method' => 'gcash',
            'paid_from' => 'drawer',
            'status' => 'approved',
        ]);
    }

    /** A) The GCash report's own money DOES move, while the closure's money columns do not. */
    public function test_gcash_report_money_moves_even_though_closure_money_columns_are_frozen(): void
    {
        $this->closure($this->date);
        $e = $this->gcashExpense($this->date, 500);
        app(DayClosureRecalculator::class)->recalculateFor($this->branch->id, $this->date);

        $before = DayClosure::first()->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);
        $beforeTotals = $this->reportTotals();

        $this->actingAs($this->owner)->put(route('expenses.update', $e), [
            'branch_id' => $this->branch->id,
            'expense_date' => $this->date,
            'description' => 'GCash out',
            'amount' => 5000,               // 500 -> 5000
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $after = DayClosure::first()->fresh()->only(['cash_expenses_total', 'expected_cash', 'variance', 'expense_count']);
        $afterTotals = $this->reportTotals();

        // The claim's mechanism holds: not one closure figure moved, not even expense_count.
        $this->assertSame($before, $after, 'closure figures identical');

        // But the page the user was on moved by P4,500.
        fwrite(STDERR, "\n[A] closure before/after: ".json_encode($before).' / '.json_encode($after));
        fwrite(STDERR, "\n[A] gcash report before: ".json_encode($beforeTotals)."\n[A] gcash report after:  ".json_encode($afterTotals)."\n");
        $this->assertNotEquals($beforeTotals['gcash_expenses_total'], $afterTotals['gcash_expenses_total']);
        $this->assertNotEquals($beforeTotals['net_gcash'], $afterTotals['net_gcash']);
        $this->assertNotEquals($beforeTotals['wallet_balance'], $afterTotals['wallet_balance']);
    }

    /** B) "stored matches a live recompute" still holds with a real duplicate row present. */
    public function test_stored_vs_live_recompute_cannot_detect_a_duplicate(): void
    {
        $this->closure($this->date);
        $this->gcashExpense($this->date, 500);
        $rec = app(DayClosureRecalculator::class);
        $rec->recalculateFor($this->branch->id, $this->date);

        // A true duplicate lands, and any write path recomputes the day.
        $this->gcashExpense($this->date, 500);
        $rec->recalculateFor($this->branch->id, $this->date);

        $stored = DayClosure::first()->fresh();
        $live = $rec->totalsFor($this->branch->id, $this->date);

        fwrite(STDERR, "\n[B] duplicate present. stored cash_exp={$stored->cash_expenses_total} exp_ct={$stored->expense_count}"
            ." | live {$live['cash_expenses_total']} / {$live['expense_count']}"
            .' | rows='.Expense::count()."\n");

        $this->assertSame(2, Expense::count(), 'the duplicate really is there');
        $this->assertEquals($live['cash_expenses_total'], (float) $stored->cash_expenses_total, 'in step');
        $this->assertEquals($live['expense_count'], $stored->expense_count, 'in step');
        $this->assertEquals(0.0, (float) $stored->cash_expenses_total, 'and no money moved');
    }

    /** C) A duplicate on a day with no closure writes nothing at all to day_closures. */
    public function test_duplicate_on_an_unclosed_day_leaves_no_trace_in_day_closures(): void
    {
        $this->closure($this->date);
        $rec = app(DayClosureRecalculator::class);
        $before = DayClosure::first()->fresh()->toArray();

        // Duplicate created via the real controller, dated on an unclosed day.
        $this->actingAs($this->owner)->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-20',
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $after = DayClosure::first()->fresh()->toArray();
        fwrite(STDERR, "\n[C] closures written: ".(DayClosure::count())." rows; changed=".var_export($before !== $after, true)
            .'; expenses now='.Expense::count()."\n");

        $this->assertSame(1, Expense::count());
        $this->assertSame($before, $after, 'a whole new expense row, and day_closures is untouched');
    }

    private function reportTotals(): array
    {
        $res = $this->actingAs($this->owner)->get(route('gcash-report.index', [
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
        ]));
        $res->assertOk();
        $t = $res->viewData('totals');
        $w = $res->viewData('wallet');

        return [
            'gcash_expenses_total' => $t['gcash_expenses_total'],
            'net_gcash' => $t['net_gcash'],
            'wallet_balance' => $w['balance'],
            'wallet_outflow' => $w['outflow'],
        ];
    }
}
