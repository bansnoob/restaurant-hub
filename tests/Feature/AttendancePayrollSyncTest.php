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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Attendance edits and the draft payroll reports derived from them.
 *
 * Two rules hold here, and they are the whole point of this file:
 *
 *   1. Editing attendance may REFRESH a payroll report the owner generated. It may
 *      never CREATE one. Overlapping periods are legal — the unique key is
 *      (branch_id, start_date, end_date), so a weekly period nests happily inside a
 *      semi-monthly one — and updateOrCreate in that loop invented a payable row in
 *      every period the owner had not asked about, covering days already paid.
 *
 *   2. Every attendance edit resyncs, not just deletion. Deleting re-derived the
 *      draft and editing times did not, so the payroll list and the payroll detail
 *      page showed different money for the same entry depending on which button the
 *      manager happened to use.
 */
class AttendancePayrollSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private Employee $rema;

    private Employee $erica;

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

        $this->rema = Employee::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => 420]);
        $this->erica = Employee::factory()->create(['branch_id' => $this->branch->id, 'daily_rate' => 370]);
    }

    private function period(string $start, string $end): PayrollPeriod
    {
        return PayrollPeriod::create([
            'branch_id' => $this->branch->id,
            'start_date' => $start,
            'end_date' => $end,
            'cutoff_label' => $start.' – '.$end,
            'status' => 'draft',
        ]);
    }

    private function entry(PayrollPeriod $period, Employee $employee, string $status = 'draft', float $net = 1000): PayrollEntry
    {
        return PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'regular_hours' => 40,
            'overtime_hours' => 0,
            'hourly_rate' => 0,
            'daily_rate' => $employee->daily_rate,
            'gross_pay' => $net,
            'deductions' => 0,
            'net_pay' => $net,
            'status' => $status,
        ]);
    }

    private function record(Employee $employee, string $date, string $in = '09:00:00', ?string $out = '17:00:00'): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $date,
            'clock_in_at' => $date.' '.$in,
            'clock_out_at' => $out ? $date.' '.$out : null,
            'status' => 'present',
            'captured_by_user_id' => $this->owner->id,
        ]);
    }

    // ---- rule 1: never invent a payroll report ------------------------------

    public function test_deleting_attendance_does_not_create_an_entry_in_an_overlapping_period(): void
    {
        $weekly = $this->period('2026-03-02', '2026-03-08');
        $semiMonthly = $this->period('2026-03-01', '2026-03-15');

        $this->entry($weekly, $this->rema);
        $record = $this->record($this->rema, '2026-03-05');

        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->delete(route('attendance.destroy', $record))
            ->assertRedirect(route('attendance.index'));

        $this->assertDatabaseMissing('payroll_entries', [
            'payroll_period_id' => $semiMonthly->id,
            'employee_id' => $this->rema->id,
        ]);
    }

    public function test_deleting_attendance_does_not_fabricate_a_report_for_days_already_paid(): void
    {
        $weekOne = $this->period('2026-03-02', '2026-03-08');
        $weekTwo = $this->period('2026-03-09', '2026-03-15');
        $semiMonthly = $this->period('2026-03-01', '2026-03-15');

        $this->entry($weekOne, $this->erica, 'paid', 2400);
        $this->entry($weekTwo, $this->erica, 'paid', 2410);
        $record = $this->record($this->erica, '2026-03-15');

        $this->actingAs($this->owner)->delete(route('attendance.destroy', $record));

        $this->assertSame(0, PayrollEntry::where('payroll_period_id', $semiMonthly->id)->count());
        $this->assertSame(2, PayrollEntry::where('employee_id', $this->erica->id)->count());
    }

    public function test_deleting_attendance_still_refreshes_the_report_that_does_exist(): void
    {
        $weekly = $this->period('2026-03-02', '2026-03-08');
        $entry = $this->entry($weekly, $this->rema, 'draft', 9999);

        $this->record($this->rema, '2026-03-03');
        $this->record($this->rema, '2026-03-04');
        $doomed = $this->record($this->rema, '2026-03-05');

        $this->actingAs($this->owner)->delete(route('attendance.destroy', $doomed));

        // Two present days left at 420/day, no late hits.
        $this->assertEquals(840.0, (float) $entry->fresh()->gross_pay);
        $this->assertEquals(840.0, (float) $entry->fresh()->net_pay);
    }

    public function test_a_paid_entry_is_never_rewritten_by_an_attendance_edit(): void
    {
        $weekly = $this->period('2026-03-02', '2026-03-08');
        $entry = $this->entry($weekly, $this->rema, 'paid', 2270);
        $record = $this->record($this->rema, '2026-03-05');

        $this->actingAs($this->owner)->delete(route('attendance.destroy', $record));

        $this->assertEquals(2270.0, (float) $entry->fresh()->net_pay);
        $this->assertSame('paid', $entry->fresh()->status);
    }

    // ---- rule 2: every edit resyncs -----------------------------------------

    public function test_correcting_a_clock_in_time_resyncs_the_draft_entry(): void
    {
        $weekly = $this->period('2026-03-02', '2026-03-08');
        $entry = $this->entry($weekly, $this->rema);

        // Clocked in at 14:00 — a third hit, 50% of 420 = 210 deducted.
        $record = $this->record($this->rema, '2026-03-03', '14:00:00');
        $this->actingAs($this->owner)->delete(route('attendance.destroy', $this->record($this->rema, '2026-03-04')));
        $this->assertEquals(210.0, (float) $entry->fresh()->deductions);

        // The manager corrects it to 09:00 — on time, nothing owed.
        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.update-times'), [
                'record_id' => $record->id,
                'clock_in_time' => '09:00',
            ])
            ->assertRedirect(route('attendance.index'));

        $entry->refresh();
        $this->assertEquals(0.0, (float) $entry->deductions);
        $this->assertEquals(420.0, (float) $entry->net_pay);
    }

    public function test_a_manual_entry_resyncs_the_draft_entry(): void
    {
        $weekly = $this->period('2026-03-02', '2026-03-08');
        $entry = $this->entry($weekly, $this->rema, 'draft', 0);

        $this->actingAs($this->owner)->post(route('attendance.manual-entry'), [
            'employee_id' => $this->rema->id,
            'work_date' => '2026-03-03',
            'clock_in_time' => '09:00',
            'clock_out_time' => '17:00',
        ]);

        $this->assertEquals(420.0, (float) $entry->fresh()->gross_pay);
    }

    public function test_a_manual_entry_does_not_invent_a_report_either(): void
    {
        $semiMonthly = $this->period('2026-03-01', '2026-03-15');

        $this->actingAs($this->owner)->post(route('attendance.manual-entry'), [
            'employee_id' => $this->rema->id,
            'work_date' => '2026-03-03',
            'clock_in_time' => '09:00',
            'clock_out_time' => '17:00',
        ]);

        $this->assertSame(0, PayrollEntry::where('payroll_period_id', $semiMonthly->id)->count());
    }

    // ---- manual entry must not destroy what it is not given -----------------

    public function test_manual_entry_keeps_an_existing_clock_out_when_the_field_is_left_blank(): void
    {
        $record = $this->record($this->rema, '2026-03-03', '08:00:00', '17:00:00');

        $this->actingAs($this->owner)->post(route('attendance.manual-entry'), [
            'employee_id' => $this->rema->id,
            'work_date' => '2026-03-03',
            'clock_in_time' => '08:30',
        ]);

        $record->refresh();
        $this->assertNotNull($record->clock_out_at, 'the existing clock-out was wiped');
        $this->assertSame('17:00:00', $record->clock_out_at->format('H:i:s'));
        $this->assertSame('08:30:00', $record->clock_in_at->format('H:i:s'));
    }

    public function test_manual_entry_rejects_a_clock_in_later_than_the_kept_clock_out(): void
    {
        $this->record($this->rema, '2026-03-03', '08:00:00', '12:00:00');

        $this->actingAs($this->owner)
            ->from(route('attendance.index'))
            ->post(route('attendance.manual-entry'), [
                'employee_id' => $this->rema->id,
                'work_date' => '2026-03-03',
                'clock_in_time' => '14:00',
            ])
            ->assertSessionHasErrors('clock_out_time');
    }

    public function test_manual_entry_still_sets_a_clock_out_when_one_is_given(): void
    {
        $record = $this->record($this->rema, '2026-03-03', '08:00:00', '17:00:00');

        $this->actingAs($this->owner)->post(route('attendance.manual-entry'), [
            'employee_id' => $this->rema->id,
            'work_date' => '2026-03-03',
            'clock_in_time' => '08:00',
            'clock_out_time' => '18:30',
        ]);

        $this->assertSame('18:30:00', $record->fresh()->clock_out_at->format('H:i:s'));
    }
}
