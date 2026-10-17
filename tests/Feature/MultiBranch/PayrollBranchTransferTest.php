<?php

namespace Tests\Feature\MultiBranch;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An employee who moves branch mid-period is paid by each branch for the days
 * they worked there, at that branch's rules. Generation used to put every day
 * into the employee's CURRENT branch's period, at that branch's rules, and an
 * attendance edit only ever refreshed the current branch's draft.
 *
 * Every attendance record carries the branch it was worked at, so that is what
 * decides whose period a day belongs to.
 */
class PayrollBranchTransferTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-10-01';

    private const END = '2026-10-07';

    private const DAILY_RATE = 500;

    private User $owner;

    private Branch $old;

    private Branch $new;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->old = Branch::factory()->create(['name' => 'Old Branch']);
        $this->new = Branch::factory()->create(['name' => 'New Branch']);

        // Moved to the new branch after working the first two days at the old one.
        $this->employee = Employee::factory()->create([
            'branch_id' => $this->new->id,
            'daily_rate' => self::DAILY_RATE,
            'is_active' => true,
        ]);
    }

    private function worked(Branch $branch, string $date, string $in = '08:55'): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $this->employee->id,
            'branch_id' => $branch->id,
            'work_date' => $date,
            'clock_in_at' => $date.' '.$in.':00',
            'clock_out_at' => $date.' 17:00:00',
            'status' => 'present',
        ]);
    }

    private function workTheSplitWeek(): void
    {
        $this->worked($this->old, '2026-10-01');
        $this->worked($this->old, '2026-10-02');
        $this->worked($this->new, '2026-10-03');
        $this->worked($this->new, '2026-10-04');
        $this->worked($this->new, '2026-10-05');
    }

    private function generate(): void
    {
        $this->actingAs($this->owner)->post('/payroll/generate', [
            'employee_id' => $this->employee->id,
            'start_date' => self::START,
            'end_date' => self::END,
        ])->assertSessionHasNoErrors();
    }

    private function entryAt(Branch $branch): ?PayrollEntry
    {
        return PayrollEntry::where('employee_id', $this->employee->id)
            ->whereHas('payrollPeriod', fn ($q) => $q->where('branch_id', $branch->id))
            ->first();
    }

    public function test_each_branch_pays_the_days_worked_there(): void
    {
        $this->workTheSplitWeek();

        $this->generate();

        $this->assertEquals(2 * self::DAILY_RATE, (float) $this->entryAt($this->old)->gross_pay);
        $this->assertEquals(3 * self::DAILY_RATE, (float) $this->entryAt($this->new)->gross_pay);
    }

    public function test_each_branch_charges_its_own_late_rules(): void
    {
        PayrollRule::create([
            'branch_id' => $this->old->id, 'standard_daily_hours' => 8,
            'required_clock_in_time' => '09:00:00',
            'first_deduction_time' => '09:15:00', 'first_deduction_type' => 'amount', 'first_deduction_amount' => 40,
            'second_deduction_time' => '09:30:00', 'second_deduction_type' => 'amount', 'second_deduction_amount' => 80,
            'third_deduction_time' => '10:00:00', 'third_deduction_type' => 'percent', 'third_deduction_percent' => 50,
        ]);
        PayrollRule::create([
            'branch_id' => $this->new->id, 'standard_daily_hours' => 8,
            'required_clock_in_time' => '10:00:00',
            'first_deduction_time' => '10:15:00', 'first_deduction_type' => 'amount', 'first_deduction_amount' => 40,
            'second_deduction_time' => '10:30:00', 'second_deduction_type' => 'amount', 'second_deduction_amount' => 80,
            'third_deduction_time' => '11:00:00', 'third_deduction_type' => 'percent', 'third_deduction_percent' => 50,
        ]);
        // 09:20 is late at the old branch (09:15 tier) and early at the new one.
        $this->worked($this->old, '2026-10-01', '09:20');
        $this->worked($this->new, '2026-10-03', '09:20');

        $this->generate();

        $this->assertEquals(40, (float) $this->entryAt($this->old)->deductions);
        $this->assertEquals(0, (float) $this->entryAt($this->new)->deductions);
    }

    public function test_regenerating_a_report_made_the_old_way_does_not_double_pay(): void
    {
        $this->workTheSplitWeek();
        // Generated before this change: all five days in the current branch's period.
        $period = PayrollPeriod::create([
            'branch_id' => $this->new->id, 'start_date' => self::START, 'end_date' => self::END,
            'cutoff_label' => 'Oct 1 – Oct 7', 'status' => 'draft',
        ]);
        PayrollEntry::create([
            'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'gross_pay' => 5 * self::DAILY_RATE, 'net_pay' => 5 * self::DAILY_RATE, 'status' => 'draft',
        ]);

        $this->generate();

        $this->assertEquals(5 * self::DAILY_RATE, (float) PayrollEntry::where('employee_id', $this->employee->id)->sum('gross_pay'));
        $this->assertEquals(3 * self::DAILY_RATE, (float) $this->entryAt($this->new)->gross_pay);
    }

    public function test_a_stale_draft_at_a_branch_with_no_days_left_is_removed(): void
    {
        $this->worked($this->new, '2026-10-03');
        $period = PayrollPeriod::create([
            'branch_id' => $this->old->id, 'start_date' => self::START, 'end_date' => self::END,
            'cutoff_label' => 'Oct 1 – Oct 7', 'status' => 'draft',
        ]);
        PayrollEntry::create([
            'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'gross_pay' => 1000, 'net_pay' => 1000, 'status' => 'draft',
        ]);

        $this->generate();

        $this->assertNull($this->entryAt($this->old));
        $this->assertNull(PayrollPeriod::find($period->id), 'an emptied draft period was left behind');
        $this->assertEquals(self::DAILY_RATE, (float) $this->entryAt($this->new)->gross_pay);
    }

    public function test_a_paid_report_at_another_branch_is_never_touched(): void
    {
        $this->worked($this->new, '2026-10-03');
        $period = PayrollPeriod::create([
            'branch_id' => $this->old->id, 'start_date' => self::START, 'end_date' => self::END,
            'cutoff_label' => 'Oct 1 – Oct 7', 'status' => 'draft',
        ]);
        $paid = PayrollEntry::create([
            'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'gross_pay' => 1000, 'net_pay' => 1000, 'status' => 'paid',
        ]);

        $this->generate();

        $this->assertEquals(1000, (float) $paid->fresh()->gross_pay);
    }

    public function test_no_attendance_still_produces_a_report_at_the_current_branch(): void
    {
        $this->generate();

        $this->assertNotNull($this->entryAt($this->new));
        $this->assertNull($this->entryAt($this->old));
    }

    public function test_correcting_an_old_branch_day_refreshes_the_old_branchs_draft(): void
    {
        $this->workTheSplitWeek();
        $this->generate();
        $newBefore = (float) $this->entryAt($this->new)->net_pay;

        $oldDay = AttendanceRecord::where('branch_id', $this->old->id)->orderBy('work_date')->firstOrFail();
        $this->actingAs($this->owner)->post('/attendance/update-times', [
            'record_id' => $oldDay->id,
            'clock_in_time' => '10:30',
        ])->assertSessionHasNoErrors();

        // Third-tier lateness under the default rules: half a day's rate.
        $this->assertEquals(2 * self::DAILY_RATE - self::DAILY_RATE / 2, (float) $this->entryAt($this->old)->net_pay);
        $this->assertEquals($newBefore, (float) $this->entryAt($this->new)->net_pay);
    }

    public function test_a_manual_correction_keeps_the_day_at_the_branch_it_was_worked(): void
    {
        $oldDay = $this->worked($this->old, '2026-10-01');

        $this->actingAs($this->owner)->post('/attendance/manual-entry', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-01',
            'clock_in_time' => '08:50',
            'clock_out_time' => '17:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->old->id, (int) $oldDay->fresh()->branch_id);
    }

    public function test_the_details_page_breaks_down_only_that_branchs_days(): void
    {
        $this->workTheSplitWeek();
        $this->generate();
        $oldPeriod = PayrollPeriod::where('branch_id', $this->old->id)->firstOrFail();

        $summary = $this->actingAs($this->owner)
            ->get("/payroll/{$oldPeriod->id}/details?employee_id={$this->employee->id}")
            ->assertOk()
            ->viewData('summary');

        $this->assertSame(2, $summary['present_days']);
    }
}
