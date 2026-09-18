<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Monthly overhead settled in cash leaves the business's cash, so Cash on Hand is
 * reported net of it.
 *
 * The load-bearing half of these tests is what must NOT happen: overhead must never
 * reach a day's expected_cash or variance. A month's rent charged to the day it was
 * handed over throws that day's drawer by the full rent amount and the closing cashier
 * wears it — on 2026-09-02 in production that would have turned a clean +P278 into
 * +P16,278 against a drawer that only ever held P1,000.
 */
class CashReportOverheadTest extends TestCase
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

    private function closedDay(string $date, float $counted = 1000): DayClosure
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $date.' 12:00:00',
            'closed_at' => $date.' 12:00:00',
            'grand_total' => $counted,
        ]);

        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => $counted,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => $counted,
            'counted_cash' => $counted,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 0,
        ]);
    }

    private function overhead(array $attributes = []): SpecialExpense
    {
        return SpecialExpense::create(array_merge([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'paid_date' => now()->subDay()->toDateString(),
            'description' => 'Rent',
            'amount' => 400,
            'payment_method' => 'cash',
        ], $attributes));
    }

    private function totals(array $query = []): array
    {
        return $this->actingAs($this->owner)
            ->get(route('day-closures.index', $query))
            ->assertOk()
            ->viewData('totals');
    }

    public function test_cash_overhead_is_deducted_from_cash_on_hand(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 400]);

        $totals = $this->totals();

        $this->assertSame(600.0, round((float) $totals['cash_on_hand'], 2));
        $this->assertSame(400.0, round((float) $totals['cash_overhead_total'], 2));
    }

    /** THE trap. Overhead is a range position, never a day's reconciliation. */
    public function test_overhead_never_touches_a_days_expected_or_variance(): void
    {
        $date = now()->subDay()->toDateString();
        $closure = $this->closedDay($date, 1000);
        $this->overhead(['amount' => 16000, 'paid_date' => $date]);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('closures');

        $row = collect($rows->items())->firstWhere('date', $date);

        $this->assertSame(1000.0, round((float) $row['expected_cash'], 2));
        $this->assertSame(0.0, round((float) $row['variance'], 2));
        // And the stored closure is untouched.
        $this->assertSame(0.0, round((float) $closure->fresh()->variance, 2));
    }

    /** An unclosed day must not sprout from an overhead payment either. */
    public function test_overhead_alone_does_not_create_a_day_row(): void
    {
        $this->overhead(['amount' => 500, 'paid_date' => now()->subDays(3)->toDateString()]);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('closures');

        $this->assertCount(0, $rows->items());
    }

    /** Only cash leaves cash. Bank transfer and GCash settle elsewhere. */
    public function test_only_cash_paid_overhead_is_deducted(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 100, 'payment_method' => 'bank_transfer']);
        $this->overhead(['amount' => 200, 'payment_method' => 'gcash']);
        $this->overhead(['amount' => 50, 'payment_method' => 'cash']);

        $totals = $this->totals();

        $this->assertSame(50.0, round((float) $totals['cash_overhead_total'], 2));
        $this->assertSame(950.0, round((float) $totals['cash_on_hand'], 2));
    }

    /** Settlement-dated: overhead paid outside the window is not this window's cash. */
    public function test_overhead_outside_the_range_is_not_deducted(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead([
            'amount' => 700,
            'period_month' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'paid_date' => now()->subMonths(6)->toDateString(),
        ]);

        $totals = $this->totals();

        $this->assertSame(0.0, round((float) $totals['cash_overhead_total'], 2));
        $this->assertSame(1000.0, round((float) $totals['cash_on_hand'], 2));
    }

    /** An unpaid bill has not left the cash yet; it falls back to its period month. */
    public function test_an_unpaid_overhead_row_dates_on_its_period_month(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 300, 'paid_date' => null]);

        // period_month is this month, which is inside the default window.
        $this->assertSame(300.0, round((float) $this->totals()['cash_overhead_total'], 2));
    }

    /**
     * Company-wide overhead belongs to no single branch, so it is excluded from a
     * single-branch view and deducted from the all-branches figure only. This mirrors
     * the GCash wallet's treatment of the same table.
     */
    public function test_company_wide_overhead_is_excluded_from_a_single_branch_view(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 250, 'branch_id' => null]);

        $scoped = $this->totals(['branch_id' => $this->branch->id]);
        $this->assertSame(0.0, round((float) $scoped['cash_overhead_total'], 2));
        $this->assertSame(1000.0, round((float) $scoped['cash_on_hand'], 2));

        $all = $this->totals();
        $this->assertSame(250.0, round((float) $all['cash_overhead_total'], 2));
        $this->assertSame(750.0, round((float) $all['cash_on_hand'], 2));
    }

    /** Another branch's overhead is not this branch's cash. */
    public function test_overhead_respects_the_branch_filter(): void
    {
        $other = Branch::factory()->create();
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 900, 'branch_id' => $other->id]);

        $this->assertSame(0.0, round((float) $this->totals(['branch_id' => $this->branch->id])['cash_overhead_total'], 2));
        $this->assertSame(900.0, round((float) $this->totals(['branch_id' => $other->id])['cash_overhead_total'], 2));
    }

    /** Daily operating cash expenses and overhead stay separate figures. */
    public function test_overhead_is_not_folded_into_cash_expenses(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 400]);

        $totals = $this->totals();

        $this->assertSame(0.0, round((float) $totals['cash_expenses_total'], 2));
        $this->assertSame(400.0, round((float) $totals['cash_overhead_total'], 2));
    }

    /**
     * Overhead can exceed the tills counted in a narrow window, netting negative. The
     * figure is truthful and is shown, but it must not wear the healthy colour.
     */
    public function test_a_negative_cash_on_hand_is_signed_and_not_coloured_as_healthy(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 12000]);

        $html = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->getContent();

        $strip = substr($html, strpos($html, 'rh-pay-stats'));
        $strip = substr($strip, 0, strpos($strip, 'rh-pay-toolbar'));

        $this->assertSame(-11000.0, round((float) $this->totals()['cash_on_hand'], 2));
        $this->assertStringContainsString('−₱11,000.00', $strip);
        $this->assertStringNotContainsString('₱-11,000.00', $strip);

        // The sign drives the colour: healthy green must not appear on a negative.
        preg_match('/Cash on Hand<\/p>.*?class="rh-pay-stat-value ([^"]+)"/s', $strip, $m);
        $this->assertStringContainsString('--warn', $m[1]);
        $this->assertStringNotContainsString('--success', $m[1]);
    }

    public function test_a_positive_cash_on_hand_keeps_the_healthy_colour(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 400]);

        $html = $this->actingAs($this->owner)->get(route('day-closures.index'))->getContent();
        $strip = substr($html, strpos($html, 'rh-pay-stats'));
        $strip = substr($strip, 0, strpos($strip, 'rh-pay-toolbar'));

        preg_match('/Cash on Hand<\/p>.*?class="rh-pay-stat-value ([^"]+)"/s', $strip, $m);
        $this->assertStringContainsString('--success', $m[1]);
    }

    public function test_the_strip_shows_all_three_figures(): void
    {
        $this->closedDay(now()->subDay()->toDateString(), 1000);
        $this->overhead(['amount' => 400]);

        $html = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->getContent();

        $strip = substr($html, strpos($html, 'rh-pay-stats'));
        $strip = substr($strip, 0, strpos($strip, 'rh-pay-toolbar'));

        preg_match_all('/rh-pay-stat-label">([^<]+)</', $strip, $labels);

        $this->assertSame(['Cash on Hand', 'Cash Overhead', 'Cash Expenses'], $labels[1]);
    }
}
