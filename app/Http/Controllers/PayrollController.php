<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Services\AttendanceSummaryCalculator;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $employees = Employee::where('is_active', true)
            ->with('branch')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $reports = PayrollEntry::query()
            ->with(['employee.branch', 'payrollPeriod.branch'])
            ->whereHas('payrollPeriod')
            ->orderByDesc('id')
            ->paginate(15);

        $rules = PayrollRule::query()->get()->keyBy('branch_id');

        return view('modules.payroll.index', compact('branches', 'employees', 'reports', 'rules'));
    }

    public function show(Request $request, PayrollPeriod $payrollPeriod): View
    {
        $payrollPeriod->load('branch');
        $entries = PayrollEntry::where('payroll_period_id', $payrollPeriod->id)
            ->with('employee')
            ->orderBy('employee_id')
            ->get();

        $rule = $this->resolveRules((int) $payrollPeriod->branch_id);
        $ruleValues = $rule->toArray();
        $calculator = app(AttendanceSummaryCalculator::class);

        $selectedEmployeeId = (int) ($request->integer('employee_id') ?: ($entries->first()->employee_id ?? 0));
        $selectedEntry = $entries->firstWhere('employee_id', $selectedEmployeeId) ?: $entries->first();
        $selectedEmployee = $selectedEntry?->employee;

        $records = collect();
        $summary = null;
        $dailyBreakdown = collect();
        if ($selectedEmployee) {
            $records = AttendanceRecord::where('employee_id', $selectedEmployee->id)
                ->whereDate('work_date', '>=', $payrollPeriod->start_date)
                ->whereDate('work_date', '<=', $payrollPeriod->end_date)
                ->orderBy('work_date')
                ->get();

            $summary = $calculator->summarizeForEmployee(
                $selectedEmployee,
                $records,
                (string) $payrollPeriod->start_date,
                (string) $payrollPeriod->end_date,
                $ruleValues
            );

            $dailyBreakdown = $this->buildDailyBreakdown(
                $selectedEmployee,
                $records,
                (string) $payrollPeriod->start_date,
                (string) $payrollPeriod->end_date,
                $ruleValues
            );
        }

        $reportLabel = null;
        if ($selectedEmployee) {
            $startLabel = Carbon::parse($payrollPeriod->start_date)->format('M j');
            $endLabel = Carbon::parse($payrollPeriod->end_date)->format('M j');
            $reportLabel = trim($selectedEmployee->first_name.' '.$selectedEmployee->last_name)
                .' ('.$startLabel.' - '.$endLabel.')';
        }

        return view('modules.payroll.show', [
            'period' => $payrollPeriod,
            'entries' => $entries,
            'selectedEntry' => $selectedEntry,
            'selectedEmployee' => $selectedEmployee,
            'summary' => $summary,
            'dailyBreakdown' => $dailyBreakdown,
            'rule' => $rule,
            'reportLabel' => $reportLabel,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $calculator = app(AttendanceSummaryCalculator::class);
        $employee = Employee::where('is_active', true)->findOrFail((int) $validated['employee_id']);

        if ((float) ($employee->daily_rate ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Selected employee has no daily rate set. Please update the employee daily rate first.',
            ]);
        }

        $startLabel = Carbon::parse($validated['start_date'])->format('M j');
        $endLabel = Carbon::parse($validated['end_date'])->format('M j');
        $cutoffLabel = trim($employee->first_name.' '.$employee->last_name).' ('.$startLabel.' - '.$endLabel.')';

        DB::transaction(function () use ($validated, $employee, $calculator, $cutoffLabel): void {
            $existingPeriod = PayrollPeriod::where('branch_id', $employee->branch_id)
                ->whereDate('start_date', $validated['start_date'])
                ->whereDate('end_date', $validated['end_date'])
                ->first();

            $period = $existingPeriod
                ? tap($existingPeriod)->update([
                    'cutoff_label' => $cutoffLabel,
                    'status' => 'draft',
                    'processed_at' => now(),
                ])
                : PayrollPeriod::create([
                    'branch_id' => $employee->branch_id,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'cutoff_label' => $cutoffLabel,
                    'status' => 'draft',
                    'processed_at' => now(),
                ]);

            $existingEntry = PayrollEntry::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();
            if ($existingEntry && $existingEntry->status === 'paid') {
                throw ValidationException::withMessages([
                    'employee_id' => 'This employee payroll report is already finalized and locked.',
                ]);
            }

            $rule = $this->resolveRules((int) $employee->branch_id);
            $ruleValues = $rule->toArray();

            $records = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', $validated['start_date'])
                ->whereDate('work_date', '<=', $validated['end_date'])
                ->get();

            $summary = $calculator->summarizeForEmployee(
                $employee,
                $records,
                $validated['start_date'],
                $validated['end_date'],
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
                    'notes' => 'Auto-generated from attendance summary. '
                        .'Late: '.$summary['late_hours'].'h, '
                        .'Absent days: '.$summary['absent_days'].', '
                        .'Present days: '.$summary['present_days'],
                ]
            );
        });

        return back()->with('success', 'Payroll period generated successfully.');
    }

    public function updateRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'standard_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'required_clock_in_time' => ['required', 'date_format:H:i'],
            'first_deduction_time' => ['required', 'date_format:H:i'],
            'first_deduction_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'second_deduction_time' => ['required', 'date_format:H:i'],
            'second_deduction_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'third_deduction_time' => ['required', 'date_format:H:i'],
            'third_deduction_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $requiredAt = Carbon::createFromFormat('H:i', $validated['required_clock_in_time']);
        $firstAt = Carbon::createFromFormat('H:i', $validated['first_deduction_time']);
        $secondAt = Carbon::createFromFormat('H:i', $validated['second_deduction_time']);
        $thirdAt = Carbon::createFromFormat('H:i', $validated['third_deduction_time']);

        if ($firstAt->lt($requiredAt) || $secondAt->lt($firstAt) || $thirdAt->lt($secondAt)) {
            throw ValidationException::withMessages([
                'first_deduction_time' => 'Deduction hit times must be in chronological order from required time-in.',
            ]);
        }

        PayrollRule::updateOrCreate(
            ['branch_id' => $validated['branch_id']],
            [
                'standard_daily_hours' => (float) $validated['standard_daily_hours'],
                'required_clock_in_time' => $requiredAt->format('H:i:s'),
                'first_deduction_time' => $firstAt->format('H:i:s'),
                'first_deduction_amount' => round((float) $validated['first_deduction_amount'], 2),
                'second_deduction_time' => $secondAt->format('H:i:s'),
                'second_deduction_amount' => round((float) $validated['second_deduction_amount'], 2),
                'third_deduction_time' => $thirdAt->format('H:i:s'),
                'third_deduction_percent' => round((float) $validated['third_deduction_percent'], 2),
            ]
        );

        return back()->with('success', 'Payroll rules updated successfully.');
    }

    public function finalize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payroll_entry_id' => ['required', 'integer', 'exists:payroll_entries,id'],
        ]);

        $entry = PayrollEntry::with('payrollPeriod')->findOrFail($validated['payroll_entry_id']);
        if ($entry->status === 'paid') {
            return back()->with('error', 'Payroll report is already finalized.');
        }

        $entry->update(['status' => 'paid']);

        $period = $entry->payrollPeriod;
        if ($period) {
            $period->update(['processed_at' => now()]);
        }

        return back()->with('success', 'Payroll report finalized and locked.');
    }

    public function destroy(PayrollPeriod $payrollPeriod): RedirectResponse
    {
        if ($payrollPeriod->status !== 'draft') {
            return back()->with('error', 'Only draft payroll periods can be deleted.');
        }

        DB::transaction(function () use ($payrollPeriod): void {
            $payrollPeriod->delete();
        });

        return back()->with('success', 'Draft payroll period deleted successfully.');
    }

    public function destroyReport(PayrollEntry $payrollEntry): RedirectResponse
    {
        $payrollEntry->load('payrollPeriod');
        $period = $payrollEntry->payrollPeriod;

        if ($payrollEntry->status !== 'draft') {
            return back()->with('error', 'Only draft payroll reports can be deleted.');
        }

        DB::transaction(function () use ($payrollEntry, $period): void {
            $payrollEntry->delete();

            // Keep data clean: remove empty draft period container after last employee report is deleted.
            $remainingReports = PayrollEntry::where('payroll_period_id', $period->id)->exists();
            if (! $remainingReports) {
                $period->delete();
            }
        });

        return back()->with('success', 'Payroll report deleted successfully.');
    }

    private function resolveRules(int $branchId): PayrollRule
    {
        return PayrollRule::firstOrCreate(
            ['branch_id' => $branchId],
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
    }

    private function buildDailyBreakdown(
        Employee $employee,
        Collection $records,
        string $startDate,
        string $endDate,
        array $rules
    ): Collection {
        $dailyRate = (float) ($employee->daily_rate ?? 0);
        $requiredClockInTime = (string) ($rules['required_clock_in_time'] ?? '09:00:00');
        $firstDeductionTime = (string) ($rules['first_deduction_time'] ?? '09:15:00');
        $firstDeductionAmount = max(0, (float) ($rules['first_deduction_amount'] ?? 0));
        $secondDeductionTime = (string) ($rules['second_deduction_time'] ?? '09:30:00');
        $secondDeductionAmount = max(0, (float) ($rules['second_deduction_amount'] ?? 0));
        $thirdDeductionTime = (string) ($rules['third_deduction_time'] ?? '10:00:00');
        $thirdDeductionPercent = max(0, min(100, (float) ($rules['third_deduction_percent'] ?? 0)));

        $recordsByDate = $records->keyBy(fn (AttendanceRecord $record) => (string) $record->work_date);
        $period = CarbonPeriod::create($startDate, $endDate);
        $rows = collect();

        foreach ($period as $date) {
            $workDate = $date->format('Y-m-d');
            /** @var AttendanceRecord|null $record */
            $record = $recordsByDate->get($workDate);

            $clockInAt = $record?->clock_in_at ? Carbon::parse($record->clock_in_at) : null;
            $clockOutAt = $record?->clock_out_at ? Carbon::parse($record->clock_out_at) : null;
            $isPresent = $clockInAt && $clockOutAt && $clockOutAt->greaterThan($clockInAt);

            $requiredAt = Carbon::parse($workDate.' '.$requiredClockInTime);
            $firstHitAt = Carbon::parse($workDate.' '.$firstDeductionTime);
            $secondHitAt = Carbon::parse($workDate.' '.$secondDeductionTime);
            $thirdHitAt = Carbon::parse($workDate.' '.$thirdDeductionTime);

            $tier = 'None';
            $lateDeduction = 0.0;
            $lateMinutes = 0;
            if ($isPresent && $clockInAt->greaterThan($requiredAt)) {
                $lateMinutes = $requiredAt->diffInMinutes($clockInAt);
            }

            if ($isPresent && $clockInAt->greaterThanOrEqualTo($thirdHitAt)) {
                $tier = '3rd Hit';
                $lateDeduction = $dailyRate * ($thirdDeductionPercent / 100);
            } elseif ($isPresent && $clockInAt->greaterThanOrEqualTo($secondHitAt)) {
                $tier = '2nd Hit';
                $lateDeduction = $secondDeductionAmount;
            } elseif ($isPresent && $clockInAt->greaterThanOrEqualTo($firstHitAt)) {
                $tier = '1st Hit';
                $lateDeduction = $firstDeductionAmount;
            }

            $gross = $isPresent ? $dailyRate : 0.0;
            $net = max(0, $gross - $lateDeduction);

            $rows->push([
                'work_date' => $workDate,
                'status' => $record?->status ?? 'absent',
                'clock_in' => $clockInAt?->format('h:i A') ?? '-',
                'clock_out' => $clockOutAt?->format('h:i A') ?? '-',
                'late_minutes' => $lateMinutes,
                'deduction_tier' => $tier,
                'gross' => round($gross, 2),
                'deduction' => round($lateDeduction, 2),
                'net' => round($net, 2),
            ]);
        }

        return $rows;
    }
}
