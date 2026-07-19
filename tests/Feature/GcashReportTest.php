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

class GcashReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function sale(array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => now(),
            'closed_at' => now(),
        ], $attributes));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('gcash-report.index'))->assertRedirect(route('login'));
    }

    public function test_owner_can_view_the_report(): void
    {
        $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->assertSee('GCash Report');
    }

    /**
     * Owner-only: the page renders per-transaction sales rows, which /sales already withholds
     * from cashiers. Unlike the Cash Report, this is not a day-level aggregate.
     */
    public function test_cashier_cannot_view_the_report(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)
            ->get(route('gcash-report.index'))
            ->assertForbidden();
    }

    public function test_user_without_a_role_cannot_view_the_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('gcash-report.index'))
            ->assertForbidden();
    }

    public function test_the_nav_link_is_hidden_from_cashiers(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get(route('dashboard'))->assertOk()->assertDontSee('GCash Report');
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('GCash Report');
    }

    public function test_pure_gcash_sale_counts_its_full_grand_total(): void
    {
        // The POS does not reliably populate gcash_amount for single-tender GCash,
        // so grand_total is the source of truth here.
        $this->sale([
            'payment_method' => 'gcash',
            'grand_total' => 500.00,
            'gcash_amount' => null,
        ]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertSame(500.00, $response->viewData('totals')['gcash_sales_total']);
        $this->assertSame(1, $response->viewData('totals')['transaction_count']);
    }

    public function test_mixed_sale_counts_only_its_gcash_slice(): void
    {
        $this->sale([
            'payment_method' => 'mixed',
            'grand_total' => 1000.00,
            'cash_amount' => 400.00,
            'gcash_amount' => 600.00,
        ]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertSame(600.00, $response->viewData('totals')['gcash_sales_total']);
    }

    public function test_cash_and_zero_gcash_mixed_sales_are_excluded(): void
    {
        $this->sale(['payment_method' => 'cash', 'grand_total' => 900.00, 'cash_amount' => 900.00]);
        $this->sale(['payment_method' => 'mixed', 'grand_total' => 300.00, 'cash_amount' => 300.00, 'gcash_amount' => 0]);
        $this->sale(['payment_method' => 'mixed', 'grand_total' => 300.00, 'cash_amount' => 300.00, 'gcash_amount' => null]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertSame(0.0, $response->viewData('totals')['gcash_sales_total']);
        $this->assertSame(0, $response->viewData('totals')['transaction_count']);
    }

    public function test_non_completed_gcash_sales_are_excluded(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'status' => 'voided']);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 200.00, 'status' => 'refunded']);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 400.00, 'status' => 'completed']);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertSame(400.00, $response->viewData('totals')['gcash_sales_total']);
    }

    public function test_net_deducts_gcash_expenses_but_not_cash_expenses(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 1000.00]);

        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 250.00,
            'status' => 'approved',
        ]);
        // Cash expense — must not touch the GCash net.
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 999.00,
            'status' => 'approved',
        ]);
        // Draft GCash expense — not approved, so excluded.
        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 500.00,
            'status' => 'draft',
        ]);

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');

        $this->assertSame(1000.00, $totals['gcash_sales_total']);
        $this->assertSame(250.00, $totals['gcash_expenses_total']);
        $this->assertSame(750.00, $totals['net_gcash']);
    }

    public function test_branch_filter_scopes_sales_and_expenses(): void
    {
        $other = Branch::factory()->create();

        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00]);
        $this->sale(['branch_id' => $other->id, 'payment_method' => 'gcash', 'grand_total' => 700.00]);
        Expense::factory()->gcash()->create([
            'branch_id' => $other->id,
            'expense_date' => now()->toDateString(),
            'amount' => 50.00,
        ]);

        $totals = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => $other->id]))
            ->viewData('totals');

        $this->assertSame(700.00, $totals['gcash_sales_total']);
        $this->assertSame(50.00, $totals['gcash_expenses_total']);
        $this->assertSame(650.00, $totals['net_gcash']);
    }

    public function test_date_range_filter_excludes_sales_outside_the_window(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'sale_datetime' => now()->subDays(60)]);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 250.00, 'sale_datetime' => now()->subDays(2)]);

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index', [
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]))->viewData('totals');

        $this->assertSame(250.00, $totals['gcash_sales_total']);
    }

    public function test_search_filters_the_transaction_list_by_order_number(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'order_number' => 'ORD-AAA111']);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 200.00, 'order_number' => 'ORD-BBB222']);

        $sales = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['search' => 'BBB222']))
            ->viewData('sales');

        $this->assertCount(1, $sales);
        $this->assertSame('ORD-BBB222', $sales->first()->order_number);
    }

    /**
     * The stat tiles sit directly above the table, so they must describe the same rows.
     * Previously the totals ignored the search and the page could render "No GCash
     * transactions" and a non-zero total at the same time.
     */
    public function test_search_also_narrows_the_totals_so_the_tiles_match_the_table(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'order_number' => 'ORD-AAA111']);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 200.00, 'order_number' => 'ORD-BBB222']);

        $totals = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['search' => 'BBB222']))
            ->viewData('totals');

        $this->assertSame(200.00, $totals['gcash_sales_total']);
        $this->assertSame(1, $totals['transaction_count']);
    }

    public function test_search_matching_nothing_shows_a_zeroed_report_not_a_stale_total(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'order_number' => 'ORD-AAA111']);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index', ['search' => 'NOPE']));
        $totals = $response->viewData('totals');

        $this->assertSame(0.0, $totals['gcash_sales_total']);
        $this->assertSame(0, $totals['transaction_count']);
        $response->assertSee('No GCash transactions');

        // Scoped to the stats strip rather than the whole page: the wallet balance card also
        // renders an amount, and it is *meant* to ignore the search — a running balance is not
        // a filtered figure. A page-wide assertion would fail for the wrong reason.
        $this->assertStringNotContainsString('₱100.00', $this->statsStrip($response->getContent()));
    }

    /** The stat tiles only, so assertions about them are not confused by the balance card. */
    private function statsStrip(string $html): string
    {
        preg_match('/<div class="rh-pay-stats">(.*?)<\/div>\s*<\/div>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_search_wildcards_are_matched_literally(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 100.00, 'order_number' => 'ORD-AAA111']);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 200.00, 'order_number' => 'ORD-BBB222']);

        // '%' must be a literal, not "match everything".
        $totals = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['search' => '%']))
            ->viewData('totals');

        $this->assertSame(0.0, $totals['gcash_sales_total']);

        // '_' must not act as a single-character wildcard.
        $underscore = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['search' => 'ORD_AAA111']))
            ->viewData('totals');

        $this->assertSame(0.0, $underscore['gcash_sales_total']);
    }

    /**
     * gcashValue() is a second implementation of the rule GCASH_AMOUNT_SQL encodes, and it
     * drives the per-row "GCash Amount" column. The NULL gcash_amount case is the exact bug
     * this feature exists to avoid, so pin the rendered row, not just the aggregate.
     */
    public function test_row_level_gcash_amount_resolves_null_gcash_amount_to_grand_total(): void
    {
        $pure = $this->sale([
            'payment_method' => 'gcash',
            'grand_total' => 41.05,
            'gcash_amount' => null,
            'order_number' => 'ORD-PURE',
        ]);
        $mixed = $this->sale([
            'payment_method' => 'mixed',
            'grand_total' => 129.00,
            'cash_amount' => 29.00,
            'gcash_amount' => 100.00,
            'order_number' => 'ORD-MIXED',
        ]);

        $this->assertSame(41.05, $pure->fresh()->gcashValue());
        $this->assertSame(100.00, $mixed->fresh()->gcashValue());

        // And the rendered page shows those per-row values.
        $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->assertSee('₱41.05')
            ->assertSee('₱100.00');
    }

    /**
     * The per-row display path and the SQL aggregate encode the same rule twice; if they ever
     * drift, the column would stop summing to the tile above it.
     */
    public function test_row_values_sum_to_the_reported_total(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 41.05, 'gcash_amount' => null]);
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 500.00, 'gcash_amount' => 0]);
        $this->sale(['payment_method' => 'mixed', 'grand_total' => 129.00, 'cash_amount' => 29.00, 'gcash_amount' => 100.00]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'));

        $rowSum = $response->viewData('sales')->sum(fn ($sale) => $sale->gcashValue());

        $this->assertSame(641.05, round($rowSum, 2));
        $this->assertSame(641.05, round($response->viewData('totals')['gcash_sales_total'], 2));
    }

    public function test_reversed_date_range_is_swapped_rather_than_returning_nothing(): void
    {
        $this->sale(['payment_method' => 'gcash', 'grand_total' => 320.00, 'sale_datetime' => now()->subDays(2)]);

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->subDays(5)->toDateString(),
        ]))->viewData('totals');

        $this->assertSame(320.00, $totals['gcash_sales_total']);
    }

    public function test_invalid_branch_filter_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => 999999]))
            ->assertSessionHasErrors('branch_id');
    }

    /**
     * The report must agree with the GCash total the day-close flow already stores,
     * otherwise the two pages would quote different numbers for the same day.
     */
    public function test_report_total_matches_the_stored_day_closure_gcash_total(): void
    {
        $today = now()->toDateString();

        $this->sale(['payment_method' => 'gcash', 'grand_total' => 500.00, 'gcash_amount' => null]);
        $this->sale(['payment_method' => 'mixed', 'grand_total' => 1000.00, 'cash_amount' => 400.00, 'gcash_amount' => 600.00]);
        $this->sale(['payment_method' => 'cash', 'grand_total' => 800.00, 'cash_amount' => 800.00]);

        $this->actingAs($this->owner)->post(route('day-close.store'), [
            'branch_id' => $this->branch->id,
            'closed_at_date' => $today,
            'opening_float' => 0,
            'counted_cash' => 1200.00,
        ])->assertSessionHasNoErrors();

        $closure = DayClosure::where('branch_id', $this->branch->id)->firstOrFail();

        $reportTotal = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', [
                'branch_id' => $this->branch->id,
                'date_from' => $today,
                'date_to' => $today,
            ]))
            ->viewData('totals')['gcash_sales_total'];

        $this->assertSame(1100.00, (float) $closure->gcash_sales_total);
        $this->assertSame((float) $closure->gcash_sales_total, $reportTotal);
    }

    /**
     * ...but once an entry is marked as never having reached the wallet, the two figures are
     * MEANT to differ, and this pins that as intended rather than a regression.
     *
     * day_closures.gcash_sales_total is a snapshot of what was *recorded* when the day was
     * signed off. The report answers a different question — what actually arrived — so a
     * declined entry leaves the report lower by exactly that amount. If this ever starts
     * failing, the two have been silently re-coupled.
     */
    public function test_declining_an_entry_makes_the_report_deliberately_diverge_from_the_closure(): void
    {
        $today = now()->toDateString();

        $kept = $this->sale(['payment_method' => 'gcash', 'grand_total' => 500.00]);
        $missing = $this->sale(['payment_method' => 'gcash', 'grand_total' => 300.00]);

        $this->actingAs($this->owner)->post(route('day-close.store'), [
            'branch_id' => $this->branch->id,
            'closed_at_date' => $today,
            'opening_float' => 0,
            'counted_cash' => 0,
        ])->assertSessionHasNoErrors();

        $closure = DayClosure::where('branch_id', $this->branch->id)->firstOrFail();
        $this->assertSame(800.00, (float) $closure->gcash_sales_total);

        $this->actingAs($this->owner)->put(
            route('gcash-report.entries.status', ['type' => 'sale', 'id' => $missing->id]),
            ['status' => 'declined']
        )->assertSessionHasNoErrors();

        $reportTotal = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', [
                'branch_id' => $this->branch->id,
                'date_from' => $today,
                'date_to' => $today,
            ]))
            ->viewData('totals')['gcash_sales_total'];

        // The closure keeps its signed-off figure; the report drops what never arrived.
        $this->assertSame(800.00, (float) $closure->fresh()->gcash_sales_total);
        $this->assertSame(500.00, $reportTotal);
        $this->assertSame(500.00, (float) $kept->grand_total);
    }
}
