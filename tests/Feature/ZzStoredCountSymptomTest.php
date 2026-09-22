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
 * Symptom probe for the "stale expense_count corrected" claim.
 *
 * The claim's stated symptom is "the order/expense counts SHOWN for 2026-09-11
 * changed". These tests ask whether day_closures.expense_count / order_count are
 * shown anywhere at all.
 */
class ZzStoredCountSymptomTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');
        $this->branch = Branch::factory()->create(['name' => 'Main Branch']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function seedDay(string $date): DayClosure
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'completed',
            'payment_method' => 'cash', 'sale_datetime' => $date.' 12:00:00',
            'closed_at' => $date.' 12:00:00', 'grand_total' => 12260,
        ]);
        // Five approved expenses on the day, mirroring the live count on prod 09-11.
        foreach ([555, 0, 0, 0, 0] as $i => $amount) {
            Expense::factory()->create([
                'branch_id' => $this->branch->id, 'status' => 'approved',
                'payment_method' => $i === 0 ? 'cash' : 'gcash',
                'paid_from' => 'drawer',
                'expense_date' => $date, 'amount' => $amount ?: 500,
                'description' => 'GCash out',
            ]);
        }

        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 20:40:45',
            'opening_float' => 0, 'cash_sales_total' => 12260, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 555,
            'expected_cash' => 11705, 'counted_cash' => 11700, 'variance' => -5,
            'order_count' => 18, 'expense_count' => 3, // <- the stale value from prod
        ]);
    }

    /**
     * A: the exact prod transition (expense_count 3 -> 5, every money column held)
     * applied to the stored row, and every user-facing surface compared before/after.
     */
    public function test_the_prod_count_only_transition_changes_nothing_a_user_sees(): void
    {
        $date = now()->subDays(9)->toDateString();
        $closure = $this->seedDay($date);

        $surfaces = fn () => [
            'cash_report' => $this->actingAs($this->owner)->get(route('day-closures.index'))->getContent(),
            'edit_day' => $this->actingAs($this->owner)->get(route('day-close.edit', $closure))->getContent(),
            'gcash_report' => $this->actingAs($this->owner)->get(route('gcash-report.index'))->getContent(),
        ];

        $before = $surfaces();

        // Exactly what the binlog shows at 23:29:45: @15 3 -> 5, nothing else.
        DayClosure::where('id', $closure->id)->update(['expense_count' => 5]);

        $after = $surfaces();

        foreach ($before as $name => $html) {
            $this->assertSame(
                $this->strip($html),
                $this->strip($after[$name]),
                "Surface [$name] rendered differently after expense_count 3 -> 5."
            );
        }
    }

    /** B: even an absurd stored count is invisible — proving the column is never read. */
    public function test_a_wildly_wrong_stored_count_is_still_invisible(): void
    {
        $date = now()->subDays(8)->toDateString();
        $closure = $this->seedDay($date);

        $before = $this->strip($this->actingAs($this->owner)->get(route('day-closures.index'))->getContent());
        $editBefore = $this->actingAs($this->owner)->get(route('day-close.edit', $closure))->json();

        DayClosure::where('id', $closure->id)->update([
            'expense_count' => 9999, 'order_count' => 8888,
        ]);

        $after = $this->strip($this->actingAs($this->owner)->get(route('day-closures.index'))->getContent());
        $editAfter = $this->actingAs($this->owner)->get(route('day-close.edit', $closure))->json();

        $this->assertSame($before, $after, 'Cash Report changed.');
        $this->assertSame($editBefore, $editAfter, 'Edit Day payload changed.');
        $this->assertStringNotContainsString('9999', $after);
        $this->assertStringNotContainsString('8888', $after);
        // And the Edit Day drawer reports the LIVE order count, not the stored 8888.
        $this->assertSame(1, $editAfter['order_count']);
    }

    /**
     * C: the row count the user actually looks at. The GCash Out list is rendered
     * straight from expenses rows, so it can only ever show as many rows as exist.
     */
    public function test_the_gcash_out_list_row_count_is_unaffected_by_the_stored_count(): void
    {
        $date = now()->subDays(7)->toDateString();
        $closure = $this->seedDay($date);

        $rowsFor = function () {
            $html = $this->actingAs($this->owner)
                ->get(route('gcash-report.index', ['date_from' => now()->subDays(30)->toDateString(), 'date_to' => now()->toDateString()]))
                ->getContent();

            return substr_count($html, 'GCash out');
        };

        $before = $rowsFor();
        DayClosure::where('id', $closure->id)->update(['expense_count' => 5]);
        $after = $rowsFor();

        $this->assertSame($before, $after, 'The GCash Out list gained or lost a row.');
        fwrite(STDERR, "\n[C] GCash Out 'GCash out' occurrences: before={$before} after={$after}\n");
    }

    private function strip(string $html): string
    {
        // CSRF tokens and session-derived nonces differ per request.
        $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="X"', $html);
        $html = preg_replace('/content="[A-Za-z0-9]{40}"/', 'content="X"', $html);

        return $html;
    }
}
