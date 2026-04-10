<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceSummaryCalculator
{
    /**
     * Build attendance summary metrics for a single employee in a date range.
     */
    public function summarizeForEmployee(
        Employee $employee,
        Collection $records,
        string $dateFrom,
        string $dateTo,
        array $rules = []
    ): array {
        $standardDailyHours = max(0.5, (float) ($rules['standard_daily_hours'] ?? 8));
        $requiredClockInTime = (string) ($rules['required_clock_in_time'] ?? '09:00:00');
        $firstDeductionTime = (string) ($rules['first_deduction_time'] ?? '09:15:00');
        $firstDeductionAmount = max(0, (float) ($rules['first_deduction_amount'] ?? 0));
        $secondDeductionTime = (string) ($rules['second_deduction_time'] ?? '09:30:00');
        $secondDeductionAmount = max(0, (float) ($rules['second_deduction_amount'] ?? 0));
        $thirdDeductionTime = (string) ($rules['third_deduction_time'] ?? '10:00:00');
        $thirdDeductionPercent = max(0, min(100, (float) ($rules['third_deduction_percent'] ?? 0)));

        $daysWithLogs = 0;
        $presentDays = 0;
        $lateDays = 0;
        $absentDays = 0;
        $leaveDays = 0;
        $holidayDays = 0;

        $workedMinutes = 0;
        $lateMinutes = 0;
        $firstHitDays = 0;
        $secondHitDays = 0;
        $thirdHitDays = 0;

        foreach ($records as $record) {
            $status = (string) $record->status;
            if ($status === 'absent') {
                $absentDays++;
            } elseif ($status === 'leave') {
                $leaveDays++;
            } elseif ($status === 'holiday') {
                $holidayDays++;
            }

            if (! $record->clock_in_at || ! $record->clock_out_at) {
                continue;
            }

            $clockIn = Carbon::parse($record->clock_in_at);
            $clockOut = Carbon::parse($record->clock_out_at);

            if ($clockOut->lessThanOrEqualTo($clockIn)) {
                continue;
            }

            $daysWithLogs++;
            $presentDays++;

            $requiredAt = Carbon::parse($record->work_date.' '.$requiredClockInTime);
            $firstHitAt = Carbon::parse($record->work_date.' '.$firstDeductionTime);
            $secondHitAt = Carbon::parse($record->work_date.' '.$secondDeductionTime);
            $thirdHitAt = Carbon::parse($record->work_date.' '.$thirdDeductionTime);

            if ($clockIn->greaterThan($requiredAt)) {
                $lateDays++;
                $lateMinutes += $requiredAt->diffInMinutes($clockIn);
            }

            if ($clockIn->greaterThanOrEqualTo($thirdHitAt)) {
                $thirdHitDays++;
            } elseif ($clockIn->greaterThanOrEqualTo($secondHitAt)) {
                $secondHitDays++;
            } elseif ($clockIn->greaterThanOrEqualTo($firstHitAt)) {
                $firstHitDays++;
            }

            $breakMinutes = (int) ($record->break_minutes ?? 0);
            $grossWorked = $clockIn->diffInMinutes($clockOut);
            $netWorked = max(0, $grossWorked - $breakMinutes);

            $workedMinutes += $netWorked;
        }

        $dailyRate = (float) ($employee->daily_rate ?? 0);
        $hourlyRate = 0.0;
        if ($dailyRate > 0) {
            $hourlyRate = round($dailyRate / $standardDailyHours, 4);
        }

        $regularHours = round($presentDays * $standardDailyHours, 2);
        $overtimeHours = 0.0;
        $payableOvertimeHours = 0.0;
        $undertimeHours = 0.0;
        $roundedUndertimeHours = 0.0;
        $workedHours = round($workedMinutes / 60, 2);
        $lateHours = round($lateMinutes / 60, 2);

        $estimatedGross = $presentDays * $dailyRate;

        $lateDeduction = ($firstHitDays * $firstDeductionAmount)
            + ($secondHitDays * $secondDeductionAmount)
            + ($thirdHitDays * ($dailyRate * ($thirdDeductionPercent / 100)));
        $estimatedDeductions = $lateDeduction;
        $estimatedNet = max(0, $estimatedGross - $estimatedDeductions);

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => trim($employee->first_name.' '.$employee->last_name),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'days_with_logs' => $daysWithLogs,
            'present_days' => $presentDays,
            'late_days' => $lateDays,
            'absent_days' => $absentDays,
            'leave_days' => $leaveDays,
            'holiday_days' => $holidayDays,
            'worked_hours' => $workedHours,
            'regular_hours' => $regularHours,
            'overtime_hours' => $overtimeHours,
            'payable_overtime_hours' => $payableOvertimeHours,
            'undertime_hours' => $undertimeHours,
            'rounded_undertime_hours' => $roundedUndertimeHours,
            'late_hours' => $lateHours,
            'hourly_rate' => $hourlyRate,
            'daily_rate' => $dailyRate,
            'estimated_gross_pay' => round($estimatedGross, 2),
            'estimated_deductions' => round($estimatedDeductions, 2),
            'estimated_net_pay' => round($estimatedNet, 2),
            'deduction_breakdown' => [
                'late' => round($lateDeduction, 2),
                'first_hit_days' => $firstHitDays,
                'second_hit_days' => $secondHitDays,
                'third_hit_days' => $thirdHitDays,
            ],
        ];
    }
}
