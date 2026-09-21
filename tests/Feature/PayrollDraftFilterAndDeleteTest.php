<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The payroll list joins payroll_entries to payroll_periods, and BOTH tables
 * carry a `status` column. An unqualified `where('status', ...)` therefore makes
 * the database refuse the whole query, so the Draft and Paid chips 500 while the
 * All chip (which adds no predicate) works — and the delete action, which only
 * renders on a draft row, becomes unreachable from the filtered view.
 */
class PayrollDraftFilterAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function makeEntry(string $status, string $start = '2026-09-01', string $end = '2026-09-15'): PayrollEntry
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id]);
        $period = PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => $start,
            'end_date' => $end,
            'cutoff_label' => $start.' to '.$end,
            'status' => 'draft',
        ]);

        return PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'regular_hours' => 80,
            'overtime_hours' => 0,
            'hourly_rate' => 0,
            'gross_pay' => 8000,
            'deductions' => 0,
            'net_pay' => 8000,
            'status' => $status,
        ]);
    }

    public function test_draft_filter_does_not_error(): void
    {
        $draft = $this->makeEntry('draft');
        $this->makeEntry('paid', '2026-08-01', '2026-08-15');

        $response = $this->actingAs($this->owner)->get(route('payroll.index', ['status' => 'draft']));

        $response->assertOk();
        $response->assertSee($draft->employee->employee_code);
    }

    public function test_paid_filter_does_not_error(): void
    {
        $paid = $this->makeEntry('paid');

        $response = $this->actingAs($this->owner)->get(route('payroll.index', ['status' => 'paid']));

        $response->assertOk();
        $response->assertSee($paid->employee->employee_code);
    }

    public function test_draft_filter_excludes_paid_entries(): void
    {
        $draft = $this->makeEntry('draft');
        $paid = $this->makeEntry('paid', '2026-08-01', '2026-08-15');

        $response = $this->actingAs($this->owner)->get(route('payroll.index', ['status' => 'draft']));

        $response->assertOk();
        // Asserted on the listing rather than the markup: every active employee is also
        // embedded in the bulk-generate drawer, so their code is on the page regardless.
        $listed = $response->viewData('reports')->pluck('id')->all();
        $this->assertSame([$draft->id], $listed);
        $this->assertNotContains($paid->id, $listed);
    }

    public function test_draft_entry_can_be_deleted(): void
    {
        $draft = $this->makeEntry('draft');

        $response = $this->actingAs($this->owner)
            ->from(route('payroll.index'))
            ->delete(route('payroll.reports.destroy', $draft));

        $response->assertRedirect(route('payroll.index'));
        $this->assertDatabaseMissing('payroll_entries', ['id' => $draft->id]);
    }

    public function test_deleting_the_last_draft_entry_also_removes_its_empty_period(): void
    {
        $draft = $this->makeEntry('draft');
        $periodId = $draft->payroll_period_id;

        $this->actingAs($this->owner)
            ->from(route('payroll.index'))
            ->delete(route('payroll.reports.destroy', $draft));

        $this->assertDatabaseMissing('payroll_periods', ['id' => $periodId]);
    }

    public function test_delete_survives_being_issued_from_the_filtered_view(): void
    {
        $draft = $this->makeEntry('draft');

        $response = $this->actingAs($this->owner)
            ->from(route('payroll.index', ['status' => 'draft']))
            ->delete(route('payroll.reports.destroy', $draft));

        $response->assertRedirect(route('payroll.index', ['status' => 'draft']));
        $this->assertDatabaseMissing('payroll_entries', ['id' => $draft->id]);

        $this->actingAs($this->owner)
            ->get(route('payroll.index', ['status' => 'draft']))
            ->assertOk();
    }

    public function test_search_only_returns_the_matching_employee(): void
    {
        $wanted = $this->makeEntry('draft');
        $wanted->employee->update(['first_name' => 'Rema', 'last_name' => 'Urbina']);
        $other = $this->makeEntry('draft', '2026-08-01', '2026-08-15');
        $other->employee->update(['first_name' => 'Aldrin', 'last_name' => 'Quilop']);

        $response = $this->actingAs($this->owner)->get(route('payroll.index', ['search' => 'Urbina']));

        $response->assertOk();
        $listed = $response->viewData('reports')->pluck('id')->all();
        $this->assertSame([$wanted->id], $listed);
    }
}
