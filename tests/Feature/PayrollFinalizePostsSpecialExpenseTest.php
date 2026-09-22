<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Finalizing a payroll report used to write `status = 'paid'` and nothing else —
 * no ledger entry anywhere. Wages settled in cash therefore stayed open as drafts
 * forever, and the money was recorded by hand somewhere else instead. That is how
 * one wage ended up in two systems at two different amounts, ₱370 apart, with
 * nothing linking them.
 *
 * Finalize now posts the wage to `special_expenses`, which is where salary money
 * already goes. The link is the point as much as the row: `payroll_entry_id` makes
 * the payment and the report the same fact rather than two unrelated ones.
 *
 * It must be `special_expenses`, never `expenses`. Daily expenses feed the drawer
 * reconciliation and the day closure; a month of wages landing there would report
 * a shortage no cashier caused.
 */
class PayrollFinalizePostsSpecialExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private Employee $employee;

    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        $this->employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Rema',
            'last_name' => 'Urbina',
            'daily_rate' => 420,
        ]);

        $this->period = PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => '2026-09-02',
            'end_date' => '2026-09-08',
            'cutoff_label' => 'Sep 2 – Sep 8 · Main',
            'status' => 'draft',
        ]);
    }

    private function entry(string $status = 'draft', float $net = 2270): PayrollEntry
    {
        return PayrollEntry::create([
            'payroll_period_id' => $this->period->id,
            'employee_id' => $this->employee->id,
            'regular_hours' => 48,
            'overtime_hours' => 0,
            'hourly_rate' => 52.5,
            'daily_rate' => 420,
            'gross_pay' => 2520,
            'deductions' => 250,
            'net_pay' => $net,
            'status' => $status,
        ]);
    }

    private function finalize(PayrollEntry $entry, array $extra = [])
    {
        return $this->actingAs($this->owner)
            ->from(route('payroll.index'))
            ->post(route('payroll.finalize'), array_merge([
                'payroll_entry_id' => $entry->id,
            ], $extra));
    }

    public function test_finalizing_posts_the_net_pay_as_a_special_expense(): void
    {
        $entry = $this->entry();

        $this->finalize($entry)->assertSessionHas('success');

        $posted = SpecialExpense::sole();
        $this->assertEquals(2270.0, (float) $posted->amount);
        $this->assertSame($this->branch->id, $posted->branch_id);
        $this->assertSame($entry->id, $posted->payroll_entry_id);
        $this->assertSame($this->owner->id, $posted->recorded_by_user_id);
    }

    public function test_it_is_filed_under_a_salary_category(): void
    {
        $this->finalize($this->entry());

        $category = SpecialExpense::sole()->category;
        $this->assertNotNull($category);
        $this->assertSame('weekly-salary', $category->slug);
    }

    public function test_it_reuses_the_salary_category_the_owner_already_made(): void
    {
        $existing = SpecialExpenseCategory::create([
            'name' => 'Weekly Salary',
            'slug' => 'weekly-salary',
            'is_active' => true,
        ]);

        $this->finalize($this->entry());

        $this->assertSame($existing->id, SpecialExpense::sole()->special_expense_category_id);
        $this->assertSame(1, SpecialExpenseCategory::where('slug', 'weekly-salary')->count());
    }

    public function test_the_row_names_the_employee_and_the_period(): void
    {
        $this->finalize($this->entry());

        $description = SpecialExpense::sole()->description;
        $this->assertStringContainsString('Rema Urbina', $description);
        $this->assertStringContainsString('Sep 2', $description);
    }

    public function test_it_is_dated_to_the_month_the_period_ends_in(): void
    {
        $this->finalize($this->entry());

        $posted = SpecialExpense::sole();
        $this->assertSame('2026-09-01', $posted->period_month->toDateString());
        $this->assertSame(now()->toDateString(), $posted->paid_date->toDateString());
    }

    public function test_it_defaults_to_cash_but_accepts_another_method(): void
    {
        $this->finalize($this->entry());
        $this->assertSame('cash', SpecialExpense::sole()->payment_method);

        SpecialExpense::query()->delete();
        PayrollEntry::query()->delete();

        $this->finalize($this->entry(), ['payment_method' => 'bank_transfer']);
        $this->assertSame('bank_transfer', SpecialExpense::sole()->payment_method);
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $this->finalize($this->entry(), ['payment_method' => 'crypto'])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_it_never_touches_the_daily_expense_ledger(): void
    {
        $this->finalize($this->entry());

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('day_closures', 0);
    }

    public function test_finalizing_twice_does_not_post_twice(): void
    {
        $entry = $this->entry();

        $this->finalize($entry)->assertSessionHas('success');
        $this->finalize($entry)->assertSessionHas('error');

        $this->assertSame(1, SpecialExpense::count());
    }

    public function test_an_already_paid_entry_posts_nothing(): void
    {
        $this->finalize($this->entry('paid'))->assertSessionHas('error');

        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_the_entry_is_still_marked_paid_and_the_period_stamped(): void
    {
        $entry = $this->entry();

        $this->finalize($entry);

        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertNotNull($this->period->fresh()->processed_at);
    }

    public function test_the_posted_row_points_back_at_the_payroll_entry(): void
    {
        $entry = $this->entry();
        $this->finalize($entry);

        $this->assertSame($entry->id, $entry->fresh()->specialExpense->payroll_entry_id);
    }

    public function test_deleting_a_payroll_entry_leaves_the_payment_on_the_books(): void
    {
        $entry = $this->entry();
        $this->finalize($entry);

        $entry->fresh()->delete();

        $posted = SpecialExpense::sole();
        $this->assertNull($posted->payroll_entry_id);
        $this->assertEquals(2270.0, (float) $posted->amount);
    }
}
