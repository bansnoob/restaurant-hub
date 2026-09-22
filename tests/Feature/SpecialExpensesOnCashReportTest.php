<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Cash Report hosts the special-expense list, but that page is role:owner|cashier while
 * special expenses are owner-only. These rows carry wages and supplier terms — the very
 * reason wages were moved out of `expenses`, which a cashier can read through the mobile
 * API. The gate is the load-bearing part of this feature.
 */
class SpecialExpensesOnCashReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create(['name' => 'Main Branch']);
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('cashier');
    }

    private function overhead(array $attributes = []): SpecialExpense
    {
        return SpecialExpense::create(array_merge([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'paid_date' => now()->subDay()->toDateString(),
            'description' => 'Rent',
            'amount' => 1000,
            'payment_method' => 'cash',
        ], $attributes));
    }

    public function test_an_owner_sees_the_special_expenses_section(): void
    {
        $this->overhead(['description' => 'Kein (Sep.14-20, 2026)', 'amount' => 2400]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Special Expenses')
            ->assertSee('Kein (Sep.14-20, 2026)');
    }

    /** The gate. A cashier must not receive these rows at all. */
    public function test_a_cashier_never_receives_the_special_expenses(): void
    {
        $this->overhead(['description' => 'Kein (Sep.14-20, 2026)', 'amount' => 2400]);

        $response = $this->actingAs($this->cashier)
            ->get(route('day-closures.index'))
            ->assertOk();

        $this->assertNull($response->viewData('special'), 'cashier was handed the special expense data');
        $response->assertDontSee('Kein (Sep.14-20, 2026)');
        $response->assertDontSee('Special Expenses');
    }

    /** The cashier can still do their job on this page. */
    public function test_a_cashier_still_sees_the_cash_report_itself(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Cash on Hand');
    }

    public function test_rows_are_listed_newest_paid_date_first(): void
    {
        $this->overhead(['paid_date' => now()->subDays(10)->toDateString(), 'description' => 'OLDEST']);
        $this->overhead(['paid_date' => now()->subDay()->toDateString(), 'description' => 'NEWEST']);
        $this->overhead(['paid_date' => now()->subDays(5)->toDateString(), 'description' => 'MIDDLE']);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('special')['rows'];

        $this->assertSame(['NEWEST', 'MIDDLE', 'OLDEST'], $rows->pluck('description')->all());
    }

    /** A bill not yet settled has no paid_date, so it dates on its period month. */
    public function test_an_unpaid_row_falls_back_to_its_period_month(): void
    {
        $this->overhead(['paid_date' => null, 'description' => 'UNPAID']);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('special')['rows'];

        $this->assertSame(['UNPAID'], $rows->pluck('description')->all());
    }

    public function test_rows_outside_the_range_are_excluded(): void
    {
        $this->overhead(['paid_date' => now()->subDay()->toDateString(), 'description' => 'INSIDE']);
        $this->overhead([
            'paid_date' => now()->subMonths(6)->toDateString(),
            'period_month' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'description' => 'OUTSIDE',
        ]);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('special')['rows'];

        $this->assertSame(['INSIDE'], $rows->pluck('description')->all());
    }

    public function test_the_branch_filter_applies(): void
    {
        $other = Branch::factory()->create(['name' => 'Second']);
        $this->overhead(['description' => 'MINE']);
        $this->overhead(['branch_id' => $other->id, 'description' => 'THEIRS']);

        $rows = $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->viewData('special')['rows'];

        $this->assertSame(['MINE'], $rows->pluck('description')->all());
    }

    /**
     * Only the cash rows reach Paid Outside Drawer. The section states both totals so it
     * reconciles against the tile instead of silently disagreeing with it.
     */
    public function test_the_section_separates_its_cash_total_from_its_grand_total(): void
    {
        $this->overhead(['amount' => 1000, 'payment_method' => 'cash']);
        $this->overhead(['amount' => 700, 'payment_method' => 'bank_transfer']);

        $data = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk();

        $special = $data->viewData('special');
        $this->assertSame(1700.0, round((float) $special['total'], 2));
        $this->assertSame(1000.0, round((float) $special['cash_total'], 2));
        // And only the cash part moved Cash on Hand.
        $this->assertSame(1000.0, round((float) $data->viewData('totals')['paid_outside_total'], 2));
    }

    /** The edit drawer must offer the row's own branch even once that branch is deactivated. */
    public function test_a_deactivated_branch_still_appears_in_the_form_options(): void
    {
        $dead = Branch::factory()->create(['name' => 'Closed Site', 'is_active' => false]);
        $this->overhead(['branch_id' => $dead->id]);

        $branches = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->viewData('branches');

        $this->assertTrue(
            collect($branches)->contains('id', $dead->id),
            'the drawer would silently reassign the cost to another branch'
        );
    }

    /** The Special Expenses page had the same sort bug — one month made period_month useless. */
    public function test_the_special_expenses_page_is_also_sorted_by_paid_date(): void
    {
        $this->overhead(['paid_date' => now()->startOfMonth()->addDays(1)->toDateString(), 'description' => 'EARLY']);
        $this->overhead(['paid_date' => now()->startOfMonth()->addDays(20)->toDateString(), 'description' => 'LATE']);
        $this->overhead(['paid_date' => now()->startOfMonth()->addDays(10)->toDateString(), 'description' => 'MID']);

        $rows = $this->actingAs($this->owner)
            ->get(route('special-expenses.index'))
            ->assertOk()
            ->viewData('specialExpenses');

        $this->assertSame(['LATE', 'MID', 'EARLY'], collect($rows->items())->pluck('description')->all());
    }
}
