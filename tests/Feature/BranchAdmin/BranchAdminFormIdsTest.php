<?php

namespace Tests\Feature\BranchAdmin;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\GcashEntryStatus;
use App\Models\MenuItem;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Records named in a form BODY, not the URL, where LockToOwnBranch cannot see them:
 * an attendance record_id or employee_id, payroll employee_ids, a payroll_entry_id,
 * a GCash statement entry. Each must belong to the admin's branch.
 *
 * Also: a branch admin signs in to the iPad POS and works their own branch there.
 */
class BranchAdminFormIdsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mine;

    private Branch $other;

    private User $admin;

    private Employee $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }
        $this->mine = Branch::factory()->create();
        $this->other = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => $this->mine->id]);
        $this->admin->assignRole('admin');
        $this->theirs = Employee::factory()->create(['branch_id' => $this->other->id, 'daily_rate' => 500, 'is_active' => true]);
    }

    private function recordAt(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'work_date' => now()->toDateString(),
            'clock_in_at' => now()->subHours(2), 'clock_out_at' => null,
        ]);
    }

    // ── Attendance ───────────────────────────────────────────────────────

    public function test_an_admin_cannot_enter_attendance_for_another_branchs_employee(): void
    {
        $this->actingAs($this->admin)->post('/attendance/manual-entry', [
            'employee_id' => $this->theirs->id, 'work_date' => now()->toDateString(), 'clock_in_time' => '09:00',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_an_admin_cannot_change_another_branchs_attendance_times(): void
    {
        $record = $this->recordAt($this->theirs);

        $this->actingAs($this->admin)->post('/attendance/update-times', ['record_id' => $record->id, 'clock_in_time' => '06:00'])
            ->assertForbidden();

        $this->assertNotSame('06:00', $record->fresh()->clock_in_at?->format('H:i'));
    }

    public function test_an_admin_can_still_correct_their_own_branchs_attendance(): void
    {
        $record = $this->recordAt(Employee::factory()->create(['branch_id' => $this->mine->id]));

        $this->actingAs($this->admin)->post('/attendance/update-times', ['record_id' => $record->id, 'clock_in_time' => '08:30'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('08:30', $record->fresh()->clock_in_at->format('H:i'));
    }

    // ── Payroll ──────────────────────────────────────────────────────────

    public function test_an_admin_cannot_generate_payroll_for_another_branchs_employee(): void
    {
        $range = ['start_date' => now()->subDays(6)->toDateString(), 'end_date' => now()->toDateString()];

        $this->actingAs($this->admin)->post('/payroll/generate', ['employee_id' => $this->theirs->id] + $range)->assertForbidden();
        $this->actingAs($this->admin)->post('/payroll/bulk-generate', ['employee_ids' => [$this->theirs->id]] + $range)->assertForbidden();

        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_an_admin_cannot_finalize_another_branchs_payroll(): void
    {
        $period = PayrollPeriod::create(['branch_id' => $this->other->id, 'start_date' => now()->subDays(6)->toDateString(), 'end_date' => now()->toDateString(), 'cutoff_label' => 'x', 'status' => 'draft']);
        $entry = PayrollEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $this->theirs->id, 'gross_pay' => 500, 'net_pay' => 500, 'status' => 'draft']);

        $this->actingAs($this->admin)->post('/payroll/finalize', ['payroll_entry_id' => $entry->id])->assertForbidden();

        $this->assertSame('draft', $entry->fresh()->status);
    }

    // ── GCash statement review ───────────────────────────────────────────

    public function test_an_admin_cannot_review_another_branchs_gcash_entry(): void
    {
        $expense = Expense::factory()->gcash()->create(['branch_id' => $this->other->id]);

        $this->actingAs($this->admin)->put("/gcash-report/entries/expense/{$expense->id}/status", ['status' => GcashEntryStatus::ACCEPTED])
            ->assertForbidden();

        $this->assertDatabaseCount('gcash_entry_statuses', 0);
    }

    // ── The iPad POS ─────────────────────────────────────────────────────

    public function test_an_admin_works_their_own_branch_on_the_ipad(): void
    {
        $item = MenuItem::factory()->create(['branch_id' => $this->mine->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/orders', ['items' => [['menu_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();
        $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->getJson('/api/v1/day-close/preview')->assertOk()->assertJsonPath('branch.id', $this->mine->id);

        $this->assertDatabaseHas('sales', ['branch_id' => $this->mine->id]);
    }

    public function test_an_admin_cannot_reach_another_branch_on_the_ipad(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/day-close/preview?branch_id='.$this->other->id)->assertForbidden();
        $this->postJson('/api/v1/attendance/clock-in', ['employee_id' => $this->theirs->id, 'work_date' => now()->toDateString()])->assertForbidden();
    }
}
