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
 * The Cash Report used to list day_closures rows only, so a day nobody closed was
 * simply absent from the tab — and because the Close Day drawer is pinned to today,
 * a missed day could never be recovered. These tests pin down the replacement
 * behaviour: every day with real activity gets a row, closed or not.
 */
class CashReportUnclosedDaysTest extends TestCase
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

    private function sale(string $date, array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $date.' 12:00:00',
            'closed_at' => $date.' 12:00:00',
            'grand_total' => 100,
        ], $attributes));
    }

    private function expense(string $date, array $attributes = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'approved',
            'payment_method' => 'cash',
            'expense_date' => $date,
            'amount' => 40,
        ], $attributes));
    }

    private function closure(string $date, array $attributes = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 100,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 100,
            'counted_cash' => 100,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 0,
        ], $attributes));
    }

    /** The core bug: sales exist, nobody closed, the date vanished from the tab. */
    public function test_a_day_with_sales_and_no_closure_appears_as_not_closed(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->sale($date, ['grand_total' => 229]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee(\Carbon\Carbon::parse($date)->format('M j'))
            ->assertSee('Not closed');
    }

    /** An unclosed day still has a knowable expected cash — sales minus cash expenses. */
    public function test_an_unclosed_day_shows_expected_cash_from_its_own_activity(): void
    {
        $date = now()->subDays(2)->toDateString();
        $this->sale($date, ['grand_total' => 500]);
        $this->expense($date, ['amount' => 120]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('380.00');
    }

    /** A day where the only activity was a cash expense still moved the till. */
    public function test_a_day_with_only_cash_expenses_appears(): void
    {
        $date = now()->subDays(4)->toDateString();
        $this->expense($date, ['amount' => 75]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee(\Carbon\Carbon::parse($date)->format('M j'))
            ->assertSee('Not closed');
    }

    /** Mixed-payment orders contribute only their cash slice. */
    public function test_mixed_payment_sales_contribute_only_their_cash_slice(): void
    {
        $date = now()->subDays(5)->toDateString();
        $this->sale($date, [
            'payment_method' => 'mixed',
            'grand_total' => 300,
            'cash_amount' => 110,
            'gcash_amount' => 190,
        ]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('110.00');
    }

    /** Don't invent rows for quiet days — only days with real activity. */
    public function test_a_day_with_no_activity_does_not_appear(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk();

        $response->assertDontSee('Not closed');
    }

    /** Non-cash activity alone doesn't move the till, so it earns no row. */
    public function test_a_day_with_only_gcash_activity_does_not_appear(): void
    {
        $date = now()->subDays(6)->toDateString();
        $this->sale($date, ['payment_method' => 'gcash', 'grand_total' => 400]);
        $this->expense($date, ['payment_method' => 'gcash', 'amount' => 50]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertDontSee('Not closed');
    }

    /** Draft/void rows are not real money and must not conjure a row. */
    public function test_non_completed_sales_and_unapproved_expenses_do_not_create_a_row(): void
    {
        $date = now()->subDays(7)->toDateString();
        $this->sale($date, ['status' => 'open', 'grand_total' => 999]);
        $this->expense($date, ['status' => 'draft', 'amount' => 999]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertDontSee('Not closed');
    }

    /** Once a day is closed, it renders as a closure — never doubled up. */
    public function test_a_closed_day_is_not_also_listed_as_unclosed(): void
    {
        $date = now()->subDay()->toDateString();
        $this->sale($date, ['grand_total' => 100]);
        $this->closure($date);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertDontSee('Not closed');
    }

    /** Unclosed days have no counted cash, so they must not fake a variance. */
    public function test_unclosed_days_add_to_expected_but_not_to_cash_on_hand_or_variance(): void
    {
        $closedDate = now()->subDay()->toDateString();
        $this->sale($closedDate, ['grand_total' => 100]);
        $this->closure($closedDate, [
            'expected_cash' => 100, 'counted_cash' => 98, 'variance' => -2,
        ]);

        $openDate = now()->subDays(2)->toDateString();
        $this->sale($openDate, ['grand_total' => 250]);

        $response = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk();

        $totals = $response->viewData('totals');

        // Expected folds in the unclosed day: 100 + 250.
        $this->assertSame(350.0, round((float) $totals['expected_total'], 2));
        // Cash on hand and variance stay measured-only.
        $this->assertSame(98.0, round((float) $totals['cash_on_hand'], 2));
        $this->assertSame(-2.0, round((float) $totals['variance_total'], 2));
    }

    /** "days closed" must keep meaning days actually closed. */
    public function test_the_closed_day_count_excludes_unclosed_days(): void
    {
        $closedDate = now()->subDay()->toDateString();
        $this->closure($closedDate);
        $this->sale(now()->subDays(2)->toDateString(), ['grand_total' => 50]);

        $totals = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('totals');

        $this->assertSame(1, (int) $totals['days_closed']);
    }

    public function test_unclosed_days_respect_the_branch_filter(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Branch']);
        $date = now()->subDays(2)->toDateString();
        $this->sale($date, ['branch_id' => $other->id, 'grand_total' => 777]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertDontSee('777.00');

        $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['branch_id' => $other->id]))
            ->assertOk()
            ->assertSee('777.00');
    }

    public function test_unclosed_days_respect_the_date_range(): void
    {
        $inside = now()->subDays(2)->toDateString();
        $outside = now()->subDays(60)->toDateString();
        $this->sale($inside, ['grand_total' => 111]);
        $this->sale($outside, ['grand_total' => 222]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('111.00')
            ->assertDontSee('222.00');
    }

    /** Two branches trading on the same date are two separate drawers, so two rows. */
    public function test_each_branch_gets_its_own_row_for_the_same_date(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Branch']);
        $date = now()->subDays(2)->toDateString();
        $this->sale($date, ['grand_total' => 140]);
        $this->sale($date, ['branch_id' => $other->id, 'grand_total' => 260]);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('closures');

        $this->assertCount(2, $rows->items());
        $this->assertSame([false, false], collect($rows->items())->pluck('closed')->all());
    }

    /** One branch closed, the other not, on the same date — the gap must still surface. */
    public function test_one_branch_closing_does_not_hide_anothers_gap(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Branch']);
        $date = now()->subDays(2)->toDateString();
        $this->sale($date, ['grand_total' => 140]);
        $this->closure($date);
        $this->sale($date, ['branch_id' => $other->id, 'grand_total' => 260]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Not closed')
            ->assertSee('260.00');
    }

    /** The list is now a merged in-memory collection, so paging over it must still hold. */
    public function test_the_merged_list_paginates_without_repeating_or_dropping_rows(): void
    {
        // 40 consecutive unclosed days, inside the default 30-day window's reach.
        for ($i = 1; $i <= 40; $i++) {
            $this->sale(now()->subDays($i)->toDateString(), ['grand_total' => 100 + $i]);
        }

        $from = now()->subDays(45)->toDateString();
        $to = now()->toDateString();

        $pageOne = $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['date_from' => $from, 'date_to' => $to]))
            ->assertOk()
            ->viewData('closures');

        $pageTwo = $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['date_from' => $from, 'date_to' => $to, 'page' => 2]))
            ->assertOk()
            ->viewData('closures');

        $this->assertSame(40, $pageOne->total());
        $this->assertCount(30, $pageOne->items());
        $this->assertCount(10, $pageTwo->items());

        $dates = collect($pageOne->items())->pluck('date')
            ->concat(collect($pageTwo->items())->pluck('date'));

        // Every day present exactly once, newest first.
        $this->assertCount(40, $dates->unique());
        $this->assertSame($dates->sortDesc()->values()->all(), $dates->values()->all());
    }

    /**
     * Expected and Variance are not range figures. Overs and shorts cancel, so a range
     * total near zero reads as "balanced" whether every day matched or every day was
     * wild — worse than uninformative on a screen used to spot problems.
     *
     * Asserted against the strip's labels rather than with assertDontSee: both words
     * still appear legitimately as table column headers. Asserted as absence rather
     * than an exact list so that adding a genuinely useful tile does not fail it.
     */
    public function test_the_stats_strip_carries_no_expected_or_variance_figure(): void
    {
        $this->sale(now()->subDay()->toDateString(), ['grand_total' => 100]);

        $html = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->getContent();

        $strip = substr($html, strpos($html, 'rh-pay-stats'));
        $strip = substr($strip, 0, strpos($strip, 'rh-pay-toolbar'));

        preg_match_all('/rh-pay-stat-label">([^<]+)</', $strip, $labels);

        $this->assertNotContains('Expected', $labels[1]);
        $this->assertNotContains('Variance', $labels[1]);
        $this->assertContains('Net Cash Taken', $labels[1]);
    }

    /** The per-day figures stay — they are the ones anyone acts on. */
    public function test_the_table_still_carries_per_day_expected_and_variance(): void
    {
        $date = now()->subDay()->toDateString();
        $this->sale($date, ['grand_total' => 100]);
        $this->closure($date, ['expected_cash' => 100, 'counted_cash' => 93, 'variance' => -7]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('93.00')      // counted, per row
            ->assertSee('7.00');      // variance pill, per row
    }

    /** Cashiers share this tab and must see the gaps too. */
    public function test_a_cashier_also_sees_unclosed_days(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->sale(now()->subDays(2)->toDateString(), ['grand_total' => 321]);

        $this->actingAs($cashier)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Not closed');
    }
}
