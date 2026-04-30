<?php

namespace App\Http\Controllers;

use App\Exceptions\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Services\AttendanceService;
use App\Services\AttendanceSummaryCalculator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    public function index(Request $request): View
    {
        $workDate = $request->string('work_date')->toString() ?: now()->toDateString();
        $dateFrom = $request->string('date_from')->toString() ?: now()->startOfWeek()->toDateString();
        $dateTo = $request->string('date_to')->toString() ?: now()->endOfWeek()->toDateString();
        $branchFilter = $request->query('branch_id');

        $employees = Employee::where('is_active', true)
            ->with('branch')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
        $employeeIds = $employees->pluck('id');

        $branches = \App\Models\Branch::where('is_active', true)->orderBy('name')->get();

        $rulesByBranch = PayrollRule::whereIn('branch_id', $employees->pluck('branch_id')->unique())
            ->get()
            ->keyBy('branch_id');

        $records = AttendanceRecord::whereDate('work_date', $workDate)
            ->orderByDesc('clock_in_at')
            ->get();
        $recordsByEmployee = $records->keyBy('employee_id');

        $rosterEmployees = $employees;
        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $rosterEmployees = $rosterEmployees->where('branch_id', (int) $branchFilter)->values();
        }

        $roster = $rosterEmployees->map(function (Employee $employee) use ($recordsByEmployee, $rulesByBranch, $workDate): array {
            $record = $recordsByEmployee->get($employee->id);
            $rule = $rulesByBranch->get($employee->branch_id);

            $clockInAt = $record?->clock_in_at ? Carbon::parse($record->clock_in_at) : null;
            $clockOutAt = $record?->clock_out_at ? Carbon::parse($record->clock_out_at) : null;

            $state = 'not_yet';
            if ($record) {
                if ($record->status === 'absent') {
                    $state = 'absent';
                } elseif (in_array($record->status, ['leave', 'holiday'], true)) {
                    $state = 'leave';
                } elseif ($clockInAt && $clockOutAt) {
                    $state = 'done';
                } elseif ($clockInAt) {
                    $state = 'working';
                }
            }

            $isLate = false;
            if ($clockInAt && $rule) {
                $required = $rule->required_clock_in_time ?? '09:00:00';
                $grace = (int) ($rule->grace_minutes ?? 0);
                $boundary = Carbon::parse($workDate.' '.$required)->addMinutes($grace);
                $isLate = $clockInAt->gt($boundary);
            } elseif ($record?->status === 'late') {
                $isLate = true;
            }

            $minutesWorked = 0;
            if ($clockInAt && $clockOutAt) {
                $minutesWorked = $clockInAt->diffInMinutes($clockOutAt) - (int) ($record->break_minutes ?? 0);
                $minutesWorked = max(0, $minutesWorked);
            }

            return [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'full_name' => trim($employee->first_name.' '.$employee->last_name),
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'state' => $state,
                'is_late' => $isLate,
                'record_id' => $record?->id,
                'clock_in_at' => $clockInAt?->toIso8601String(),
                'clock_in_label' => $clockInAt?->format('h:i A'),
                'clock_out_at' => $clockOutAt?->toIso8601String(),
                'clock_out_label' => $clockOutAt?->format('h:i A'),
                'status' => $record?->status,
                'notes' => $record?->notes,
                'hours_worked' => round($minutesWorked / 60, 2),
            ];
        });

        $stats = [
            'present' => $roster->whereIn('state', ['working', 'done'])->count(),
            'late' => $roster->where('is_late', true)->count(),
            'absent' => $roster->where('state', 'absent')->count(),
            'working_now' => $roster->where('state', 'working')->count(),
            'not_yet' => $roster->where('state', 'not_yet')->count(),
            'total_hours' => round($roster->sum('hours_worked'), 1),
        ];

        $rosterByState = [
            'working' => $roster->where('state', 'working')->values(),
            'done' => $roster->where('state', 'done')->values(),
            'not_yet' => $roster->where('state', 'not_yet')->values(),
            'absent' => $roster->where('state', 'absent')->values(),
            'leave' => $roster->where('state', 'leave')->values(),
        ];

        $recordsInRange = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $dateFrom)
            ->whereDate('work_date', '<=', $dateTo)
            ->get()
            ->groupBy('employee_id');

        $calculator = app(AttendanceSummaryCalculator::class);
        $summaries = $employees->map(function (Employee $employee) use ($calculator, $recordsInRange, $rulesByBranch, $dateFrom, $dateTo): array {
            $employeeRecords = $recordsInRange->get($employee->id, collect());
            $rule = $rulesByBranch->get($employee->branch_id);
            $ruleValues = $rule ? $rule->toArray() : [];

            if (! $employeeRecords instanceof Collection) {
                $employeeRecords = collect();
            }

            return $calculator->summarizeForEmployee($employee, $employeeRecords, $dateFrom, $dateTo, $ruleValues);
        })->values();

        return view('modules.attendance.index', [
            'workDate' => $workDate,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'branchFilter' => $branchFilter,
            'branches' => $branches,
            'employees' => $employees,
            'records' => $records,
            'summaries' => $summaries,
            'roster' => $roster,
            'rosterByState' => $rosterByState,
            'stats' => $stats,
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        try {
            $this->attendanceService->clockIn(
                $employee,
                $validated['work_date'],
                $request->user(),
                $validated['notes'] ?? null
            );
        } catch (AttendanceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Employee timed in successfully.');
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'record_id' => ['required', 'integer', 'exists:attendance_records,id'],
        ]);

        $record = AttendanceRecord::findOrFail($validated['record_id']);

        try {
            $this->attendanceService->clockOut($record);
        } catch (AttendanceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Employee timed out successfully.');
    }

    public function manualEntry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'clock_in_time' => ['required', 'date_format:H:i'],
            'clock_out_time' => ['nullable', 'date_format:H:i', 'after:clock_in_time'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $clockInAt = Carbon::parse($validated['work_date'].' '.$validated['clock_in_time']);
        $clockOutAt = null;

        if (! empty($validated['clock_out_time'])) {
            $clockOutAt = Carbon::parse($validated['work_date'].' '.$validated['clock_out_time']);
            if ($clockOutAt->lessThanOrEqualTo($clockInAt)) {
                throw ValidationException::withMessages([
                    'clock_out_time' => 'Clock out time must be later than clock in time.',
                ]);
            }
        }

        $record = AttendanceRecord::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $validated['work_date'],
        ]);

        $record->branch_id = $employee->branch_id;
        $record->clock_in_at = $clockInAt;
        $record->clock_out_at = $clockOutAt;
        $record->status = 'present';
        $record->notes = $validated['notes'] ?? $record->notes;
        $record->captured_by_user_id = $request->user()->id;
        $record->save();

        return back()->with('success', 'Manual attendance entry saved.');
    }

    public function updateTimes(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'record_id' => ['required', 'integer', 'exists:attendance_records,id'],
            'clock_in_time' => ['nullable', 'date_format:H:i', 'required_without:clock_out_time'],
            'clock_out_time' => ['nullable', 'date_format:H:i', 'required_without:clock_in_time'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = AttendanceRecord::findOrFail($validated['record_id']);
        $workDate = Carbon::parse($record->work_date)->toDateString();

        $clockInAt = $record->clock_in_at ? Carbon::parse($record->clock_in_at) : null;
        $clockOutAt = $record->clock_out_at ? Carbon::parse($record->clock_out_at) : null;

        if (array_key_exists('clock_in_time', $validated) && $validated['clock_in_time']) {
            $clockInAt = Carbon::parse($workDate.' '.$validated['clock_in_time']);
        }

        if (array_key_exists('clock_out_time', $validated) && $validated['clock_out_time']) {
            $clockOutAt = Carbon::parse($workDate.' '.$validated['clock_out_time']);
        }

        if ($clockInAt && $clockOutAt && $clockOutAt->lessThanOrEqualTo($clockInAt)) {
            throw ValidationException::withMessages([
                'clock_out_time' => 'Clock out time must be later than clock in time.',
            ]);
        }

        $record->clock_in_at = $clockInAt;
        $record->clock_out_at = $clockOutAt;
        if (array_key_exists('notes', $validated)) {
            $record->notes = $validated['notes'];
        }
        $record->captured_by_user_id = $request->user()->id;
        $record->save();

        return back()->with('success', 'Attendance time updated.');
    }

    public function destroy(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $employee = Employee::find($attendanceRecord->employee_id);
        $workDate = Carbon::parse($attendanceRecord->work_date)->toDateString();

        DB::transaction(function () use ($attendanceRecord, $employee, $workDate): void {
            $attendanceRecord->delete();

            if ($employee) {
                $this->syncDraftPayrollEntriesForEmployeeDate($employee, $workDate);
            }
        });

        return back()->with('success', 'Daily attendance record deleted and related draft payroll entries updated.');
    }

    private function syncDraftPayrollEntriesForEmployeeDate(Employee $employee, string $workDate): void
    {
        $periods = PayrollPeriod::where('branch_id', $employee->branch_id)
            ->where('status', 'draft')
            ->whereDate('start_date', '<=', $workDate)
            ->whereDate('end_date', '>=', $workDate)
            ->get();

        if ($periods->isEmpty()) {
            return;
        }

        $rule = PayrollRule::firstOrCreate(
            ['branch_id' => $employee->branch_id],
            [
                'standard_daily_hours' => 8,
                'required_clock_in_time' => '09:00:00',
                'first_deduction_time' => '09:15:00',
                'first_deduction_amount' => 50,
                'second_deduction_time' => '09:30:00',
                'second_deduction_amount' => 100,
                'third_deduction_time' => '10:00:00',
                'third_deduction_percent' => 50,
                'overtime_threshold_minutes' => 30,
                'overtime_multiplier' => 1.25,
                'undertime_rounding_minutes' => 15,
                'late_penalty_per_minute' => 0,
                'absent_penalty_days' => 1,
            ]
        );

        $ruleValues = $rule->toArray();
        $calculator = app(AttendanceSummaryCalculator::class);

        foreach ($periods as $period) {
            $existingEntry = PayrollEntry::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();
            if ($existingEntry && $existingEntry->status === 'paid') {
                continue;
            }

            $records = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', $period->start_date)
                ->whereDate('work_date', '<=', $period->end_date)
                ->get();

            $summary = $calculator->summarizeForEmployee(
                $employee,
                $records,
                (string) $period->start_date,
                (string) $period->end_date,
                $ruleValues
            );

            $regularHours = (float) $summary['regular_hours'];
            $overtimeHours = (float) $summary['payable_overtime_hours'];
            $hourlyRate = (float) ($summary['hourly_rate'] ?? 0);
            $dailyRate = (float) ($summary['daily_rate'] ?? 0);
            $grossPay = (float) $summary['estimated_gross_pay'];
            $deductions = (float) $summary['estimated_deductions'];
            $netPay = max(0, $grossPay - $deductions);

            PayrollEntry::updateOrCreate(
                [
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'regular_hours' => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'hourly_rate' => $hourlyRate,
                    'daily_rate' => round($dailyRate, 2),
                    'gross_pay' => round($grossPay, 2),
                    'deductions' => $deductions,
                    'net_pay' => round($netPay, 2),
                    'status' => 'draft',
                    'notes' => 'Auto-updated after attendance record deletion.',
                ]
            );
        }
    }
}
