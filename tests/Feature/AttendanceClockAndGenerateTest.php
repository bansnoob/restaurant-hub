<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Three ways the Attendance and Payroll pages told the owner something untrue.
 *
 * Clock In stamps now(). The page has a date picker, so on any past date every
 * employee shows under "Not In Yet" and the obvious thing to do is press Clock In —
 * which writes today's wall-clock time against that old work_date. The row is then
 * months late against its own rule, and payroll charges the third deduction tier.
 *
 * Bulk Generate ran each employee in its own transaction and let the first
 * already-finalized one abort the loop, so part of the batch was committed while the
 * page reported only an error.
 *
 * The Attendance branch filter narrowed the tiles and the roster but not the Period
 * Summary, so the two halves of one page disagreed about who works where.
 */
class AttendanceClockAndGenerateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        PayrollRule::create([
            'branch_id' => $this->branch->id,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '09:00:00',
            'first_deduction_time' => '09:15:00',
            'first_deduction_amount' => 40,
            'second_deduction_time' => '09:30:00',
            'second_deduction_amount' => 100,
            'third_deduction_time' => '10:00:00',
            'third_deduction_percent' => 50,
        ]);

        $this->employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'daily_rate' => 420,
        ]);
    }

    // ---- Clock In may not back-date ----------------------------------------

    public function test_clocking_in_on_a_past_date_is_refused(): void
    {
        $past = now()->subDays(30)->toDateString();

        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.clock-in'), [
                'employee_id' => $this->employee->id,
                'work_date' => $past,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_the_refusal_points_at_manual_entry(): void
    {
        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.clock-in'), [
                'employee_id' => $this->employee->id,
                'work_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertStringContainsString('Manual Entry', session('error'));
    }

    public function test_clocking_in_today_still_works(): void
    {
        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.clock-in'), [
                'employee_id' => $this->employee->id,
                'work_date' => now()->toDateString(),
            ])
            ->assertSessionHas('success');

        $record = AttendanceRecord::sole();
        $this->assertNotNull($record->clock_in_at);
        $this->assertSame(now()->toDateString(), $record->clock_in_at->toDateString());
    }

    public function test_clocking_out_a_stale_record_is_refused(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->subDays(5)->toDateString(),
            'clock_in_at' => now()->subDays(5)->setTime(9, 0),
            'status' => 'present',
            'captured_by_user_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.clock-out'), ['record_id' => $record->id])
            ->assertSessionHas('error');

        $this->assertNull($record->fresh()->clock_out_at);
    }

    public function test_clocking_out_a_night_shift_still_works(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->subDay()->toDateString(),
            'clock_in_at' => now()->subHours(6),
            'status' => 'present',
            'captured_by_user_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.clock-out'), ['record_id' => $record->id])
            ->assertSessionHas('success');

        $this->assertNotNull($record->fresh()->clock_out_at);
    }

    // ---- Bulk Generate reports what it actually did --------------------------

    public function test_bulk_generate_skips_a_finalized_employee_and_still_does_the_rest(): void
    {
        $others = Employee::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'daily_rate' => 400,
        ]);

        $period = PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
            'cutoff_label' => 'Mar 1 – Mar 15',
            'status' => 'draft',
        ]);
        PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'regular_hours' => 0, 'overtime_hours' => 0, 'hourly_rate' => 0,
            'gross_pay' => 100, 'deductions' => 0, 'net_pay' => 100,
            'status' => 'paid',
        ]);

        // The finalized employee is first, so an abort would skip both of the others.
        $ids = [$this->employee->id, $others[0]->id, $others[1]->id];

        $this->actingAs($this->owner)
            ->from(route('payroll.index'))
            ->post(route('payroll.bulk-generate'), [
                'employee_ids' => $ids,
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-15',
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, PayrollEntry::where('payroll_period_id', $period->id)
            ->whereIn('employee_id', [$others[0]->id, $others[1]->id])->count());
    }

    public function test_bulk_generate_names_who_it_skipped(): void
    {
        $other = Employee::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => 400]);

        $period = PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
            'cutoff_label' => 'Mar 1 – Mar 15',
            'status' => 'draft',
        ]);
        PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'regular_hours' => 0, 'overtime_hours' => 0, 'hourly_rate' => 0,
            'gross_pay' => 100, 'deductions' => 0, 'net_pay' => 100,
            'status' => 'paid',
        ]);

        $this->actingAs($this->owner)->post(route('payroll.bulk-generate'), [
            'employee_ids' => [$this->employee->id, $other->id],
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
        ]);

        $message = session('success');
        $this->assertStringContainsString('Generated 1 payroll report', $message);
        $this->assertStringContainsString($this->employee->last_name, $message);
    }

    public function test_a_paid_entry_is_not_overwritten_by_bulk_generate(): void
    {
        $period = PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
            'cutoff_label' => 'Mar 1 – Mar 15',
            'status' => 'draft',
        ]);
        $paid = PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'regular_hours' => 0, 'overtime_hours' => 0, 'hourly_rate' => 0,
            'gross_pay' => 100, 'deductions' => 0, 'net_pay' => 100,
            'status' => 'paid',
        ]);

        $this->actingAs($this->owner)->post(route('payroll.bulk-generate'), [
            'employee_ids' => [$this->employee->id],
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
        ]);

        $this->assertEquals(100.0, (float) $paid->fresh()->net_pay);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    // ---- the Period Summary obeys the branch filter --------------------------

    public function test_the_period_summary_respects_the_branch_filter(): void
    {
        $otherBranch = Branch::factory()->create();
        $elsewhere = Employee::factory()->create(['branch_id' => $otherBranch->id, 'daily_rate' => 500]);

        $response = $this->actingAs($this->owner)
            ->get(route('attendance.index', ['branch_id' => $this->branch->id]))
            ->assertOk();

        $ids = collect($response->viewData('summaries'))->pluck('employee_id')->all();

        $this->assertContains($this->employee->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids, 'the summary is showing another branch');
    }

    public function test_the_period_summary_shows_everyone_with_no_branch_filter(): void
    {
        $otherBranch = Branch::factory()->create();
        $elsewhere = Employee::factory()->create(['branch_id' => $otherBranch->id, 'daily_rate' => 500]);

        $response = $this->actingAs($this->owner)->get(route('attendance.index'))->assertOk();
        $ids = collect($response->viewData('summaries'))->pluck('employee_id')->all();

        $this->assertContains($this->employee->id, $ids);
        $this->assertContains($elsewhere->id, $ids);
    }
}
