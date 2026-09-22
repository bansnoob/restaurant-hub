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
 * Characterisation of Edit Day on a closed day.
 *
 * The drawer submits every cash row on the day, not only the ones that were touched,
 * so any field it does not carry is written back as whatever update() defaults it to.
 */
class EditDayPreservesExpenseFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private DayClosure $closure;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        $this->closure = DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => '2026-09-10',
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => '2026-09-10 21:30:00',
            'opening_float' => 1000,
            'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 500, 'expected_cash' => 500,
            'counted_cash' => 500, 'variance' => 0,
            'order_count' => 0, 'expense_count' => 1,
        ]);
    }

    private function expense(array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-10',
            'description' => 'Cooking oil',
            'vendor_name' => 'Puregold',
            'reference_no' => 'OR-1234',
            'notes' => 'bought with the morning delivery',
            'amount' => 500,
            'payment_method' => 'cash',
            'paid_from' => 'drawer',
            'status' => 'approved',
            'recorded_by_user_id' => $this->owner->id,
        ], $overrides));
    }

    /** The payload the Edit Day drawer actually posts — description, id, amount, paid_from. */
    private function save(array $rows, array $extra = [])
    {
        return $this->actingAs($this->owner)
            ->from(route('day-closures.index'))
            ->put(route('day-close.update', $this->closure), array_merge([
                'counted_cash' => 500,
                'expenses' => $rows,
            ], $extra));
    }

    public function test_saving_an_untouched_day_keeps_the_vendor(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'amount' => 500,
            'paid_from' => 'drawer',
        ]]);

        $this->assertSame('Puregold', $expense->fresh()->vendor_name);
    }

    public function test_saving_an_untouched_day_keeps_the_reference_and_notes(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'amount' => 500,
            'paid_from' => 'drawer',
        ]]);

        $fresh = $expense->fresh();
        $this->assertSame('OR-1234', $fresh->reference_no);
        $this->assertSame('bought with the morning delivery', $fresh->notes);
    }

    public function test_saving_keeps_the_category(): void
    {
        $category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);
        $expense = $this->expense(['expense_category_id' => $category->id]);

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'amount' => 500,
            'paid_from' => 'drawer',
        ]]);

        $this->assertSame($category->id, $expense->fresh()->expense_category_id);
    }

    public function test_a_row_added_here_can_carry_a_category(): void
    {
        $category = ExpenseCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);

        $this->save([[
            'description' => 'Ice',
            'amount' => 120,
            'paid_from' => 'drawer',
            'expense_category_id' => $category->id,
        ]]);

        $created = Expense::where('description', 'Ice')->sole();
        $this->assertSame($category->id, $created->expense_category_id);
    }

    public function test_the_closure_itself_is_recomputed(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'amount' => 300,
            'paid_from' => 'drawer',
        ]]);

        $this->closure->refresh();
        $this->assertEquals(300.0, (float) $this->closure->cash_expenses_total);
        $this->assertEquals(700.0, (float) $this->closure->expected_cash);
    }

    public function test_an_explicit_vendor_change_still_applies(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'vendor_name' => 'SM Hypermarket',
            'amount' => 500,
            'paid_from' => 'drawer',
        ]]);

        $this->assertSame('SM Hypermarket', $expense->fresh()->vendor_name);
    }

    public function test_clearing_the_vendor_on_purpose_still_works(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id,
            'description' => 'Cooking oil',
            'vendor_name' => '',
            'amount' => 500,
            'paid_from' => 'drawer',
        ]]);

        $this->assertNull($expense->fresh()->vendor_name);
    }

    public function test_the_category_can_be_changed_and_cleared(): void
    {
        $category = ExpenseCategory::create([
            'branch_id' => $this->branch->id, 'name' => 'Supplies', 'slug' => 'supplies', 'is_active' => true,
        ]);
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id, 'description' => 'Cooking oil', 'amount' => 500,
            'paid_from' => 'drawer', 'expense_category_id' => $category->id,
        ]]);
        $this->assertSame($category->id, $expense->fresh()->expense_category_id);

        $this->save([[
            'id' => $expense->id, 'description' => 'Cooking oil', 'amount' => 500,
            'paid_from' => 'drawer', 'expense_category_id' => '',
        ]]);
        $this->assertNull($expense->fresh()->expense_category_id);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $expense = $this->expense();

        $this->save([[
            'id' => $expense->id, 'description' => 'Cooking oil', 'amount' => 500,
            'paid_from' => 'drawer', 'expense_category_id' => 999999,
        ]])->assertSessionHasErrors('expenses.0.expense_category_id');
    }

    public function test_the_edit_endpoint_offers_the_branchs_categories(): void
    {
        $mine = ExpenseCategory::create([
            'branch_id' => $this->branch->id, 'name' => 'Supplies', 'slug' => 'supplies', 'is_active' => true,
        ]);
        $shared = ExpenseCategory::create([
            'branch_id' => null, 'name' => 'Utilities', 'slug' => 'utilities', 'is_active' => true,
        ]);
        $elsewhere = ExpenseCategory::create([
            'branch_id' => Branch::factory()->create()->id, 'name' => 'Other branch', 'slug' => 'other-branch', 'is_active' => true,
        ]);

        $ids = $this->actingAs($this->owner)
            ->getJson(route('day-close.edit', $this->closure))
            ->assertOk()
            ->json('categories.*.id');

        $this->assertContains($mine->id, $ids);
        $this->assertContains($shared->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_the_edit_endpoint_still_sends_the_vendor(): void
    {
        $this->expense();

        $this->actingAs($this->owner)
            ->getJson(route('day-close.edit', $this->closure))
            ->assertOk()
            ->assertJsonPath('expenses.0.vendor_name', 'Puregold');
    }

    public function test_the_drawer_markup_carries_vendor_and_category(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('day-closures.index'))->assertOk()->getContent();

        $this->assertStringContainsString('[vendor_name]', $html, 'the drawer cannot submit a vendor');
        $this->assertStringContainsString('[expense_category_id]', $html, 'the drawer cannot submit a category');
    }
}
