<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GcashRecordManagementTest extends TestCase
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
    private function recordPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'sale_date' => now()->toDateString(),
            'amount' => 500.00,
            'description' => 'GCash transfer for catering',
        ], $overrides);
    }

    public function test_owner_can_add_a_gcash_record(): void
    {
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $sale = Sale::firstOrFail();

        $this->assertSame('gcash', $sale->payment_method);
        $this->assertSame('completed', $sale->status);
        $this->assertSame('500.00', (string) $sale->grand_total);
        $this->assertSame('GCash transfer for catering', $sale->notes);
        $this->assertStringStartsWith('GCASH-', $sale->order_number);
        $this->assertTrue($sale->isManualGcashRecord());
    }

    public function test_a_new_record_shows_up_in_the_report_totals(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');

        $this->assertSame(500.00, $totals['gcash_sales_total']);
        $this->assertSame(1, $totals['transaction_count']);
    }

    public function test_cashiers_cannot_add_edit_or_delete_records(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $sale = Sale::firstOrFail();

        $this->actingAs($cashier)->post(route('gcash-report.records.store'), $this->recordPayload())->assertForbidden();
        $this->actingAs($cashier)->put(route('gcash-report.records.update', $sale), $this->recordPayload())->assertForbidden();
        $this->actingAs($cashier)->delete(route('gcash-report.records.destroy', $sale))->assertForbidden();
    }

    public function test_owner_can_edit_a_manual_record(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $sale = Sale::firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $sale), $this->recordPayload([
                'amount' => 750.50,
                'description' => 'Corrected amount',
            ]))
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('750.50', (string) $sale->grand_total);
        $this->assertSame('750.50', (string) $sale->gcash_amount);
        $this->assertSame('Corrected amount', $sale->notes);
    }

    public function test_owner_can_delete_a_manual_record(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $sale = Sale::firstOrFail();

        $this->actingAs($this->owner)
            ->delete(route('gcash-report.records.destroy', $sale))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sales', 0);
    }

    /**
     * A POS sale's grand_total is the sum of its sale_items; rewriting it from a report would
     * leave the two disagreeing, so the report refuses to touch it.
     */
    public function test_a_pos_sale_cannot_be_edited_or_deleted_from_the_report(): void
    {
        $pos = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'order_number' => 'MAIN001-20260716-0001',
            'payment_method' => 'gcash',
            'status' => 'completed',
            'grand_total' => 300.00,
        ]);

        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $pos), $this->recordPayload(['amount' => 1.00]))
            ->assertSessionHas('error');

        $this->actingAs($this->owner)
            ->delete(route('gcash-report.records.destroy', $pos))
            ->assertSessionHas('error');

        $pos->refresh();
        $this->assertSame('300.00', (string) $pos->grand_total);
        $this->assertDatabaseCount('sales', 1);
    }

    /**
     * day_closures.gcash_sales_total is a snapshot taken at closing time. Letting a record
     * change afterwards would leave it stale and make this report disagree with the Cash
     * Report — the invariant GcashReportTest pins.
     */
    public function test_records_cannot_be_added_to_a_closed_day(): void
    {
        DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->toDateString(),
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload())
            ->assertSessionHas('error');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_records_on_a_closed_day_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $sale = Sale::firstOrFail();

        DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->toDateString(),
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $sale), $this->recordPayload(['amount' => 9.00]))
            ->assertSessionHas('error');

        $this->actingAs($this->owner)
            ->delete(route('gcash-report.records.destroy', $sale))
            ->assertSessionHas('error');

        $this->assertSame('500.00', (string) $sale->fresh()->grand_total);
    }

    public function test_a_record_cannot_be_moved_onto_a_closed_day(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $sale = Sale::firstOrFail();

        $closedDay = now()->subDay()->toDateString();
        DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $closedDay,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $sale), $this->recordPayload(['sale_date' => $closedDay]))
            ->assertSessionHas('error');

        $this->assertSame(now()->toDateString(), $sale->fresh()->sale_datetime->toDateString());
    }

    public function test_record_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['amount' => 0]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['amount' => -5]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['description' => '']))
            ->assertSessionHasErrors('description');

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['branch_id' => 999999]))
            ->assertSessionHasErrors('branch_id');

        // More precision than the money columns hold: it would display rounded while the
        // total summed the unrounded value, so the column would not add up to the tile.
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['amount' => 100.999]))
            ->assertSessionHasErrors('amount');

        // Beyond sales.gcash_amount's decimal(10,2) range.
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['amount' => 100000000]))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('sales', 0);
    }

    /**
     * The POS derives its next order number from the last sale of that branch that day. A
     * manual GCASH- row must not be picked up by that lookup, or the POS and the manual
     * records share one running sequence and POS numbers skip (…-0002 then …-0004), which
     * reads as a missing or voided order. Numbers stay unique either way — this is about the
     * POS sequence staying contiguous.
     */
    public function test_adding_a_manual_record_does_not_disturb_pos_order_numbering(): void
    {
        $service = app(SaleService::class);
        $menuItem = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'base_price' => 100.00]);

        $order = fn () => $service->createOrder([
            'branch_id' => $this->branch->id,
            'order_type' => 'dine_in',
            'table_label' => null,
            'notes' => null,
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ], $this->owner);

        $first = $order();
        $second = $order();

        // A manual GCash record lands in the middle, with the highest id for the day.
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload())
            ->assertSessionHasNoErrors();

        // The next POS order must continue the POS sequence, not the manual one.
        $third = $order();

        $this->assertSame('MAIN001-'.now()->format('Ymd').'-0001', $first->order_number);
        $this->assertSame('MAIN001-'.now()->format('Ymd').'-0002', $second->order_number);
        $this->assertSame('MAIN001-'.now()->format('Ymd').'-0003', $third->order_number);

        $this->assertSame(
            Sale::count(),
            Sale::distinct()->count('order_number'),
            'Order numbers must stay unique within the branch.'
        );
    }

    /**
     * branches.code is validated only as a string, so it can contain LIKE wildcards. A code of
     * '%' would otherwise match the GCASH- rows and drag the POS back onto a shared sequence.
     */
    public function test_a_branch_code_containing_like_wildcards_does_not_break_numbering(): void
    {
        $odd = Branch::factory()->create(['code' => '%']);
        $service = app(SaleService::class);
        $menuItem = MenuItem::factory()->create(['branch_id' => $odd->id, 'base_price' => 50.00]);

        $order = fn () => $service->createOrder([
            'branch_id' => $odd->id,
            'order_type' => 'dine_in',
            'table_label' => null,
            'notes' => null,
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ], $this->owner);

        $first = $order();

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['branch_id' => $odd->id]))
            ->assertSessionHasNoErrors();

        $second = $order();

        $this->assertSame('%-'.now()->format('Ymd').'-0001', $first->order_number);
        $this->assertSame('%-'.now()->format('Ymd').'-0002', $second->order_number);
    }

    /**
     * Editing a record onto another branch and back re-issues its number, so the newest row is
     * no longer the highest-numbered one. Continuing the sequence from the newest row would
     * re-issue a number already in use and permanently wedge new records for that branch/day
     * behind a unique-constraint violation.
     */
    public function test_moving_a_record_between_branches_and_back_does_not_wedge_numbering(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB']);

        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $first = Sale::orderBy('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $first), $this->recordPayload(['branch_id' => $other->id]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->put(route('gcash-report.records.update', $first), $this->recordPayload(['branch_id' => $this->branch->id]))
            ->assertSessionHasNoErrors();

        // Must still be able to add to that branch and day.
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload())
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload())
            ->assertSessionHasNoErrors();

        $numbers = Sale::where('branch_id', $this->branch->id)->pluck('order_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count(), 'Order numbers must stay unique within a branch.');
    }

    public function test_moving_a_record_between_days_and_back_does_not_wedge_numbering(): void
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload(['sale_date' => $today]));
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload(['sale_date' => $today]));
        $first = Sale::orderBy('id')->firstOrFail();

        $this->actingAs($this->owner)->put(route('gcash-report.records.update', $first), $this->recordPayload(['sale_date' => $tomorrow]));
        $this->actingAs($this->owner)->put(route('gcash-report.records.update', $first), $this->recordPayload(['sale_date' => $today]));

        $this->actingAs($this->owner)
            ->post(route('gcash-report.records.store'), $this->recordPayload(['sale_date' => $today]))
            ->assertSessionHasNoErrors();

        $numbers = Sale::where('branch_id', $this->branch->id)->pluck('order_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count(), 'Order numbers must stay unique within a branch.');
    }

    public function test_manual_records_get_their_own_sequence(): void
    {
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());
        $this->actingAs($this->owner)->post(route('gcash-report.records.store'), $this->recordPayload());

        $numbers = Sale::orderBy('id')->pluck('order_number')->all();

        $this->assertSame([
            'GCASH-'.now()->format('Ymd').'-0001',
            'GCASH-'.now()->format('Ymd').'-0002',
        ], $numbers);
    }

    public function test_gcash_expenses_are_listed_on_the_report(): void
    {
        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 120.00,
            'description' => 'Supplier payment via GCash',
        ]);
        // A cash expense must not appear on the GCash report.
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 999.00,
            'description' => 'Paid in cash',
        ]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertCount(1, $response->viewData('expenses'));
        $response->assertSee('Supplier payment via GCash');
        $response->assertDontSee('Paid in cash');
    }

    public function test_an_expense_added_from_the_report_is_a_gcash_expense(): void
    {
        $this->actingAs($this->owner)->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Supplier payment via GCash',
            'amount' => 120.00,
            'payment_method' => 'gcash',
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $this->assertSame('gcash', $expense->payment_method);

        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');
        $this->assertSame(120.00, $totals['gcash_expenses_total']);
        $this->assertSame(-120.00, $totals['net_gcash']);
    }

    /**
     * ExpenseController::update writes `$validated['x'] ?? null` for category, vendor,
     * reference and notes — so any field the form omits is wiped. The GCash expense drawer
     * must therefore round-trip all of them; editing an amount must not cost the category.
     */
    public function test_editing_a_gcash_expense_preserves_its_other_fields(): void
    {
        $category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);

        $expense = Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 100.00,
            'description' => 'Rice sack',
            'expense_category_id' => $category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'GC-99887',
            'notes' => 'Delivered Tuesday',
        ]);

        // Exactly what the drawer submits when only the amount is corrected.
        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Rice sack',
            'amount' => 150.00,
            'payment_method' => 'gcash',
            'expense_category_id' => $category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'GC-99887',
            'notes' => 'Delivered Tuesday',
        ])->assertSessionHasNoErrors();

        $expense->refresh();
        // Expense has no decimal casts, so compare numerically rather than by string shape.
        $this->assertSame(150.00, (float) $expense->amount);
        $this->assertSame($category->id, $expense->expense_category_id);
        $this->assertSame('Acme Rice', $expense->vendor_name);
        $this->assertSame('GC-99887', $expense->reference_no);
        $this->assertSame('Delivered Tuesday', $expense->notes);
    }

    /**
     * Those fields must reach the page, or the drawer would submit blanks and wipe them.
     */
    public function test_the_expense_row_payload_carries_every_field_the_update_rewrites(): void
    {
        $category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);
        Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'GC-99887',
            'notes' => 'Delivered Tuesday',
        ]);

        $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->assertSee('Acme Rice', false)
            ->assertSee('GC-99887', false)
            ->assertSee('Delivered Tuesday', false)
            ->assertSee('new_category_name', false);
    }

    public function test_sales_and_expense_tables_paginate_independently(): void
    {
        Sale::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => 'gcash',
            'status' => 'completed',
            'grand_total' => 10.00,
            'sale_datetime' => now(),
        ]);
        Expense::factory()->gcash()->count(3)->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index', ['expense_page' => 2]))->assertOk();

        // Paging the expense table must not empty the sales table.
        $this->assertCount(3, $response->viewData('sales'));
        $this->assertSame('expense_page', $response->viewData('expenses')->getPageName());
    }
}
