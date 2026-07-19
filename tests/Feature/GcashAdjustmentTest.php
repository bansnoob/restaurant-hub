<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\GcashAdjustment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GcashAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create(['code' => 'MAIN001']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'adjustment_date' => now()->toDateString(),
            'amount' => 500.00,
            'reason' => 'Reversed — order recorded twice',
        ], $overrides);
    }

    private function gcashSale(float $amount): Sale
    {
        return Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_method' => 'gcash',
            'status' => 'completed',
            'sale_datetime' => now(),
            'grand_total' => $amount,
        ]);
    }

    public function test_owner_can_record_an_adjustment_and_it_is_stored_negative(): void
    {
        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $adjustment = GcashAdjustment::firstOrFail();

        // The form posts a positive figure; the server applies the sign.
        $this->assertSame(-500.00, (float) $adjustment->amount);
        $this->assertSame('Reversed — order recorded twice', $adjustment->reason);
        $this->assertSame($this->owner->id, $adjustment->recorded_by_user_id);
    }

    public function test_an_adjustment_deducts_from_the_net_without_touching_gcash_sales(): void
    {
        $this->gcashSale(1000.00);

        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 300.00]));

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');

        // Sales is untouched — it is the figure the day closure snapshots.
        $this->assertSame(1000.00, $totals['gcash_sales_total']);
        $this->assertSame(-300.00, $totals['adjustments_total']);
        $this->assertSame(700.00, $totals['net_gcash']);
    }

    public function test_the_net_accounts_for_sales_expenses_and_adjustments_together(): void
    {
        $this->gcashSale(1000.00);
        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 200.00,
        ]);
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 300.00]));

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');

        $this->assertSame(500.00, $totals['net_gcash']);
    }

    /**
     * The whole reason adjustments live in their own table: a correction is neither revenue nor
     * a cost, so it must not reach the Sales page's counts and averages or the expense reporting.
     */
    public function test_an_adjustment_does_not_create_a_sale_or_an_expense(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload());

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('gcash_adjustments', 1);
    }

    public function test_an_adjustment_does_not_appear_on_the_sales_or_expenses_pages(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload([
            'reason' => 'Zzunmistakable adjustment marker',
        ]));

        $this->actingAs($this->owner)->get(route('sales.index'))->assertOk()->assertDontSee('Zzunmistakable adjustment marker');
        $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk()->assertDontSee('Zzunmistakable adjustment marker');
    }

    /**
     * day_closures.gcash_sales_total is computed from `sales`, which an adjustment never
     * touches. That is precisely what lets an adjustment be dated into a closed day without
     * invalidating the figures that day was signed off with.
     */
    public function test_an_adjustment_does_not_change_a_stored_day_closure(): void
    {
        $this->gcashSale(1000.00);

        $this->actingAs($this->owner)->post(route('day-close.store'), [
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->toDateString(),
            'opening_float' => 0,
            'counted_cash' => 0,
        ])->assertSessionHasNoErrors();

        $closure = DayClosure::where('branch_id', $this->branch->id)->firstOrFail();
        $before = (float) $closure->gcash_sales_total;

        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 400.00]));

        $this->assertSame($before, (float) $closure->fresh()->gcash_sales_total);
        $this->assertSame(1000.00, $before);
    }

    /**
     * Unlike a GCash record, an adjustment is allowed on a closed day — it cannot make that
     * day's snapshot stale, and correcting an already-closed day is the main reason to use one.
     */
    public function test_an_adjustment_can_be_recorded_against_a_closed_day(): void
    {
        DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->toDateString(),
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('gcash_adjustments', 1);
    }

    public function test_owner_can_edit_and_delete_an_adjustment(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload());
        $adjustment = GcashAdjustment::firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('gcash-report.adjustments.update', $adjustment), $this->payload([
                'amount' => 250.00,
                'reason' => 'Corrected amount',
            ]))
            ->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertSame(-250.00, (float) $adjustment->amount);
        $this->assertSame('Corrected amount', $adjustment->reason);

        $this->actingAs($this->owner)
            ->delete(route('gcash-report.adjustments.destroy', $adjustment))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('gcash_adjustments', 0);
    }

    public function test_editing_an_adjustment_keeps_it_negative(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload());
        $adjustment = GcashAdjustment::firstOrFail();

        // The edit form shows the absolute value, so a round-trip must not flip the sign.
        $this->actingAs($this->owner)->put(route('gcash-report.adjustments.update', $adjustment), $this->payload());

        $this->assertSame(-500.00, (float) $adjustment->fresh()->amount);
    }

    public function test_cashiers_cannot_touch_adjustments(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload());
        $adjustment = GcashAdjustment::firstOrFail();

        $this->actingAs($cashier)->post(route('gcash-report.adjustments.store'), $this->payload())->assertForbidden();
        $this->actingAs($cashier)->put(route('gcash-report.adjustments.update', $adjustment), $this->payload())->assertForbidden();
        $this->actingAs($cashier)->delete(route('gcash-report.adjustments.destroy', $adjustment))->assertForbidden();
    }

    public function test_adjustment_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 0]))
            ->assertSessionHasErrors('amount');

        // Negative input would flip to a positive credit once the server applies its sign.
        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => -50]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 100.999]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload(['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.adjustments.store'), $this->payload(['branch_id' => 999999]))
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseCount('gcash_adjustments', 0);
    }

    public function test_adjustments_respect_the_branch_and_date_filters(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB']);

        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload(['amount' => 100.00]));
        $this->actingAs($this->owner)->post(route('gcash-report.adjustments.store'), $this->payload([
            'branch_id' => $other->id,
            'amount' => 700.00,
        ]));
        // Outside the default 30-day window.
        GcashAdjustment::factory()->create([
            'branch_id' => $this->branch->id,
            'adjustment_date' => now()->subDays(60)->toDateString(),
            'amount' => -999.00,
        ]);

        $totals = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => $other->id]))
            ->viewData('totals');

        $this->assertSame(-700.00, $totals['adjustments_total']);

        $defaultRange = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');
        $this->assertSame(-800.00, $defaultRange['adjustments_total']);
    }

    public function test_the_three_tables_paginate_independently(): void
    {
        $this->gcashSale(10.00);
        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
        ]);
        GcashAdjustment::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'adjustment_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['adjustment_page' => 2]))
            ->assertOk();

        // Paging the adjustments must not empty the other two tables.
        $this->assertCount(1, $response->viewData('sales'));
        $this->assertCount(1, $response->viewData('expenses'));
        $this->assertSame('adjustment_page', $response->viewData('adjustments')->getPageName());
    }
}
