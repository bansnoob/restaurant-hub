<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Transactions that lock a payroll report retry when MySQL picks them as a deadlock
 * victim (PayrollEntry::WRITE_ATTEMPTS). A retry re-runs the closure after the
 * rollback, and a model bound from the URL still believes it was deleted on the
 * first attempt: `$model->delete()` then does nothing, the row the rollback restored
 * stays, and the page still says it was deleted.
 *
 * DatabaseMigrations, not RefreshDatabase: the latter wraps each test in a
 * transaction, which makes every transaction here a nested one, and Laravel only
 * retries at the outermost level.
 */
class PayrollDeadlockRetryTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $branch->id]);
        $this->owner->assignRole('owner');
        PayrollRule::create([
            'branch_id' => $branch->id, 'standard_daily_hours' => 8, 'required_clock_in_time' => '09:00:00',
            'first_deduction_time' => '09:15:00', 'first_deduction_amount' => 40,
            'second_deduction_time' => '09:30:00', 'second_deduction_amount' => 80,
            'third_deduction_time' => '10:00:00', 'third_deduction_percent' => 50,
        ]);
        $this->employee = Employee::factory()->create(['branch_id' => $branch->id, 'daily_rate' => 500, 'is_active' => true]);

        foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
            AttendanceRecord::factory()->create([
                'employee_id' => $this->employee->id, 'branch_id' => $branch->id, 'work_date' => $date,
                'clock_in_at' => $date.' 08:55:00', 'clock_out_at' => $date.' 17:00:00', 'status' => 'present',
            ]);
        }
        $this->actingAs($this->owner)->post('/payroll/generate', [
            'employee_id' => $this->employee->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-08',
        ])->assertSessionHasNoErrors();
    }

    /** Fail the first query after a `delete from <table>` once, as a deadlock would. */
    private function deadlockOnceAfterDeleting(string $table): void
    {
        $deleted = false;
        $thrown = false;
        DB::listen(function ($query) use ($table, &$deleted, &$thrown): void {
            if ($thrown) {
                return;
            }
            if (str_starts_with($query->sql, 'delete from "'.$table.'"')) {
                $deleted = true;

                return;
            }
            if ($deleted) {
                $thrown = true;
                throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
            }
        });
    }

    public function test_a_retried_deduction_removal_still_removes_it(): void
    {
        $entry = PayrollEntry::firstOrFail();
        $this->post(route('payroll.deductions.store', $entry), ['amount' => '300', 'reason' => 'Cash advance'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(1200, (float) $entry->fresh()->net_pay);

        $this->deadlockOnceAfterDeleting('payroll_deductions');
        $this->delete(route('payroll.deductions.destroy', PayrollDeduction::firstOrFail()))->assertSessionHas('success');

        $this->assertSame(0, PayrollDeduction::count());
        $this->assertEquals(1500, (float) $entry->fresh()->net_pay);
    }

    public function test_a_retried_attendance_delete_still_deletes_it(): void
    {
        $day = AttendanceRecord::whereDate('work_date', '2026-11-05')->firstOrFail();

        $this->deadlockOnceAfterDeleting('attendance_records');
        $this->delete(route('attendance.destroy', $day))->assertSessionHas('success');

        $this->assertNull(AttendanceRecord::find($day->id));
        $this->assertEquals(1000, (float) PayrollEntry::firstOrFail()->gross_pay);
    }
}
