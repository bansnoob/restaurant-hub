<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ExpenseController::update writes `$validated['x'] ?? null` for the optional columns, so any
 * field an edit form leaves out is wiped on save. Every form that posts to expenses.update must
 * therefore submit all of them.
 */
class ExpenseFormFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** Optional columns ExpenseController::update nulls when the request omits them. */
    private const WIPEABLE_FIELDS = ['expense_category_id', 'reference_no', 'vendor_name', 'notes'];

    private User $owner;

    private Branch $branch;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
        $this->category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);
    }

    /**
     * Field names inside the add/edit drawer only — the page also has a filter form that
     * carries an expense_category_id select, which would mask a missing drawer input.
     *
     * @return array<int, string>
     */
    private function drawerFieldNames(string $html, string $mustContain): array
    {
        preg_match_all('/<form[^>]*class="[^"]*rm-drawer[^"]*"[^>]*>(.*?)<\/form>/s', $html, $forms);

        foreach ($forms[1] as $body) {
            if (! str_contains($body, $mustContain)) {
                continue;
            }
            preg_match_all('/name="([a-z_]+)"/', $body, $names);

            return array_values(array_unique($names[1]));
        }

        return [];
    }

    public function test_the_expenses_drawer_submits_every_field_the_update_rewrites(): void
    {
        $html = $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk()->getContent();

        $fields = $this->drawerFieldNames($html, 'name="expense_date"');

        $this->assertNotEmpty($fields, 'Could not find the expenses add/edit drawer.');

        foreach (self::WIPEABLE_FIELDS as $field) {
            $this->assertContains(
                $field,
                $fields,
                "The expenses drawer does not submit `{$field}`, so editing an expense would null it."
            );
        }
    }

    public function test_the_gcash_report_expense_drawer_submits_every_field_the_update_rewrites(): void
    {
        $html = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk()->getContent();

        $fields = $this->drawerFieldNames($html, 'name="expense_date"');

        $this->assertNotEmpty($fields, 'Could not find the GCash expense drawer.');

        foreach (self::WIPEABLE_FIELDS as $field) {
            $this->assertContains(
                $field,
                $fields,
                "The GCash expense drawer does not submit `{$field}`, so editing an expense would null it."
            );
        }
    }

    /**
     * A select whose bound value matches no option falls back to its first one, so offering a
     * subset of the valid methods silently rewrites the rest. The mobile API can create
     * bank_transfer/other expenses, and this page filters and totals them, so editing one must
     * not convert it to cash.
     */
    public function test_the_expenses_drawer_offers_every_payment_method_the_controller_accepts(): void
    {
        $html = $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk()->getContent();

        preg_match_all('/<form[^>]*class="[^"]*rm-drawer[^"]*"[^>]*>(.*?)<\/form>/s', $html, $forms);

        $drawer = '';
        foreach ($forms[1] as $body) {
            if (str_contains($body, 'name="payment_method"')) {
                $drawer = $body;
                break;
            }
        }
        $this->assertNotEmpty($drawer, 'Could not find the expenses drawer payment method select.');

        preg_match('/<select name="payment_method".*?<\/select>/s', $drawer, $select);
        $this->assertNotEmpty($select, 'Could not isolate the payment method select.');

        foreach (['cash', 'bank_transfer', 'gcash', 'other'] as $method) {
            $this->assertStringContainsString(
                'value="'.$method.'"',
                $select[0],
                "The drawer does not offer `{$method}`, so editing such an expense would silently rewrite it."
            );
        }
    }

    public function test_editing_a_bank_transfer_expense_does_not_convert_it(): void
    {
        $expense = Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 100.00,
            'description' => 'Supplier wire',
            'payment_method' => 'bank_transfer',
        ]);

        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Supplier wire (corrected)',
            'amount' => 100.00,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        $this->assertSame('bank_transfer', $expense->fresh()->payment_method);
    }

    public function test_editing_an_expense_preserves_its_optional_fields(): void
    {
        $expense = Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 100.00,
            'description' => 'Rice sack',
            'payment_method' => 'cash',
            'expense_category_id' => $this->category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'REF-123',
            'notes' => 'Delivered Tuesday',
        ]);

        // What the drawer now submits when only the amount is corrected.
        $this->actingAs($this->owner)->put(route('expenses.update', $expense), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Rice sack',
            'amount' => 150.00,
            'payment_method' => 'cash',
            'expense_category_id' => $this->category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'REF-123',
            'notes' => 'Delivered Tuesday',
        ])->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(150.00, (float) $expense->amount);
        $this->assertSame($this->category->id, $expense->expense_category_id);
        $this->assertSame('Acme Rice', $expense->vendor_name);
        $this->assertSame('REF-123', $expense->reference_no);
        $this->assertSame('Delivered Tuesday', $expense->notes);
    }

    public function test_an_expense_can_be_created_with_all_of_its_fields(): void
    {
        $this->actingAs($this->owner)->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Rice sack',
            'amount' => 100.00,
            'payment_method' => 'cash',
            'expense_category_id' => $this->category->id,
            'vendor_name' => 'Acme Rice',
            'reference_no' => 'REF-123',
            'notes' => 'Delivered Tuesday',
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $this->assertSame($this->category->id, $expense->expense_category_id);
        $this->assertSame('Acme Rice', $expense->vendor_name);
        $this->assertSame('REF-123', $expense->reference_no);
        $this->assertSame('Delivered Tuesday', $expense->notes);
    }

    public function test_a_new_category_can_be_created_from_the_form(): void
    {
        $this->actingAs($this->owner)->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Electricity',
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'new_category_name' => 'Utilities',
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $this->assertSame('Utilities', ExpenseCategory::find($expense->expense_category_id)?->name);
    }

    /**
     * new_category_name takes precedence over expense_category_id in the controller, so the
     * form must not submit a stale one. The input is disabled — not merely hidden — whenever a
     * category is selected, and a disabled input is never submitted.
     */
    public function test_the_new_category_input_is_disabled_when_a_category_is_selected(): void
    {
        foreach ([route('expenses.index'), route('gcash-report.index')] as $url) {
            $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/name="new_category_name"[^>]*:disabled="[^"]*expense_category_id/',
                $html,
                "{$url}: new_category_name must be disabled when a category is selected, or a stale ".
                'value would override the chosen category.'
            );
        }
    }
}
