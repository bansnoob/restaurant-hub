<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "I edited a GCASH OUT record and it seems like it duplicated or something."
 *
 * A GCash Out row is an `expenses` row with payment_method = 'gcash'. The GCash report's
 * expense drawer is one <form> that is either a create or an edit depending on two
 * Alpine expressions evaluated at submit time:
 *
 *   :action="expense.mode === 'edit' ? expense.action : '{{ route('expenses.store') }}'"
 *   <template x-if="expense.mode === 'edit'"><input name="_method" value="PUT"></template>
 *
 * Both read expense.mode. When it says 'edit' the request updates in place; when it does
 * not, the very same filled-in form POSTs to expenses.store and INSERTS. These tests pin
 * down which requests update and which insert, using the drawer's exact field set.
 *
 * Models production expense id=438 — "GCash out", 500.00, 2026-09-12, branch 1 — with its
 * legitimate same-day sibling id=439 at 1,123.00 present so row counts are meaningful.
 */
class GcashOutEditDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private ExpenseCategory $category;

    /** The production date of the edited row. */
    private string $date = '2026-09-12';

    private Expense $gcashOut;

    private Expense $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        $this->category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);

        // id=438 equivalent: the row the user edited.
        $this->gcashOut = Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => $this->date,
            'description' => 'GCash out',
            'amount' => 500.00,
            'vendor_name' => 'Supplier',
            'reference_no' => 'GC-0001',
            'notes' => null,
            'status' => 'approved',
        ]);

        // id=439 equivalent: a second, legitimate GCash Out on the same day.
        $this->sibling = Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => null,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => $this->date,
            'description' => 'GCash out',
            'amount' => 1123.00,
            'status' => 'approved',
        ]);
    }

    /**
     * Exactly what openExpenseEdit() loads into the drawer and the drawer then submits:
     * every field ExpenseController::update writes, plus the fixed payment_method=gcash
     * hidden input. new_category_name is omitted because the blade disables that input
     * whenever a category is selected (a disabled input is not submitted).
     */
    private function drawerPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => (string) $this->branch->id,
            'expense_date' => $this->date,
            'description' => 'GCash out',
            'amount' => '750.00',
            'payment_method' => 'gcash',
            'expense_category_id' => (string) $this->category->id,
            'vendor_name' => 'Supplier',
            'reference_no' => 'GC-0001',
            'notes' => '',
        ], $overrides);
    }

    private function closeTheDay(): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $this->date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 0,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'variance' => 0,
            'order_count' => 0,
            'expense_count' => 2,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. The edit as the drawer sends it: PUT to expenses.update.
    // ---------------------------------------------------------------------

    public function test_put_edit_updates_in_place_and_does_not_insert_a_row(): void
    {
        $before = Expense::count();

        $response = $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload());

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('gcash-report.index'));

        $this->assertSame($before, Expense::count(), 'The PUT edit must not create a row.');

        $this->gcashOut->refresh();
        $this->assertSame(750.00, (float) $this->gcashOut->amount);
        $this->assertSame('gcash', $this->gcashOut->payment_method);
        $this->assertSame($this->date, substr((string) $this->gcashOut->expense_date, 0, 10));
    }

    /**
     * The browser does not send a real PUT. It POSTs with a hidden _method=PUT. If Laravel's
     * method override is what makes the edit an edit, then the POST+_method form of the very
     * same request must behave identically.
     */
    public function test_post_with_method_spoof_to_the_update_url_also_updates_in_place(): void
    {
        $before = Expense::count();

        $response = $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->post(
                route('expenses.update', $this->gcashOut),
                $this->drawerPayload(['_method' => 'PUT', 'amount' => '815.00'])
            );

        $this->assertSame(
            $before,
            Expense::count(),
            'The spoofed-PUT edit (what the browser actually sends) must not create a row. '
            ."Status was {$response->getStatusCode()}."
        );

        $this->gcashOut->refresh();
        $this->assertSame(815.00, (float) $this->gcashOut->amount);
    }

    // ---------------------------------------------------------------------
    // 2. The failure mode: the same payload with no spoof, aimed at store.
    // ---------------------------------------------------------------------

    /**
     * This is the duplicate. The drawer's :action falls back to expenses.store the moment
     * expense.mode is anything but 'edit' — and the same hidden _method template is gated on
     * the same expression, so the two fail together. The user sees a filled-in "Edit GCash
     * Expense" form, presses Save, and gets a second row carrying the EDITED values while
     * the original keeps the old ones. Nothing is a literal duplicate of anything, which is
     * exactly what production shows: two similar rows, no exact-match group.
     */
    public function test_the_same_payload_posted_to_store_inserts_a_second_row(): void
    {
        $before = Expense::count();

        $response = $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->post(route('expenses.store'), $this->drawerPayload());

        $response->assertSessionHasNoErrors();

        $this->assertSame(
            $before + 1,
            Expense::count(),
            'A create-mode submit of an edit-filled drawer inserts rather than updates.'
        );

        // The original is untouched at its old amount...
        $this->gcashOut->refresh();
        $this->assertSame(500.00, (float) $this->gcashOut->amount);

        // ...and a near-twin now sits beside it with the edited amount.
        $twins = Expense::where('branch_id', $this->branch->id)
            ->whereDate('expense_date', $this->date)
            ->where('description', 'GCash out')
            ->where('payment_method', 'gcash')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $twins, 'Three GCash Out rows on one day where the user expected two.');
        $this->assertSame([500.00, 1123.00, 750.00], $twins->pluck('amount')->map(fn ($a) => (float) $a)->all());
    }

    // ---------------------------------------------------------------------
    // 3. A day that has been closed.
    // ---------------------------------------------------------------------

    public function test_editing_a_gcash_expense_on_a_closed_day_does_not_insert_a_row(): void
    {
        $closure = $this->closeTheDay();
        $before = Expense::count();

        $response = $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload(['amount' => '900.00']));

        $response->assertSessionHasNoErrors();

        $this->assertSame($before, Expense::count(), 'A closed day must not turn an edit into an insert.');
        $this->assertSame(1, DayClosure::count(), 'The edit must not create a second closure for the day.');

        $this->gcashOut->refresh();
        $this->assertSame(900.00, (float) $this->gcashOut->amount);

        // GCash expenses never touch the drawer, so the closure's cash figures must not move.
        $closure->refresh();
        $this->assertSame(0.00, (float) $closure->cash_expenses_total);
        $this->assertSame(2, $closure->expense_count);
    }

    // ---------------------------------------------------------------------
    // 4. An edit that moves the row to another day.
    // ---------------------------------------------------------------------

    public function test_editing_the_date_moves_the_row_rather_than_copying_it(): void
    {
        $before = Expense::count();
        $newDate = '2026-09-13';

        $response = $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload(['expense_date' => $newDate]));

        $response->assertSessionHasNoErrors();

        $this->assertSame($before, Expense::count(), 'Changing the date must move the row, not copy it.');

        $this->gcashOut->refresh();
        $this->assertSame($newDate, substr((string) $this->gcashOut->expense_date, 0, 10));

        $this->assertSame(
            1,
            Expense::whereDate('expense_date', $this->date)->count(),
            'Only the sibling should remain on the original day.'
        );
        $this->assertSame(1, Expense::whereDate('expense_date', $newDate)->count());
    }

    public function test_moving_a_closed_day_expense_leaves_one_row_and_both_closures_intact(): void
    {
        $closure = $this->closeTheDay();
        $before = Expense::count();

        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload(['expense_date' => '2026-09-13']))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Expense::count());
        $this->assertSame(1, DayClosure::count());

        $closure->refresh();
        $this->assertSame(1, $closure->expense_count, 'The origin day should be left holding only the sibling.');
    }

    // ---------------------------------------------------------------------
    // 5. What the report renders afterwards — a display duplicate would look
    //    the same to the user as a row duplicate.
    // ---------------------------------------------------------------------

    public function test_the_gcash_report_lists_the_edited_row_once(): void
    {
        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload(['description' => 'GCash out edited']))
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', [
                'branch_id' => $this->branch->id,
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]))
            ->assertOk()
            ->getContent();

        // Each rendered GCash Out row emits one openExpenseEdit(...) call carrying its id.
        // (The row's Delete button emits the same payload to deleteExpense(...), which is
        // why a bare id search finds two hits per row - match the Edit call specifically.)
        // Blade's @js escapes the payload's quotes as \u0022.
        $marker = 'openExpenseEdit(JSON.parse(\'{\u0022id\u0022:'.$this->gcashOut->id.',';
        $this->assertSame(
            1,
            substr_count($html, $marker),
            'The edited expense must appear exactly once in the GCash Out table.'
        );
        $this->assertStringContainsString('GCash out edited', $html);
    }

    /**
     * NOT a duplicate, but the same edit request found while modelling it: an expense whose
     * category is is_active = 0 is not in the drawer's <select> (both ExpenseController and
     * GcashReportController build the list with where('is_active', true)), so the browser
     * submits expense_category_id = '' for it. Verified in a real browser against Alpine
     * 3.15.8: the select falls back to '' while Alpine state still holds the id, which keeps
     * the "New category" input :disabled and unsubmitted. The edit then silently clears the
     * category. Only reachable if a category was deactivated directly in the database - no
     * screen does that today.
     */
    public function test_editing_an_expense_whose_category_is_inactive_clears_the_category(): void
    {
        $this->category->update(['is_active' => false]);
        $before = Expense::count();

        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->gcashOut), $this->drawerPayload([
                'expense_category_id' => '',   // what the browser sends for an absent <option>
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Expense::count(), 'Still no extra row - this is a wipe, not a duplicate.');

        $this->gcashOut->refresh();
        $this->assertNull(
            $this->gcashOut->expense_category_id,
            'An amount-only edit silently dropped the category.'
        );
    }

    /**
     * The drawer submits new_category_name whenever no category is selected. If that also
     * created an expense, an edit would leave two rows. It must only ever touch categories.
     */
    public function test_editing_with_a_new_category_name_does_not_insert_a_second_expense(): void
    {
        $before = Expense::count();

        $this->actingAs($this->owner)
            ->from(route('gcash-report.index'))
            ->put(route('expenses.update', $this->sibling), $this->drawerPayload([
                'expense_category_id' => '',
                'new_category_name' => 'GCash Transfers',
                'amount' => '1123.00',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Expense::count());
        $this->sibling->refresh();
        $this->assertNotNull($this->sibling->expense_category_id);
    }
}
