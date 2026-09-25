<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use App\Services\CashReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The headline figure is what the tills GENERATED over the window, not a running
 * balance — because nothing in this app records cash leaving a till, so a true
 * balance cannot be derived.
 *
 * It used to sum each closed day's counted_cash. counted_cash is a snapshot that
 * includes the opening float, and the float is the same physical money every day, so
 * summing snapshots counted it once per day: three identical ₱1,000-float days read
 * ₱4,500 against ₱2,500 actually held, and the figure grew every time the date range
 * was widened even though no money had moved.
 *
 * Each day now contributes counted_cash - opening_float, which is that day's own
 * takings. The float is reported separately, once.
 */
class CashReportNetCashTakenTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->assignRole('owner');
    }

    private function day(string $date, float $opening, float $counted, float $sales = 0): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id, 'closed_at_date' => $date,
            'closed_by_user_id' => $this->user->id, 'closed_at' => $date.' 21:00:00',
            'opening_float' => $opening,
            'cash_sales_total' => $sales, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => $opening + $sales, 'counted_cash' => $counted,
            'variance' => $counted - ($opening + $sales),
            'order_count' => 0, 'expense_count' => 0,
        ]);
    }

    private function totals(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        $svc = app(CashReportService::class);

        return $svc->totals($svc->dayRows($from, $to, $this->branch->id), $from, $to, $this->branch->id);
    }

    public function test_the_float_is_not_counted_once_per_day(): void
    {
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $d) {
            $this->day($d, 1000, 1500, 500);
        }

        $this->assertEqualsWithDelta(1500.0, $this->totals()['cash_on_hand'], 0.005,
            'three days of 500 takings is 1,500 — the 1,000 float is not part of what the tills generated');
    }

    public function test_widening_the_range_over_a_quiet_day_adds_nothing(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);
        $this->day('2026-09-11', 1000, 1000, 0);   // open, took nothing

        $this->assertEqualsWithDelta(500.0, $this->totals()['cash_on_hand'], 0.005);
    }

    public function test_a_single_day_is_that_days_takings(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);

        $this->assertEqualsWithDelta(500.0, $this->totals('2026-09-10', '2026-09-10')['cash_on_hand'], 0.005);
    }

    public function test_a_day_that_came_up_short_reduces_the_figure(): void
    {
        $this->day('2026-09-10', 1000, 1400, 500);   // 100 short

        $this->assertEqualsWithDelta(400.0, $this->totals()['cash_on_hand'], 0.005);
    }

    public function test_overhead_and_outside_paid_are_still_deducted(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);
        Expense::create([
            'branch_id' => $this->branch->id, 'expense_date' => '2026-09-10',
            'description' => 'Advance', 'amount' => 200,
            'payment_method' => 'cash', 'paid_from' => 'outside',
            'status' => 'approved', 'recorded_by_user_id' => $this->user->id,
        ]);

        $this->assertEqualsWithDelta(300.0, $this->totals()['cash_on_hand'], 0.005);
    }

    public function test_an_unclosed_day_contributes_nothing(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);

        $totals = $this->totals();
        $this->assertSame(1, $totals['days_closed']);
        $this->assertEqualsWithDelta(500.0, $totals['cash_on_hand'], 0.005);
    }

    public function test_the_float_is_reported_separately_and_only_once(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);
        $this->day('2026-09-11', 1000, 1500, 500);

        $this->assertEqualsWithDelta(1000.0, $this->totals()['float_in_till'], 0.005,
            'the float is the latest closed day\'s opening float, reported once');
    }

    public function test_a_changed_float_reports_the_latest_one(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);
        $this->day('2026-09-11', 1500, 2000, 500);

        $this->assertEqualsWithDelta(1500.0, $this->totals()['float_in_till'], 0.005);
    }

    public function test_the_tile_no_longer_claims_to_be_a_balance(): void
    {
        $this->day('2026-09-10', 1000, 1500, 500);

        $html = $this->actingAs($this->user)
            ->get(route('day-closures.index', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Cash on Hand', $html,
            'the label must stop describing a range total as a balance held');
        $this->assertStringContainsString('Net Cash Taken', $html);
    }
}
