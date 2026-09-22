<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Replays the production 23:29:45 request: closure for 09-11 snapshotted expense_count=3,
 * three GCash rows were later backdated onto 09-11 (true count 6), then one of those three
 * (id 438) was edited off 09-11 onto 09-10 in the same request that recalculated.
 */
class ZzDriftVsMoveRefutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_written_count_is_post_move_not_the_drifted_truth(): void
    {
        Role::findOrCreate('owner');
        $branch = Branch::factory()->create();
        $owner = User::factory()->create(['branch_id' => $branch->id]);
        $owner->assignRole('owner');

        $d11 = '2026-09-11';
        $d10 = '2026-09-10';

        // 18 completed cash sales totalling 12,260 -- the day's orders.
        for ($i = 0; $i < 18; $i++) {
            \App\Models\Sale::factory()->create([
                'branch_id' => $branch->id, 'status' => 'completed',
                'payment_method' => 'cash', 'sale_datetime' => $d11.' 12:00:00',
                'grand_total' => $i === 0 ? 12260 - 17 * 100 : 100,
            ]);
        }

        // The 3 cash rows that existed when 09-11 was closed.
        foreach ([355, 100, 100] as $amt) {
            Expense::factory()->create([
                'branch_id' => $branch->id, 'status' => 'approved',
                'payment_method' => 'cash', 'paid_from' => 'drawer',
                'expense_date' => $d11, 'amount' => $amt,
            ]);
        }

        $closure11 = DayClosure::create([
            'branch_id' => $branch->id, 'closed_at_date' => $d11,
            'closed_at' => $d11.' 20:40:45', 'opening_float' => 0,
            'cash_sales_total' => 12260, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 555, 'expected_cash' => 11705,
            'counted_cash' => 11700, 'variance' => -5,
            'order_count' => 18, 'expense_count' => 3,
        ]);
        // The 8 rows already on 09-10.
        for ($i = 0; $i < 8; $i++) {
            Expense::factory()->create([
                'branch_id' => $branch->id, 'status' => 'approved',
                'payment_method' => 'cash', 'paid_from' => 'drawer',
                'expense_date' => $d10, 'amount' => 10,
            ]);
        }

        $closure10 = DayClosure::create([
            'branch_id' => $branch->id, 'closed_at_date' => $d10,
            'closed_at' => $d10.' 21:22:10', 'opening_float' => 0,
            'order_count' => 22, 'expense_count' => 8, 'cash_sales_total' => 12183, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0, 'cash_expenses_total' => 1193, 'expected_cash' => 10990, 'counted_cash' => 10700, 'variance' => -290,
        ]);

        // Three GCash rows backdated onto 09-11 AFTER the close, with no recalc existing.
        $r454 = Expense::factory()->create(['branch_id' => $branch->id, 'status' => 'approved', 'payment_method' => 'gcash', 'paid_from' => 'drawer', 'expense_date' => $d11, 'amount' => 478]);
        $r455 = Expense::factory()->create(['branch_id' => $branch->id, 'status' => 'approved', 'payment_method' => 'gcash', 'paid_from' => 'drawer', 'expense_date' => $d11, 'amount' => 500]);
        $r438 = Expense::factory()->create(['branch_id' => $branch->id, 'status' => 'approved', 'payment_method' => 'gcash', 'paid_from' => 'drawer', 'expense_date' => $d11, 'amount' => 500, 'description' => 'GCash out']);

        // The true, pre-edit count for 09-11 is 6 -- not 5.
        $this->assertSame(6, Expense::where('branch_id', $branch->id)->whereDate('expense_date', $d11)->count());

        // Tonight's request: move 438 from 09-11 to 09-10.
        $this->actingAs($owner)->put(route('expenses.update', $r438), [
            'branch_id' => $branch->id,
            'expense_date' => $d10,
            'description' => 'GCash out',
            'amount' => 500,
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $closure11->refresh();
        $closure10->refresh();

        // The single write folds +3 of pre-existing drift and -1 for tonight's move.
        $this->assertSame(5, (int) $closure11->expense_count, 'written value is the POST-move count');
        $this->assertSame(18, (int) $closure11->order_count, 'order_count never moved');
        $this->assertSame('11705.00', (string) $closure11->expected_cash, 'money untouched -- GCash rows');
        $this->assertSame(9, (int) $closure10->expense_count);
    }
}
