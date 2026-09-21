<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

class AttendanceService
{
    /**
     * A clock-out is refused once the shift has run this long. Past it, now() is no
     * longer a plausible time for the person to have left, and writing it produces a
     * multi-day span that reads as enormous worked hours.
     */
    private const MAX_SHIFT_HOURS = 20;

    public function clockIn(Employee $employee, string $workDate, User $capturedBy, ?string $notes = null): AttendanceRecord
    {
        // Clock In stamps now(). The Attendance page has a date picker, so on any past
        // date every employee shows under "Not In Yet" and Clock In is the obvious
        // control — but it would write today's wall-clock time against that old date.
        // The row is then months late against its own rule and payroll charges the
        // third deduction tier on a day the person may well have been on time for.
        if (! Carbon::parse($workDate)->isSameDay(Carbon::today())) {
            throw new AttendanceException(
                'Clock In records the time right now, so it can only be used for today. '
                .'Use Manual Entry to record the actual times for '.$workDate.'.'
            );
        }

        $record = AttendanceRecord::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'work_date' => $workDate,
            ],
            [
                'branch_id' => $employee->branch_id,
                'captured_by_user_id' => $capturedBy->id,
            ]
        );

        if ($record->clock_in_at) {
            throw new AttendanceException('This employee is already timed in for the selected date.');
        }

        $record->update([
            'clock_in_at' => now(),
            'status' => 'present',
            'notes' => $notes,
            'captured_by_user_id' => $capturedBy->id,
        ]);

        return $record;
    }

    public function clockOut(AttendanceRecord $record): AttendanceRecord
    {
        if (! $record->clock_in_at) {
            throw new AttendanceException('Cannot time out an employee without a clock-in record.');
        }

        if ($record->clock_out_at) {
            throw new AttendanceException('This attendance entry is already timed out.');
        }

        // Same reasoning as clockIn: a record left open for days cannot be closed with
        // now(). A night shift crossing midnight is well inside the limit.
        if (Carbon::parse($record->clock_in_at)->diffInHours(Carbon::now()) > self::MAX_SHIFT_HOURS) {
            throw new AttendanceException(
                'This entry was timed in more than '.self::MAX_SHIFT_HOURS.' hours ago, so the time now is not a '
                .'plausible time out. Use Update Times to record when the shift actually ended.'
            );
        }

        $record->update(['clock_out_at' => now()]);

        return $record;
    }
}
