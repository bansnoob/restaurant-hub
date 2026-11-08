<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A deduction typed in by hand, with a reason: a cash advance, a broken plate,
 * a uniform. Attendance cannot know about these, so the report's figures are
 * attendance deductions plus every manual one.
 *
 * The trap is regeneration. A draft is rebuilt from attendance on every Generate
 * and on every attendance edit, and that rebuild writes `deductions` and `net_pay`
 * from scratch. A manual deduction that lived only in those columns would vanish
 * the next time someone corrected a clock-in, and the employee would be overpaid.
 */
class PayrollManualDeductionTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-11-02';

    private const END = '2026-11-08';

    private User $owner;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('admin');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create(['name' => 'Ramen Naijiro']);
        $this->owner = User::factory()->create(['name' => 'Bans', 'branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        PayrollRule::create([
            'branch_id' => $this->branch->id,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '09:00:00',
            'first_deduction_time' => '09:15:00',
            'first_deduction_amount' => 40,
            'first_deduction_type' => 'amount',
            'second_deduction_time' => '09:30:00',
            'second_deduction_amount' => 80,
            'second_deduction_type' => 'amount',
            'third_deduction_time' => '10:00:00',
            'third_deduction_percent' => 50,
            'third_deduction_type' => 'percent',
        ]);

        $this->employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kein',
            'last_name' => 'Dela Cruz',
            'daily_rate' => 500,
            'is_active' => true,
        ]);
    }

    private function worked(string $date, string $in = '08:55'): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $date,
            'clock_in_at' => $date.' '.$in.':00',
            'clock_out_at' => $date.' 17:00:00',
            'status' => 'present',
        ]);
    }

    /** Three days, one of them 1st-hit late: gross 1,500, attendance deductions 40, net 1,460. */
    private function generatedReport(): PayrollEntry
    {
        $this->worked('2026-11-03');
        $this->worked('2026-11-04', '09:20');
        $this->worked('2026-11-05');

        $this->actingAs($this->owner)->post('/payroll/generate', [
            'employee_id' => $this->employee->id,
            'start_date' => self::START,
            'end_date' => self::END,
        ])->assertSessionHasNoErrors();

        $entry = PayrollEntry::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals(1500, (float) $entry->gross_pay);
        $this->assertEquals(40, (float) $entry->deductions);
        $this->assertEquals(1460, (float) $entry->net_pay);

        return $entry;
    }

    private function deduct(PayrollEntry $entry, array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('payroll.show', $entry->payroll_period_id))
            ->post(route('payroll.deductions.store', $entry), $body);
    }

    public function test_a_deduction_with_a_reason_comes_off_the_net_pay(): void
    {
        $entry = $this->generatedReport();

        $this->deduct($entry, ['amount' => '500', 'reason' => 'Cash advance Nov 4'])
            ->assertRedirect(route('payroll.show', $entry->payroll_period_id))
            ->assertSessionHas('success');

        $entry->refresh();
        $this->assertEquals(540, (float) $entry->deductions);
        $this->assertEquals(960, (float) $entry->net_pay);
        $this->assertEquals(1500, (float) $entry->gross_pay);
        $this->assertDatabaseHas('payroll_deductions', [
            'payroll_entry_id' => $entry->id,
            'amount' => 500,
            'reason' => 'Cash advance Nov 4',
            'recorded_by_user_id' => $this->owner->id,
        ]);
    }

    public function test_several_deductions_add_up(): void
    {
        $entry = $this->generatedReport();

        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);
        $this->deduct($entry, ['amount' => '150.50', 'reason' => 'Broken bowl']);

        $entry->refresh();
        $this->assertEquals(490.50, (float) $entry->deductions);
        $this->assertEquals(1009.50, (float) $entry->net_pay);
        $this->assertSame(2, $entry->manualDeductions()->count());
    }

    public function test_a_reason_is_required(): void
    {
        $entry = $this->generatedReport();

        $this->deduct($entry, ['amount' => '100', 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->deduct($entry, ['amount' => '100', 'reason' => str_repeat('x', 201)])->assertSessionHasErrors('reason');

        $this->assertSame(0, PayrollDeduction::count());
        $this->assertEquals(1460, (float) $entry->fresh()->net_pay);
    }

    public function test_an_array_shaped_reason_is_refused_without_breaking_the_page(): void
    {
        $entry = $this->generatedReport();

        $this->deduct($entry, ['amount' => '100', 'reason' => ['x']])->assertSessionHasErrors('reason');

        // The refused input is flashed back to the drawer on the next page load.
        $this->actingAs($this->owner)->get(route('payroll.show', $entry->payroll_period_id))->assertOk();
    }

    public function test_the_amount_must_be_positive_and_at_most_the_net_pay(): void
    {
        $entry = $this->generatedReport();

        foreach (['0', '-50', 'abc', '10.555', ''] as $bad) {
            $this->deduct($entry, ['amount' => $bad, 'reason' => 'Advance'])->assertSessionHasErrors('amount');
        }
        $this->deduct($entry, ['amount' => '1460.01', 'reason' => 'Advance'])->assertSessionHasErrors('amount');

        $this->assertSame(0, PayrollDeduction::count());

        // The whole net pay is allowed: it leaves ₱0 to pay, never a negative wage.
        $this->deduct($entry, ['amount' => '1460', 'reason' => 'Advance'])->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) $entry->fresh()->net_pay);
    }

    public function test_add_deduction_is_hidden_once_nothing_is_left_to_pay(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '1460', 'reason' => 'Cash advance']);

        $this->actingAs($this->owner)
            ->get(route('payroll.show', $entry->payroll_period_id))
            ->assertOk()
            ->assertSee('Cash advance')
            ->assertDontSee('Add deduction');
    }

    public function test_removing_a_deduction_puts_it_back(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);
        $this->deduct($entry, ['amount' => '100', 'reason' => 'Typo']);
        $typo = PayrollDeduction::where('reason', 'Typo')->firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('payroll.show', $entry->payroll_period_id))
            ->delete(route('payroll.deductions.destroy', $typo))
            ->assertRedirect(route('payroll.show', $entry->payroll_period_id))
            ->assertSessionHas('success');

        $entry->refresh();
        $this->assertNull(PayrollDeduction::find($typo->id));
        $this->assertEquals(340, (float) $entry->deductions);
        $this->assertEquals(1160, (float) $entry->net_pay);
    }

    public function test_a_finalized_report_cannot_be_changed(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);
        $deduction = PayrollDeduction::firstOrFail();
        $entry->update(['status' => 'paid']);

        $this->deduct($entry, ['amount' => '100', 'reason' => 'Late addition'])->assertSessionHas('error');
        $this->actingAs($this->owner)->delete(route('payroll.deductions.destroy', $deduction))->assertSessionHas('error');

        $entry->refresh();
        $this->assertSame(1, PayrollDeduction::count());
        $this->assertEquals(1160, (float) $entry->net_pay);
    }

    public function test_regenerating_the_report_keeps_the_manual_deductions(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);

        // A fourth day turns up and the owner generates again.
        $this->worked('2026-11-06');
        $this->actingAs($this->owner)->post('/payroll/generate', [
            'employee_id' => $this->employee->id,
            'start_date' => self::START,
            'end_date' => self::END,
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertEquals(2000, (float) $entry->gross_pay);
        $this->assertEquals(340, (float) $entry->deductions);
        $this->assertEquals(1660, (float) $entry->net_pay);
        $this->assertSame(1, $entry->manualDeductions()->count());
    }

    public function test_an_attendance_correction_keeps_the_manual_deductions(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);

        // The late day was a mistake: they were on time.
        $late = AttendanceRecord::whereDate('work_date', '2026-11-04')->firstOrFail();
        $this->actingAs($this->owner)->post('/attendance/update-times', [
            'record_id' => $late->id,
            'clock_in_time' => '08:50',
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertEquals(300, (float) $entry->deductions);
        $this->assertEquals(1200, (float) $entry->net_pay);
    }

    public function test_deleting_attendance_keeps_the_manual_deductions(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);

        $day = AttendanceRecord::whereDate('work_date', '2026-11-05')->firstOrFail();
        $this->actingAs($this->owner)->delete(route('attendance.destroy', $day))->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertEquals(1000, (float) $entry->gross_pay);
        $this->assertEquals(340, (float) $entry->deductions);
        $this->assertEquals(660, (float) $entry->net_pay);
    }

    public function test_finalizing_posts_the_wage_after_the_manual_deductions(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);

        $this->actingAs($this->owner)->post(route('payroll.finalize'), [
            'payroll_entry_id' => $entry->id,
            'payment_method' => 'cash',
            'paid_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1160, (float) SpecialExpense::where('payroll_entry_id', $entry->id)->value('amount'));
    }

    public function test_the_details_page_lists_each_deduction_and_its_reason(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance Nov 4']);

        $this->actingAs($this->owner)
            ->get(route('payroll.show', $entry->payroll_period_id))
            ->assertOk()
            ->assertSeeInOrder(['rh-pay-deduct', 'Late arrivals', '₱40.00', 'Cash advance Nov 4', 'Added by Bans', '₱300.00', 'Total', '₱340.00'])
            ->assertSee('Add deduction')
            ->assertSee(route('payroll.deductions.destroy', PayrollDeduction::firstOrFail()), false)
            // A double-click must not take the money twice: the button disables on submit.
            ->assertSee('@submit="submitting = true"', false)
            ->assertSee(':disabled="submitting"', false);
    }

    public function test_a_finalized_report_shows_its_deductions_without_the_controls(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance Nov 4']);
        $entry->update(['status' => 'paid']);

        $this->actingAs($this->owner)
            ->get(route('payroll.show', $entry->payroll_period_id))
            ->assertOk()
            ->assertSee('Cash advance Nov 4')
            ->assertDontSee('Add deduction')
            ->assertDontSee(route('payroll.deductions.destroy', PayrollDeduction::firstOrFail()), false);
    }

    public function test_a_branch_admin_manages_deductions_at_their_own_branch_only(): void
    {
        $entry = $this->generatedReport();
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->assignRole('admin');

        $this->deduct($entry, ['amount' => '100', 'reason' => 'Uniform'], $admin)->assertSessionHasNoErrors();
        $this->assertSame(1, PayrollDeduction::count());

        $otherBranch = Branch::factory()->create();
        $otherAdmin = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherAdmin->assignRole('admin');

        $this->deduct($entry, ['amount' => '100', 'reason' => 'Hijack'], $otherAdmin)->assertNotFound();
        $this->actingAs($otherAdmin)->delete(route('payroll.deductions.destroy', PayrollDeduction::firstOrFail()))->assertNotFound();
        $this->assertSame(1, PayrollDeduction::count());
        $this->assertEquals(1360, (float) $entry->fresh()->net_pay);
    }

    public function test_a_cashier_cannot_add_a_deduction(): void
    {
        $entry = $this->generatedReport();
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->deduct($entry, ['amount' => '100', 'reason' => 'Advance'], $cashier);

        $this->assertSame(0, PayrollDeduction::count());
    }

    /**
     * A ₱1,460 advance taken off a ₱1,460 net is fine, until an attendance day is
     * deleted and gross drops to ₱1,000. Net stops at ₱0, so ₱500 of the advance is
     * not being taken while every total still says it was. Finalizing that would
     * lose the ₱500 without a trace.
     */
    private function overDeductedReport(): PayrollEntry
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '1460', 'reason' => 'Cash advance'])->assertSessionHasNoErrors();

        $day = AttendanceRecord::whereDate('work_date', '2026-11-05')->firstOrFail();
        $this->actingAs($this->owner)->delete(route('attendance.destroy', $day));

        $entry->refresh();
        $this->assertEquals(1000, (float) $entry->gross_pay);
        $this->assertEquals(1500, (float) $entry->deductions);
        $this->assertEquals(0, (float) $entry->net_pay);

        return $entry;
    }

    public function test_deductions_larger_than_the_gross_pay_are_flagged_on_the_report(): void
    {
        $entry = $this->overDeductedReport();

        $this->actingAs($this->owner)
            ->get(route('payroll.show', $entry->payroll_period_id))
            ->assertOk()
            ->assertSee('₱500.00 of these deductions is more than the pay');
    }

    public function test_a_report_whose_deductions_exceed_the_gross_pay_cannot_be_finalized(): void
    {
        $entry = $this->overDeductedReport();

        $this->actingAs($this->owner)->post(route('payroll.finalize'), [
            'payroll_entry_id' => $entry->id,
            'payment_method' => 'cash',
            'paid_date' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertFalse(SpecialExpense::where('payroll_entry_id', $entry->id)->exists());

        // Brought back within the gross pay, it finalizes.
        $this->actingAs($this->owner)->delete(route('payroll.deductions.destroy', PayrollDeduction::firstOrFail()));
        $this->deduct($entry, ['amount' => '960', 'reason' => 'Cash advance (₱500 carried to next payroll)']);
        $this->actingAs($this->owner)->post(route('payroll.finalize'), [
            'payroll_entry_id' => $entry->id,
            'payment_method' => 'cash',
            'paid_date' => now()->toDateString(),
        ])->assertSessionHas('success');
        $this->assertEquals(0, (float) SpecialExpense::where('payroll_entry_id', $entry->id)->value('amount'));
    }

    public function test_deleting_a_report_warns_that_its_deductions_go_with_it(): void
    {
        $entry = $this->generatedReport();
        $this->deduct($entry, ['amount' => '300', 'reason' => 'Cash advance']);

        $this->actingAs($this->owner)
            ->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('Its manual deductions (₱300.00) are deleted with it.', false);
    }

    public function test_a_stale_draft_hands_its_deductions_to_the_report_that_replaces_it(): void
    {
        // A draft at the old branch, with a cash advance on it. The employee's days turn
        // out to belong to the new branch, so Generate removes the old draft. The advance
        // must follow the days, not disappear with the draft.
        $newBranch = Branch::factory()->create(['name' => 'Samgyupsal']);
        $this->employee->update(['branch_id' => $newBranch->id]);
        AttendanceRecord::factory()->create([
            'employee_id' => $this->employee->id, 'branch_id' => $newBranch->id, 'work_date' => '2026-11-03',
            'clock_in_at' => '2026-11-03 08:55:00', 'clock_out_at' => '2026-11-03 17:00:00', 'status' => 'present',
        ]);
        $period = PayrollPeriod::create([
            'branch_id' => $this->branch->id, 'start_date' => self::START, 'end_date' => self::END,
            'cutoff_label' => 'Nov 2 – Nov 8', 'status' => 'draft',
        ]);
        $stale = PayrollEntry::create([
            'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'gross_pay' => 1000, 'deductions' => 200, 'net_pay' => 800, 'status' => 'draft',
        ]);
        $stale->manualDeductions()->create(['amount' => 200, 'reason' => 'Cash advance']);

        $this->actingAs($this->owner)->post('/payroll/generate', [
            'employee_id' => $this->employee->id,
            'start_date' => self::START,
            'end_date' => self::END,
        ])->assertSessionHasNoErrors();

        $this->assertNull(PayrollEntry::find($stale->id));
        $replacement = PayrollEntry::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame(1, $replacement->manualDeductions()->count());
        $this->assertEquals(200, (float) $replacement->deductions);
        $this->assertEquals(300, (float) $replacement->net_pay);
    }
}
