<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Services\AttendanceSummaryCalculator;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollController extends Controller
{
    /**
     * Where finalized wages are filed. Created on first use rather than seeded, so
     * an install that already has an owner-made "Weekly Salary" reuses it.
     */
    private const SALARY_CATEGORY_SLUG = 'weekly-salary';

    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $employees = Employee::where('is_active', true)
            ->with('branch')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $branchFilter = $request->query('branch_id');
        $statusFilter = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        $reportsQuery = PayrollEntry::query()
            ->with(['employee.branch', 'payrollPeriod.branch'])
            ->whereHas('payrollPeriod')
            ->join('payroll_periods', 'payroll_entries.payroll_period_id', '=', 'payroll_periods.id')
            ->select('payroll_entries.*')
            ->orderByDesc('payroll_periods.end_date')
            ->orderByDesc('payroll_periods.start_date')
            ->orderByDesc('payroll_entries.id');

        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $reportsQuery->whereHas('payrollPeriod', fn ($q) => $q->where('branch_id', (int) $branchFilter));
        }
        if (in_array($statusFilter, ['draft', 'paid'], true)) {
            // payroll_periods carries a `status` column too, so an unqualified
            // name here is ambiguous once the join is on and the query is refused.
            $reportsQuery->where('payroll_entries.status', $statusFilter);
        }
        if ($search !== '') {
            $needle = '%'.$search.'%';
            // The name alternatives must be grouped: appended flat they would also
            // OR away the correlation whereHas puts on the subquery, matching every row.
            $reportsQuery->whereHas('employee', function ($q) use ($needle) {
                $q->where(function ($nameMatch) use ($needle) {
                    $nameMatch->where('first_name', 'like', $needle)
                        ->orWhere('last_name', 'like', $needle)
                        ->orWhere('employee_code', 'like', $needle);
                });
            });
        }

        $reports = (clone $reportsQuery)->paginate(15)->withQueryString();

        $rules = PayrollRule::query()->get()->keyBy('branch_id');

        $monthStart = now()->startOfMonth()->toDateString();
        $stats = [
            'drafts' => PayrollEntry::where('status', 'draft')->count(),
            'finalized_this_month' => PayrollEntry::where('status', 'paid')
                ->whereDate('updated_at', '>=', $monthStart)
                ->count(),
            'paid_total_this_month' => (float) PayrollEntry::where('status', 'paid')
                ->whereDate('updated_at', '>=', $monthStart)
                ->sum('net_pay'),
            'pending_total' => (float) PayrollEntry::where('status', 'draft')->sum('net_pay'),
        ];

        $filters = [
            'search' => $search,
            'branch_id' => $branchFilter,
            'status' => $statusFilter,
        ];

        return view('modules.payroll.index', compact('branches', 'employees', 'reports', 'rules', 'stats', 'filters'));
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

        $totalNet = (float) $entries->sum('net_pay');
        $totalGross = (float) $entries->sum('gross_pay');
        $totalDeductions = (float) $entries->sum('deductions');

        return view('modules.payroll.show', [
            'period' => $payrollPeriod,
            'entries' => $entries,
            'selectedEntry' => $selectedEntry,
            'selectedEmployee' => $selectedEmployee,
            'summary' => $summary,
            'dailyBreakdown' => $dailyBreakdown,
            'rule' => $rule,
            'totals' => [
                'net' => $totalNet,
                'gross' => $totalGross,
                'deductions' => $totalDeductions,
                'count' => $entries->count(),
            ],
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

        $this->guardEmployeeForGenerate($employee);

        DB::transaction(function () use ($validated, $employee, $calculator): void {
            $this->generateForEmployee($employee, $validated['start_date'], $validated['end_date'], $calculator);
        });

        return back()->with('success', 'Payroll period generated successfully.');
    }

    public function bulkGenerate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $calculator = app(AttendanceSummaryCalculator::class);
        $employees = Employee::where('is_active', true)
            ->whereIn('id', $validated['employee_ids'])
            ->get();

        $skipped = [];
        $generated = 0;

        foreach ($employees as $employee) {
            try {
                $this->guardEmployeeForGenerate($employee);
            } catch (ValidationException) {
                $skipped[] = trim($employee->first_name.' '.$employee->last_name).' (no daily rate)';
                continue;
            }

            // generateForEmployee also throws for an already-finalized entry, and that
            // throw used to escape the loop: employees already processed stayed committed
            // in their own transactions while the page reported only the error, and anyone
            // after the failure point was silently dropped from payroll.
            try {
                DB::transaction(function () use ($employee, $validated, $calculator): void {
                    $this->generateForEmployee($employee, $validated['start_date'], $validated['end_date'], $calculator);
                });
                $generated++;
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->first() ?? 'could not be generated';
                $skipped[] = trim($employee->first_name.' '.$employee->last_name).' ('.$reason.')';
            }
        }

        $message = "Generated {$generated} payroll report".($generated === 1 ? '' : 's').'.';
        if (! empty($skipped)) {
            $message .= ' Skipped: '.implode(', ', $skipped).'.';
        }

        return back()->with($generated > 0 ? 'success' : 'error', $message);
    }

    public function updateRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'standard_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'required_clock_in_time' => ['required', 'date_format:H:i'],
            'first_deduction_time' => ['required', 'date_format:H:i'],
            'first_deduction_type' => ['required', Rule::in(AttendanceSummaryCalculator::TYPES)],
            'first_deduction_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'first_deduction_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'second_deduction_time' => ['required', 'date_format:H:i'],
            'second_deduction_type' => ['required', Rule::in(AttendanceSummaryCalculator::TYPES)],
            'second_deduction_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'second_deduction_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'third_deduction_time' => ['required', 'date_format:H:i'],
            'third_deduction_type' => ['required', Rule::in(AttendanceSummaryCalculator::TYPES)],
            'third_deduction_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
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
                'first_deduction_type' => $validated['first_deduction_type'],
                'first_deduction_amount' => round((float) $validated['first_deduction_amount'], 2),
                'first_deduction_percent' => round((float) $validated['first_deduction_percent'], 2),
                'second_deduction_time' => $secondAt->format('H:i:s'),
                'second_deduction_type' => $validated['second_deduction_type'],
                'second_deduction_amount' => round((float) $validated['second_deduction_amount'], 2),
                'second_deduction_percent' => round((float) $validated['second_deduction_percent'], 2),
                'third_deduction_time' => $thirdAt->format('H:i:s'),
                'third_deduction_type' => $validated['third_deduction_type'],
                'third_deduction_amount' => round((float) $validated['third_deduction_amount'], 2),
                'third_deduction_percent' => round((float) $validated['third_deduction_percent'], 2),
            ]
        );

        return back()->with('success', 'Payroll rules updated successfully.');
    }

    public function finalize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payroll_entry_id' => ['required', 'integer', 'exists:payroll_entries,id'],
            'payment_method' => ['nullable', Rule::in(SpecialExpense::PAYMENT_METHODS)],
        ]);

        $entry = PayrollEntry::with(['payrollPeriod', 'employee', 'specialExpense'])
            ->findOrFail($validated['payroll_entry_id']);

        if ($entry->status === 'paid') {
            return back()->with('error', 'Payroll report is already finalized.');
        }

        // Marking the report paid and recording the money that paid it are one act.
        // Splitting them is what left wages sitting as open drafts while the cash was
        // written down by hand somewhere else, the same wage twice at two amounts.
        DB::transaction(function () use ($entry, $validated): void {
            $entry->update(['status' => 'paid']);

            $period = $entry->payrollPeriod;
            if ($period) {
                $period->update(['processed_at' => now()]);
            }

            $this->postWageToOverhead($entry, $validated['payment_method'] ?? 'cash');
        });

        return back()->with('success', 'Payroll report finalized, locked and posted to overhead.');
    }

    /**
     * Record the settled wage in `special_expenses`.
     *
     * Deliberately not `expenses`: daily expenses feed the drawer reconciliation and
     * the day closure, so a month of wages landing there reports a cash shortage no
     * cashier caused. Overhead is the ledger salary money already goes through.
     *
     * Dated to the month the pay period ENDS in — the month the wage belongs to —
     * while paid_date carries the day it was actually settled.
     */
    private function postWageToOverhead(PayrollEntry $entry, string $paymentMethod): void
    {
        if ($entry->specialExpense) {
            return;
        }

        $period = $entry->payrollPeriod;
        $employee = $entry->employee;

        $category = SpecialExpenseCategory::firstOrCreate(
            ['slug' => self::SALARY_CATEGORY_SLUG],
            ['name' => 'Weekly Salary', 'is_active' => true]
        );

        $employeeName = $employee
            ? trim($employee->first_name.' '.$employee->last_name)
            : 'Employee #'.$entry->employee_id;
        $periodLabel = $period
            ? Carbon::parse($period->start_date)->format('M j').' – '.Carbon::parse($period->end_date)->format('M j, Y')
            : 'unknown period';

        SpecialExpense::create([
            'branch_id' => $period?->branch_id ?? $employee?->branch_id,
            'special_expense_category_id' => $category->id,
            'payroll_entry_id' => $entry->id,
            'recorded_by_user_id' => request()->user()?->id,
            'period_month' => ($period
                ? Carbon::parse($period->end_date)
                : Carbon::now())->startOfMonth()->toDateString(),
            'paid_date' => Carbon::now()->toDateString(),
            'description' => $employeeName.' ('.$periodLabel.')',
            'amount' => round((float) $entry->net_pay, 2),
            'payment_method' => $paymentMethod,
            'notes' => 'Posted automatically when payroll report #'.$entry->id.' was finalized.',
        ]);
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

            $remainingReports = PayrollEntry::where('payroll_period_id', $period->id)->exists();
            if (! $remainingReports) {
                $period->delete();
            }
        });

        return back()->with('success', 'Payroll report deleted successfully.');
    }

    private function guardEmployeeForGenerate(Employee $employee): void
    {
        if ((float) ($employee->daily_rate ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee '.trim($employee->first_name.' '.$employee->last_name).' has no daily rate. Update it first.',
            ]);
        }
    }

    private function generateForEmployee(
        Employee $employee,
        string $startDate,
        string $endDate,
        AttendanceSummaryCalculator $calculator,
    ): void {
        $branchName = $employee->branch?->name ?? 'Branch '.$employee->branch_id;
        $startLabel = Carbon::parse($startDate)->format('M j');
        $endLabel = Carbon::parse($endDate)->format('M j');
        $cutoffLabel = $startLabel.' – '.$endLabel.' · '.$branchName;

        $existingPeriod = PayrollPeriod::where('branch_id', $employee->branch_id)
            ->whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->first();

        $period = $existingPeriod
            ? tap($existingPeriod)->update([
                'cutoff_label' => $cutoffLabel,
                'status' => 'draft',
                'processed_at' => now(),
            ])
            : PayrollPeriod::create([
                'branch_id' => $employee->branch_id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cutoff_label' => $cutoffLabel,
                'status' => 'draft',
                'processed_at' => now(),
            ]);

        $existingEntry = PayrollEntry::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();
        if ($existingEntry && $existingEntry->status === 'paid') {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee '.trim($employee->first_name.' '.$employee->last_name).' already has a finalized report for this period.',
            ]);
        }

        $rule = $this->resolveRules((int) $employee->branch_id);
        $ruleValues = $rule->toArray();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get();

        $summary = $calculator->summarizeForEmployee($employee, $records, $startDate, $endDate, $ruleValues);

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
                'notes' => 'Auto-generated from attendance summary. Late: '.$summary['late_hours'].'h, Absent days: '.$summary['absent_days'].', Present days: '.$summary['present_days'],
            ]
        );
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
                'first_deduction_type' => 'amount',
                'second_deduction_type' => 'amount',
                'third_deduction_type' => 'percent',
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

            // Through the calculator, never inline: these rows are printed directly
            // under the stored total they are supposed to explain.
            $calculator = app(AttendanceSummaryCalculator::class);
            if ($isPresent && $clockInAt->greaterThanOrEqualTo($thirdHitAt)) {
                $tier = '3rd Hit';
                $lateDeduction = $calculator->tierDeduction('third', $rules, $dailyRate);
            } elseif ($isPresent && $clockInAt->greaterThanOrEqualTo($secondHitAt)) {
                $tier = '2nd Hit';
                $lateDeduction = $calculator->tierDeduction('second', $rules, $dailyRate);
            } elseif ($isPresent && $clockInAt->greaterThanOrEqualTo($firstHitAt)) {
                $tier = '1st Hit';
                $lateDeduction = $calculator->tierDeduction('first', $rules, $dailyRate);
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
