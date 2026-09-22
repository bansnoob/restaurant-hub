<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SCRATCH VERIFICATION ONLY.
 *
 * The adjacency claim asserts 438 was on 2026-09-11 and the 23:29:58 edit MOVED it to
 * 2026-09-12. Nothing in this codebase audits expense_date, so that premise cannot be read
 * back off the `expenses` row. But ExpenseController::update calls
 * DayClosureRecalculator::recalculateForMove, which leaves a fingerprint in `day_closures`:
 *
 *   - a MOVE recomputes BOTH the origin and destination closures, and their expense_count
 *     really changes, so both rows are dirty and both updated_at bump;
 *   - an edit that does NOT move the row recomputes only the one day, and for a GCASH
 *     expense every recomputed figure is identical, so Eloquent writes nothing at all.
 *
 * These two tests pin that fingerprint down so a single read-only query on production settles
 * the claim's premise.
 */
class GcashOutMoveFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function closure(string $date, int $expenseCount): DayClosure
    {
        $c = DayClosure::create([
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
            'expense_count' => $expenseCount,
        ]);

        // Age the closure so any write during the edit is unmistakable.
        DayClosure::where('id', $c->id)->update(['updated_at' => '2026-09-12 22:00:00']);

        return $c->fresh();
    }

    private function drawerPayload(Expense $e, string $date, string $amount): array
    {
        return [
            '_method' => 'PUT',
            'branch_id' => (string) $e->branch_id,
            'expense_date' => $date,
            'description' => 'GCash out',
            'amount' => $amount,
            'payment_method' => 'gcash',
            'expense_category_id' => '',
            'vendor_name' => '',
            'reference_no' => '',
            'notes' => '',
        ];
    }

    private function makeExpense(string $date, float $amount): Expense
    {
        return Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => null,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => $date,
            'description' => 'GCash out',
            'amount' => $amount,
            'vendor_name' => null,
            'reference_no' => null,
            'notes' => null,
            'status' => 'approved',
        ]);
    }

    /** FINGERPRINT OF A MOVE: both days' closures are rewritten. */
    public function test_a_date_move_bumps_both_closures(): void
    {
        $e438 = $this->makeExpense('2026-09-11', 500.00);
        $this->makeExpense('2026-09-12', 1123.00);           // the 439 sibling

        $sep11 = $this->closure('2026-09-11', 1);
        $sep12 = $this->closure('2026-09-12', 1);

        Carbon::setTestNow('2026-09-20 23:29:58');
        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->post(route('expenses.update', $e438), $this->drawerPayload($e438, '2026-09-12', '500.00'))
            ->assertSessionHasNoErrors();
        Carbon::setTestNow();

        $sep11->refresh();
        $sep12->refresh();

        fwrite(STDERR, "\n--- MOVE 2026-09-11 -> 2026-09-12 ---\n");
        fwrite(STDERR, "  sep11 closure: expense_count={$sep11->expense_count} updated_at={$sep11->updated_at}\n");
        fwrite(STDERR, "  sep12 closure: expense_count={$sep12->expense_count} updated_at={$sep12->updated_at}\n");

        $this->assertSame(0, $sep11->expense_count, 'origin day loses the row');
        $this->assertSame(2, $sep12->expense_count, 'destination day gains it');
        $this->assertSame('2026-09-20 23:29:58', $sep11->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 23:29:58', $sep12->updated_at->format('Y-m-d H:i:s'));
    }

    /** FINGERPRINT OF NO MOVE: an in-place GCash edit rewrites nothing. */
    public function test_an_in_place_gcash_edit_leaves_the_closure_untouched(): void
    {
        $e438 = $this->makeExpense('2026-09-12', 500.00);
        $this->makeExpense('2026-09-12', 1123.00);

        $sep11 = $this->closure('2026-09-11', 0);
        $sep12 = $this->closure('2026-09-12', 2);

        Carbon::setTestNow('2026-09-20 23:29:58');
        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->post(route('expenses.update', $e438), $this->drawerPayload($e438, '2026-09-12', '650.00'))
            ->assertSessionHasNoErrors();
        Carbon::setTestNow();

        $e438->refresh();
        $sep11->refresh();
        $sep12->refresh();

        fwrite(STDERR, "\n--- IN-PLACE EDIT, amount 500.00 -> 650.00, date unchanged ---\n");
        fwrite(STDERR, "  expense updated_at={$e438->updated_at}\n");
        fwrite(STDERR, "  sep11 closure: expense_count={$sep11->expense_count} updated_at={$sep11->updated_at}\n");
        fwrite(STDERR, "  sep12 closure: expense_count={$sep12->expense_count} updated_at={$sep12->updated_at}\n\n");

        $this->assertSame(650.00, (float) $e438->amount, 'the edit did land');
        $this->assertSame(
            '2026-09-12 22:00:00',
            $sep12->updated_at->format('Y-m-d H:i:s'),
            'nothing recomputed differently, so the closure must not be rewritten'
        );
        $this->assertSame('2026-09-12 22:00:00', $sep11->updated_at->format('Y-m-d H:i:s'));
    }
}
