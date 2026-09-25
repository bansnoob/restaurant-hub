<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Models\User;
use App\Services\CashReportService;
use App\Services\DayClosureRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A sweep of the Cash Report arithmetic, with the weight on days that were closed and
 * then edited — the path where a stored snapshot and the rows underneath it can part
 * company without anything saying so.
 *
 * Two invariants have to hold for every closed day, before and after any edit:
 *
 *   expected_cash = opening_float + cash_sales + mixed_cash - drawer_cash_expenses
 *   variance      = counted_cash - expected_cash
 *
 * And one at range level:
 *
 *   cash_on_hand  = sum(counted_cash of closed days) - cash overhead - outside-paid
 */
class CashReportArithmeticSweepTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function closure(string $date, float $opening = 1000, float $counted = 1000): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 21:30:00',
            'opening_float' => $opening,
            'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => $opening, 'counted_cash' => $counted,
            'variance' => $counted - $opening,
            'order_count' => 0, 'expense_count' => 0,
        ]);
    }

    private function sale(string $date, string $method, float $total, ?float $cash = null, ?float $gcash = null): Sale
    {
        return Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->owner->id,
            'sale_datetime' => $date.' 12:00:00',
            'status' => 'completed',
            'payment_method' => $method,
            'sub_total' => $total,
            'grand_total' => $total,
            'cash_amount' => $cash,
            'gcash_amount' => $gcash,
        ]);
    }

    private function expense(string $date, float $amount, string $method = 'cash', string $paidFrom = 'drawer'): Expense
    {
        return Expense::create([
            'branch_id' => $this->branch->id,
            'expense_date' => $date,
            'description' => 'Test',
            'amount' => $amount,
            'payment_method' => $method,
            'paid_from' => $paidFrom,
            'status' => 'approved',
            'recorded_by_user_id' => $this->owner->id,
        ]);
    }

    private function recalc(string $date): DayClosure
    {
        app(DayClosureRecalculator::class)->recalculateFor($this->branch->id, $date);

        return DayClosure::whereDate('closed_at_date', $date)->sole();
    }

    private function assertInvariants(DayClosure $c, string $context = ''): void
    {
        $expected = (float) $c->opening_float + (float) $c->cash_sales_total
            + (float) $c->mixed_cash_total - (float) $c->cash_expenses_total;

        $this->assertEqualsWithDelta($expected, (float) $c->expected_cash, 0.005,
            "expected_cash does not equal opening + cash + mixed - drawer expenses {$context}");
        $this->assertEqualsWithDelta((float) $c->counted_cash - (float) $c->expected_cash,
            (float) $c->variance, 0.005,
            "variance does not equal counted - expected {$context}");
    }

    // ---- a plain closed day ------------------------------------------------

    public function test_a_closed_day_satisfies_both_invariants(): void
    {
        $this->closure('2026-09-10');
        $this->sale('2026-09-10', 'cash', 500);
        $this->expense('2026-09-10', 120);

        $c = $this->recalc('2026-09-10');
        $this->assertInvariants($c);
        $this->assertEqualsWithDelta(1380.0, (float) $c->expected_cash, 0.005);
    }

    public function test_a_mixed_sale_splits_without_double_counting(): void
    {
        $this->closure('2026-09-10');
        $this->sale('2026-09-10', 'mixed', 300, cash: 200, gcash: 100);

        $c = $this->recalc('2026-09-10');
        $this->assertInvariants($c);
        $this->assertEqualsWithDelta(0.0, (float) $c->cash_sales_total, 0.005);
        $this->assertEqualsWithDelta(200.0, (float) $c->mixed_cash_total, 0.005);
        $this->assertEqualsWithDelta(100.0, (float) $c->gcash_sales_total, 0.005);
        $this->assertEqualsWithDelta(1200.0, (float) $c->expected_cash, 0.005);
    }

    public function test_an_outside_paid_expense_never_touches_the_drawer(): void
    {
        $this->closure('2026-09-10');
        $this->expense('2026-09-10', 500, paidFrom: 'outside');

        $c = $this->recalc('2026-09-10');
        $this->assertInvariants($c);
        $this->assertEqualsWithDelta(0.0, (float) $c->cash_expenses_total, 0.005);
        $this->assertEqualsWithDelta(1000.0, (float) $c->expected_cash, 0.005);
    }

    public function test_a_non_cash_expense_never_touches_the_drawer(): void
    {
        $this->closure('2026-09-10');
        $this->expense('2026-09-10', 400, method: 'gcash');

        $c = $this->recalc('2026-09-10');
        $this->assertInvariants($c);
        $this->assertEqualsWithDelta(1000.0, (float) $c->expected_cash, 0.005);
    }

    // ---- editing a day that was already closed -----------------------------

    private function editDay(DayClosure $c, array $rows, float $counted, array $deleted = [])
    {
        return $this->actingAs($this->owner)
            ->from(route('day-closures.index'))
            ->put(route('day-close.update', $c), [
                'counted_cash' => $counted,
                'expenses' => $rows,
                'deleted_expense_ids' => $deleted,
            ]);
    }

    public function test_editing_an_amount_on_a_closed_day_keeps_both_invariants(): void
    {
        $c = $this->closure('2026-09-10', counted: 1380);
        $this->sale('2026-09-10', 'cash', 500);
        $e = $this->expense('2026-09-10', 120);
        $this->recalc('2026-09-10');

        $this->editDay($c, [[
            'id' => $e->id, 'description' => 'Test', 'amount' => 300, 'paid_from' => 'drawer',
        ]], 1380);

        $fresh = DayClosure::whereDate('closed_at_date', '2026-09-10')->sole();
        $this->assertInvariants($fresh, '(after editing the amount)');
        $this->assertEqualsWithDelta(1200.0, (float) $fresh->expected_cash, 0.005);
        $this->assertEqualsWithDelta(180.0, (float) $fresh->variance, 0.005);
    }

    public function test_deleting_an_expense_on_a_closed_day_keeps_both_invariants(): void
    {
        $c = $this->closure('2026-09-10', counted: 1380);
        $this->sale('2026-09-10', 'cash', 500);
        $e = $this->expense('2026-09-10', 120);
        $this->recalc('2026-09-10');

        $this->editDay($c, [], 1380, [$e->id]);

        $fresh = DayClosure::whereDate('closed_at_date', '2026-09-10')->sole();
        $this->assertInvariants($fresh, '(after deleting the expense)');
        $this->assertEqualsWithDelta(1500.0, (float) $fresh->expected_cash, 0.005);
    }

    public function test_switching_a_row_to_outside_paid_returns_it_to_the_drawer(): void
    {
        $c = $this->closure('2026-09-10', counted: 1380);
        $this->sale('2026-09-10', 'cash', 500);
        $e = $this->expense('2026-09-10', 120);
        $this->recalc('2026-09-10');

        $this->editDay($c, [[
            'id' => $e->id, 'description' => 'Test', 'amount' => 120, 'paid_from' => 'outside',
        ]], 1380);

        $fresh = DayClosure::whereDate('closed_at_date', '2026-09-10')->sole();
        $this->assertInvariants($fresh, '(after switching to outside)');
        $this->assertEqualsWithDelta(1500.0, (float) $fresh->expected_cash, 0.005);
    }

    public function test_correcting_the_counted_cash_moves_only_the_variance(): void
    {
        $c = $this->closure('2026-09-10', counted: 1000);
        $this->sale('2026-09-10', 'cash', 500);
        $this->recalc('2026-09-10');

        $this->editDay($c, [], 1500);

        $fresh = DayClosure::whereDate('closed_at_date', '2026-09-10')->sole();
        $this->assertInvariants($fresh, '(after correcting the count)');
        $this->assertEqualsWithDelta(1500.0, (float) $fresh->expected_cash, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->variance, 0.005);
    }

    public function test_an_edited_day_agrees_with_a_fresh_recompute(): void
    {
        $c = $this->closure('2026-09-10', counted: 1380);
        $this->sale('2026-09-10', 'cash', 500);
        $e = $this->expense('2026-09-10', 120);
        $this->recalc('2026-09-10');

        $this->editDay($c, [
            ['id' => $e->id, 'description' => 'Test', 'amount' => 75, 'paid_from' => 'drawer'],
            ['description' => 'Added later', 'amount' => 60, 'paid_from' => 'drawer'],
        ], 1380);

        $stored = DayClosure::whereDate('closed_at_date', '2026-09-10')->sole();
        $before = [(float) $stored->expected_cash, (float) $stored->variance, (float) $stored->cash_expenses_total];

        $after = $this->recalc('2026-09-10');
        $this->assertSame($before, [
            (float) $after->expected_cash, (float) $after->variance, (float) $after->cash_expenses_total,
        ], 'the stored snapshot drifts from a fresh recompute of the same rows');
    }

    // ---- range-level -------------------------------------------------------

    public function test_cash_on_hand_subtracts_overhead_and_outside_paid(): void
    {
        $c = $this->closure('2026-09-10', counted: 2000);
        $this->recalc('2026-09-10');

        $this->expense('2026-09-10', 300, paidFrom: 'outside');
        $category = SpecialExpenseCategory::firstOrCreate(['slug' => 'rent'], ['name' => 'Rent', 'is_active' => true]);
        SpecialExpense::create([
            'branch_id' => $this->branch->id,
            'special_expense_category_id' => $category->id,
            'period_month' => '2026-09-01',
            'paid_date' => '2026-09-10',
            'description' => 'Rent',
            'amount' => 500,
            'payment_method' => 'cash',
        ]);

        $service = app(CashReportService::class);
        $rows = $service->dayRows('2026-09-01', '2026-09-30', $this->branch->id);
        $totals = $service->totals($rows, '2026-09-01', '2026-09-30', $this->branch->id);

        $this->assertEqualsWithDelta(800.0, $totals['paid_outside_total'], 0.005);
        $this->assertEqualsWithDelta(1200.0, $totals['cash_on_hand'], 0.005,
            'cash_on_hand must be counted (2000) minus overhead (500) minus outside-paid (300)');
    }

    public function test_a_non_cash_overhead_row_does_not_reduce_cash_on_hand(): void
    {
        $this->closure('2026-09-10', counted: 2000);
        $this->recalc('2026-09-10');

        $category = SpecialExpenseCategory::firstOrCreate(['slug' => 'rent'], ['name' => 'Rent', 'is_active' => true]);
        SpecialExpense::create([
            'branch_id' => $this->branch->id,
            'special_expense_category_id' => $category->id,
            'period_month' => '2026-09-01', 'paid_date' => '2026-09-10',
            'description' => 'Rent by transfer', 'amount' => 9000, 'payment_method' => 'bank_transfer',
        ]);

        $service = app(CashReportService::class);
        $rows = $service->dayRows('2026-09-01', '2026-09-30', $this->branch->id);
        $totals = $service->totals($rows, '2026-09-01', '2026-09-30', $this->branch->id);

        $this->assertEqualsWithDelta(2000.0, $totals['cash_on_hand'], 0.005);
    }

    public function test_overhead_outside_the_range_is_not_deducted(): void
    {
        $this->closure('2026-09-10', counted: 2000);
        $this->recalc('2026-09-10');

        $category = SpecialExpenseCategory::firstOrCreate(['slug' => 'rent'], ['name' => 'Rent', 'is_active' => true]);
        SpecialExpense::create([
            'branch_id' => $this->branch->id,
            'special_expense_category_id' => $category->id,
            'period_month' => '2026-08-01', 'paid_date' => '2026-08-15',
            'description' => 'August rent', 'amount' => 9000, 'payment_method' => 'cash',
        ]);

        $service = app(CashReportService::class);
        $rows = $service->dayRows('2026-09-01', '2026-09-30', $this->branch->id);
        $totals = $service->totals($rows, '2026-09-01', '2026-09-30', $this->branch->id);

        $this->assertEqualsWithDelta(2000.0, $totals['cash_on_hand'], 0.005);
    }

    // ---- the day row's own expense count -----------------------------------

    /**
     * Documents, rather than judges, what expense_count means: every approved expense
     * on the day, whatever its payment method — see GcashOutEditDuplicateTest, which
     * pins that on purpose.
     *
     * Worth knowing because the close screen prints it beside the cash-only amount as
     * "Cash expenses (N)  P X". A GCash expense adds one to N and nothing to X, so the
     * two describe different sets. No money is wrong; the label is narrower than the
     * count. Changing either is a product call, so this only holds the behaviour still.
     */
    public function test_the_expense_count_covers_every_expense_not_only_drawer_ones(): void
    {
        $this->closure('2026-09-10');
        $this->expense('2026-09-10', 100);                       // cash / drawer
        $this->expense('2026-09-10', 200, method: 'gcash');      // never touches the drawer
        $this->expense('2026-09-10', 50, paidFrom: 'outside');   // cash, but not from the till

        $c = $this->recalc('2026-09-10');

        $this->assertSame(3, $c->expense_count, 'expense_count counts every approved expense on the day');
        $this->assertEqualsWithDelta(100.0, (float) $c->cash_expenses_total, 0.005,
            'only the drawer-paid cash row reaches the amount');
        $this->assertInvariants($c);
    }
}
