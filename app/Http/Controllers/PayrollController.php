<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsQueryFilters;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollDeduction;
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
    use ReadsQueryFilters;

    /**
     * Where finalized wages are filed. Created on first use rather than seeded, so
     * an install that already has an owner-made "Weekly Salary" reuses it.
     */
    private const SALARY_CATEGORY_SLUG = 'weekly-salary';

    public function index(Request $request): View
    {
        $branches = Branch::visibleTo($request->user())->where('is_active', true)->orderBy('name')->get();
        $employees = Employee::where('is_active', true)
            ->when($request->user()->lockedBranchId(), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->with('branch')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $branchFilter = $this->queryValue($request, 'branch_id');
        $statusFilter = $this->queryValue($request, 'status');
        $search = trim($this->queryValue($request, 'search'));

        $reportsQuery = PayrollEntry::query()
            ->with(['employee.branch', 'payrollPeriod.branch'])
            ->whereHas('payrollPeriod')
            ->join('payroll_periods', 'payroll_entries.payroll_period_id', '=', 'payroll_periods.id')
            ->select('payroll_entries.*')
            // After select(), which would otherwise drop the column it adds.
            ->withSum('manualDeductions', 'amount')
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

        // The cards follow the branch filter (a report's branch is its period's), so a
        // filtered page never shows the company's totals beside one branch's list.
        $monthStart = now()->startOfMonth()->toDateString();
        $statsBase = PayrollEntry::query()
            ->when(! empty($branchFilter) && is_numeric($branchFilter), fn ($q) => $q
                ->whereHas('payrollPeriod', fn ($p) => $p->where('branch_id', (int) $branchFilter)));
        $stats = [
            'drafts' => (clone $statsBase)->where('status', 'draft')->count(),
            'finalized_this_month' => (clone $statsBase)->where('status', 'paid')
                ->whereDate('updated_at', '>=', $monthStart)
                ->count(),
            'paid_total_this_month' => (float) (clone $statsBase)->where('status', 'paid')
                ->whereDate('updated_at', '>=', $monthStart)
                ->sum('net_pay'),
            'pending_total' => (float) (clone $statsBase)->where('status', 'draft')->sum('net_pay'),
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
            // Only the days worked at this period's branch: those are the days it pays.
            $records = AttendanceRecord::where('employee_id', $selectedEmployee->id)
                ->where('branch_id', $payrollPeriod->branch_id)
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

        $manualDeductions = $selectedEntry
            ? $selectedEntry->manualDeductions()->with('recordedBy')->orderBy('id')->get()
            : collect();

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
            'manualDeductions' => $manualDeductions,
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
        $this->authorizeBranch($request, (int) $employee->branch_id);

        $this->guardEmployeeForGenerate($employee);

        DB::transaction(function () use ($validated, $employee, $calculator): void {
            $this->generateForEmployee($employee, $validated['start_date'], $validated['end_date'], $calculator);
        }, PayrollEntry::WRITE_ATTEMPTS);

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
        foreach ($employees as $employee) {
            $this->authorizeBranch($request, (int) $employee->branch_id);
        }

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
                }, PayrollEntry::WRITE_ATTEMPTS);
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
            'paid_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $entry = PayrollEntry::with('payrollPeriod')->findOrFail($validated['payroll_entry_id']);
        $this->authorizeBranch($request, (int) $entry->payrollPeriod?->branch_id);

        // Marking the report paid and recording the money that paid it are one act.
        // Splitting them is what left wages sitting as open drafts while the cash was
        // written down by hand somewhere else, the same wage twice at two amounts.
        return DB::transaction(function () use ($validated): RedirectResponse {
            // Re-read under a lock: a deduction or an attendance refresh committed since
            // the read above changes net_pay, and the wage posted must be this one.
            $entry = PayrollEntry::with(['payrollPeriod', 'employee', 'specialExpense'])
                ->lockForUpdate()
                ->findOrFail($validated['payroll_entry_id']);

            if ($entry->status === 'paid') {
                return back()->with('error', 'Payroll report is already finalized.');
            }

            $uncovered = $entry->uncoveredManualDeductions();
            if ($uncovered > 0) {
                return back()->with('error', '₱'.number_format($uncovered, 2).' of the manual deductions is more than the pay, so it would never be taken. Reduce a deduction first and carry the rest to the next payroll.');
            }

            $entry->update(['status' => 'paid']);

            $period = $entry->payrollPeriod;
            if ($period) {
                $period->update(['processed_at' => now()]);
            }

            $this->postWageToOverhead(
                $entry,
                $validated['payment_method'] ?? 'cash',
                $validated['paid_date'] ?? Carbon::now()->toDateString()
            );

            return back()->with('success', 'Payroll report finalized, locked and posted to overhead.');
        }, PayrollEntry::WRITE_ATTEMPTS);
    }

    /**
     * Take an amount off a draft report by hand, with the reason it was taken.
     * (The report is in the URL, so LockToOwnBranch has already checked its branch.)
     */
    public function storeDeduction(Request $request, PayrollEntry $payrollEntry): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        return DB::transaction(function () use ($request, $payrollEntry, $validated): RedirectResponse {
            $entry = PayrollEntry::whereKey($payrollEntry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'draft') {
                return back()->with('error', 'This payroll report is finalized. Its deductions can no longer change.');
            }

            $amount = round((float) $validated['amount'], 2);
            if ($amount > (float) $entry->net_pay) {
                throw ValidationException::withMessages([
                    'amount' => 'A deduction can be at most the net pay left, ₱'.number_format((float) $entry->net_pay, 2).'.',
                ]);
            }

            $attendanceDeductions = $entry->attendanceDeductionTotal();
            $entry->manualDeductions()->create([
                'amount' => $amount,
                'reason' => $validated['reason'],
                'recorded_by_user_id' => $request->user()->id,
            ]);
            $entry->retotal($attendanceDeductions);

            return back()->with('success', 'Deduction of ₱'.number_format($amount, 2).' added.');
        }, PayrollEntry::WRITE_ATTEMPTS);
    }

    public function destroyDeduction(PayrollDeduction $payrollDeduction): RedirectResponse
    {
        return DB::transaction(function () use ($payrollDeduction): RedirectResponse {
            $entry = PayrollEntry::whereKey($payrollDeduction->payroll_entry_id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'draft') {
                return back()->with('error', 'This payroll report is finalized. Its deductions can no longer change.');
            }

            $attendanceDeductions = $entry->attendanceDeductionTotal();
            // By key: on a deadlock retry the bound model already counts itself deleted.
            PayrollDeduction::whereKey($payrollDeduction->id)->delete();
            $entry->retotal($attendanceDeductions);

            return back()->with('success', 'Deduction removed.');
        }, PayrollEntry::WRITE_ATTEMPTS);
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
    private function postWageToOverhead(PayrollEntry $entry, string $paymentMethod, string $paidDate): void
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
            'paid_date' => $paidDate,
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
        return DB::transaction(function () use ($payrollEntry): RedirectResponse {
            // Re-read under a lock: a report finalized since the page loaded is paid now,
            // and deleting it would also delete its deductions.
            $entry = PayrollEntry::with('payrollPeriod')->lockForUpdate()->findOrFail($payrollEntry->id);
            if ($entry->status !== 'draft') {
                return back()->with('error', 'Only draft payroll reports can be deleted.');
            }

            $period = $entry->payrollPeriod;
            $entry->delete();

            $remainingReports = PayrollEntry::where('payroll_period_id', $period->id)->exists();
            if (! $remainingReports) {
                $period->delete();
            }

            return back()->with('success', 'Payroll report deleted successfully.');
        }, PayrollEntry::WRITE_ATTEMPTS);
    }

    private function guardEmployeeForGenerate(Employee $employee): void
    {
        if ((float) ($employee->daily_rate ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee '.trim($employee->first_name.' '.$employee->last_name).' has no daily rate. Update it first.',
            ]);
        }
    }

    /**
     * Each branch pays the days worked there, at its own rules. Every attendance
     * record carries the branch it was worked at, so an employee who moved branch
     * mid-range gets one report per branch instead of every day landing in their
     * current branch's period. No attendance at all still yields a report at the
     * current branch, as before.
     */
    private function generateForEmployee(
        Employee $employee,
        string $startDate,
        string $endDate,
        AttendanceSummaryCalculator $calculator,
    ): void {
        $recordsByBranch = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->groupBy(fn (AttendanceRecord $record) => (int) $record->branch_id);

        if ($recordsByBranch->isEmpty()) {
            $recordsByBranch = collect([(int) $employee->branch_id => collect()]);
        }

        $generated = [];
        foreach ($recordsByBranch as $branchId => $records) {
            $generated[(int) $branchId] = $this->generateEntryAtBranch($employee, (int) $branchId, $records, $startDate, $endDate, $calculator);
        }

        // The days a stale draft paid now belong to another report, and so do the
        // deductions taken off it: the current branch's report when there is one.
        $replacement = $generated[(int) $employee->branch_id] ?? reset($generated);

        $this->removeStaleDrafts($employee, array_keys($generated), $startDate, $endDate, $replacement);
    }

    /**
     * A draft for these exact dates at a branch the employee no longer has days at
     * would pay those days twice: once there, once where they now belong. This is
     * what a report generated before the split looks like. Paid reports are never
     * touched, and a period left with no reports is removed, as destroyReport does.
     *
     * Its manual deductions move to $replacement first: a cash advance is still owed
     * after the days are paid somewhere else.
     *
     * @param  list<int>  $branchIds  the branches that do have days in the range
     */
    private function removeStaleDrafts(Employee $employee, array $branchIds, string $startDate, string $endDate, PayrollEntry $replacement): void
    {
        // Locked, so a report finalized meanwhile is no longer a draft by the time it is
        // read, and a deduction being added to one waits instead of being deleted with it.
        $stale = PayrollEntry::where('employee_id', $employee->id)
            ->where('status', 'draft')
            ->whereHas('payrollPeriod', fn ($q) => $q
                ->whereDate('start_date', $startDate)
                ->whereDate('end_date', $endDate)
                ->whereNotIn('branch_id', $branchIds))
            ->lockForUpdate()
            ->get();

        foreach ($stale as $entry) {
            $attendanceDeductions = $replacement->attendanceDeductionTotal();
            if ($entry->manualDeductions()->update(['payroll_entry_id' => $replacement->id]) > 0) {
                $replacement->retotal($attendanceDeductions);
            }

            $periodId = $entry->payroll_period_id;
            $entry->delete();

            if (! PayrollEntry::where('payroll_period_id', $periodId)->exists()) {
                PayrollPeriod::whereKey($periodId)->delete();
            }
        }
    }

    private function generateEntryAtBranch(
        Employee $employee,
        int $branchId,
        Collection $records,
        string $startDate,
        string $endDate,
        AttendanceSummaryCalculator $calculator,
    ): PayrollEntry {
        $branchName = Branch::find($branchId)?->name ?? 'Branch '.$branchId;
        $startLabel = Carbon::parse($startDate)->format('M j');
        $endLabel = Carbon::parse($endDate)->format('M j');
        $cutoffLabel = $startLabel.' – '.$endLabel.' · '.$branchName;

        $existingPeriod = PayrollPeriod::where('branch_id', $branchId)
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
                'branch_id' => $branchId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cutoff_label' => $cutoffLabel,
                'status' => 'draft',
                'processed_at' => now(),
            ]);

        // Locked: an add or remove of a manual deduction waits for this rebuild, or it
        // waits for them, so the totals written below always include every deduction.
        $existingEntry = PayrollEntry::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->lockForUpdate()
            ->first();
        if ($existingEntry && $existingEntry->status === 'paid') {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee '.trim($employee->first_name.' '.$employee->last_name).' already has a finalized report for this period.',
            ]);
        }

        $rule = $this->resolveRules($branchId);
        $ruleValues = $rule->toArray();

        $summary = $calculator->summarizeForEmployee($employee, $records, $startDate, $endDate, $ruleValues);

        $regularHours = (float) $summary['regular_hours'];
        $overtimeHours = (float) $summary['payable_overtime_hours'];
        $hourlyRate = (float) ($summary['hourly_rate'] ?? 0);
        $dailyRate = (float) ($summary['daily_rate'] ?? 0);
        $grossPay = (float) $summary['estimated_gross_pay'];
        $pay = PayrollEntry::payAfterDeductions(
            $grossPay,
            (float) $summary['estimated_deductions'],
            $existingEntry?->manualDeductionTotal() ?? 0.0
        );

        $values = [
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'hourly_rate' => $hourlyRate,
            'daily_rate' => round($dailyRate, 2),
            'gross_pay' => round($grossPay, 2),
            'deductions' => $pay['deductions'],
            'net_pay' => $pay['net_pay'],
            'status' => 'draft',
            'notes' => 'Auto-generated from attendance summary. Late: '.$summary['late_hours'].'h, Absent days: '.$summary['absent_days'].', Present days: '.$summary['present_days'],
        ];

        // Through the locked row, not updateOrCreate: that re-reads the entry without the
        // lock and writes only what differs from its own (possibly older) copy.
        return $existingEntry
            ? tap($existingEntry)->update($values)
            : PayrollEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id] + $values);
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

    /**
     * Employees and reports named in a form body: the super admin reaches every
     * branch, a branch admin only their own. (Records in the URL are checked by
     * LockToOwnBranch.)
     */
    private function authorizeBranch(Request $request, int $branchId): void
    {
        abort_unless($request->user()->canAccessBranch($branchId), 403, 'That payroll belongs to another branch.');
    }
}
