<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;

class AttendanceService
{
    public function clockIn(Employee $employee, string $workDate, User $capturedBy, ?string $notes = null): AttendanceRecord
    {
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

        $record->update(['clock_out_at' => now()]);

        return $record;
    }
}
