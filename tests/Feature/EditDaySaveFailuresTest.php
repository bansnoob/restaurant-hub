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
 * Three ways saving Edit Day on a closed day went wrong:
 *
 *  1. The category <select> drew its options (x-for) after x-model had set its
 *     value, so it showed and SUBMITTED "Uncategorised". The form posts every cash
 *     row on the day, so opening a day and pressing Save, changing nothing, wiped
 *     the category from all of them. In place since the select was added on
 *     2026-09-22 (7166c06).
 *  2. A row with a blank description (a newly added row, or an existing one
 *     cleared) was written as NULL into a NOT NULL column: a 500.
 *  3. Descriptions were validated to 255 characters against a 200-character
 *     column: a 500 on MySQL's strict mode (SQLite does not enforce the length).
 *
 * And the Cash Report page never displayed validation errors at all, so a
 * refused save just looked like the page reloading.
 */
class EditDaySaveFailuresTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private DayClosure $closure;

    private Expense $ice;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        $this->closure = DayClosure::create([
            'branch_id' => $this->branch->id, 'closed_at_date' => '2026-10-22', 'closed_by_user_id' => $this->owner->id,
            'closed_at' => '2026-10-22 22:00:00', 'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 120, 'expected_cash' => -120, 'counted_cash' => 0,
            'variance' => 120, 'order_count' => 0, 'expense_count' => 1,
        ]);
        $supplies = ExpenseCategory::create(['branch_id' => null, 'name' => 'Supplies', 'slug' => 'supplies', 'is_active' => true]);
        $this->ice = Expense::factory()->create([
            'branch_id' => $this->branch->id, 'expense_date' => '2026-10-22', 'payment_method' => 'cash',
            'paid_from' => 'drawer', 'amount' => 120, 'description' => 'Ice', 'expense_category_id' => $supplies->id,
        ]);
    }

    private function iceRow(array $overrides = []): array
    {
        return array_merge([
            'id' => $this->ice->id,
            'description' => 'Ice',
            'vendor_name' => $this->ice->vendor_name,
            'expense_category_id' => (string) $this->ice->expense_category_id,
            'amount' => 120,
            'paid_from' => 'drawer',
        ], $overrides);
    }

    private function save(array $rows)
    {
        return $this->actingAs($this->owner)
            ->from(route('day-closures.index'))
            ->put(route('day-close.update', $this->closure), ['counted_cash' => 0, 'expenses' => $rows]);
    }

    // ── 2 + 3: refused with a message, not a 500 ─────────────────────────

    public function test_a_new_row_without_a_description_is_refused_not_a_500(): void
    {
        $this->save([$this->iceRow(), ['id' => '', 'description' => '', 'amount' => 50, 'paid_from' => 'drawer']])
            ->assertRedirect(route('day-closures.index'))
            ->assertSessionHasErrors('expenses.1.description');

        $this->assertSame(1, Expense::count());
    }

    public function test_clearing_an_existing_rows_description_is_refused_not_a_500(): void
    {
        $this->save([$this->iceRow(['description' => ''])])->assertSessionHasErrors('expenses.0.description');

        $this->assertSame('Ice', $this->ice->fresh()->description);
    }

    public function test_a_description_longer_than_the_column_is_refused(): void
    {
        $this->save([$this->iceRow(['description' => str_repeat('x', 201)])])->assertSessionHasErrors('expenses.0.description');
    }

    public function test_a_description_at_the_column_limit_saves(): void
    {
        $this->save([$this->iceRow(['description' => str_repeat('x', 200)])])->assertSessionHasNoErrors();

        $this->assertSame(200, strlen($this->ice->fresh()->description));
    }

    public function test_the_cash_report_shows_why_a_save_was_refused(): void
    {
        $this->save([$this->iceRow(['description' => ''])]);

        $this->actingAs($this->owner)->get(route('day-closures.index'))
            ->assertOk()
            ->assertSee('Each expense needs a description.');
    }

    // ── 1: the category select keeps the saved category ──────────────────

    public function test_saving_the_category_the_form_carries_keeps_it(): void
    {
        $this->save([$this->iceRow()])->assertSessionHasNoErrors();

        $this->assertSame($this->ice->expense_category_id, $this->ice->fresh()->expense_category_id);
    }

    public function test_the_browser_refuses_a_blank_or_long_description_before_sending(): void
    {
        $html = $this->actingAs($this->owner)->get(route('day-closures.index'))->assertOk()->getContent();

        // Stops the submit in the browser, so the drawer stays open with the edits.
        $this->assertMatchesRegularExpression(
            '/placeholder="Description" required maxlength="200"\s+:name="\'expenses\[\' \+ i \+ \'\]\[description\]\'"/',
            $html
        );
    }

    public function test_the_category_options_select_the_rows_saved_category(): void
    {
        $html = $this->actingAs($this->owner)->get(route('day-closures.index'))->assertOk()->getContent();

        // Bound per option: x-model alone runs before the x-for options exist, and the
        // select then shows (and submits) "Uncategorised".
        $this->assertMatchesRegularExpression(
            '/<template x-for="c in \(day\.categories \|\| \[\]\)"[^>]*>\s*<option :value="c\.id" :selected="String\(c\.id\) === String\(row\.expense_category_id\)"/',
            $html
        );
    }
}
