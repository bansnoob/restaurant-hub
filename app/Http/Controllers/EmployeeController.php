<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $search = trim((string) $request->query('search', ''));
        $branchId = $request->query('branch_id');
        $types = (array) $request->query('types', []);
        $activeFilter = $request->query('active', 'all'); // all|active|inactive

        $query = Employee::query()->with('branch');

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('first_name', 'like', $needle)
                  ->orWhere('last_name', 'like', $needle)
                  ->orWhere('employee_code', 'like', $needle)
                  ->orWhere('email', 'like', $needle);
            });
        }

        if (! empty($branchId) && is_numeric($branchId)) {
            $query->where('branch_id', (int) $branchId);
        }

        $allowedTypes = ['full_time', 'part_time', 'contract'];
        $types = array_values(array_intersect($allowedTypes, $types));
        if (! empty($types)) {
            $query->whereIn('employment_type', $types);
        }

        if ($activeFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($activeFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $employees = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => Employee::count(),
            'active' => Employee::where('is_active', true)->count(),
            'inactive' => Employee::where('is_active', false)->count(),
            'full_time' => Employee::where('employment_type', 'full_time')->where('is_active', true)->count(),
            'part_time' => Employee::where('employment_type', 'part_time')->where('is_active', true)->count(),
            'contract' => Employee::where('employment_type', 'contract')->where('is_active', true)->count(),
        ];

        $filters = [
            'search' => $search,
            'branch_id' => $branchId,
            'types' => $types,
            'active' => $activeFilter,
        ];

        return view('modules.employees.index', compact('branches', 'employees', 'stats', 'filters'));
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load('branch');

        $thirtyDaysAgo = Carbon::today()->subDays(29)->toDateString();
        $today = Carbon::today()->toDateString();

        $attendanceRows = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$thirtyDaysAgo, $today])
            ->select('status', 'clock_in_at', 'clock_out_at', 'break_minutes', 'work_date')
            ->get();

        $statusCounts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0];
        $totalMinutes = 0;
        foreach ($attendanceRows as $row) {
            if (isset($statusCounts[$row->status])) {
                $statusCounts[$row->status]++;
            }
            if ($row->clock_in_at && $row->clock_out_at) {
                $minutes = Carbon::parse($row->clock_out_at)->diffInMinutes(Carbon::parse($row->clock_in_at));
                $totalMinutes += max(0, $minutes - (int) ($row->break_minutes ?? 0));
            }
        }

        $payrollHistory = DB::table('payroll_entries')
            ->join('payroll_periods', 'payroll_entries.payroll_period_id', '=', 'payroll_periods.id')
            ->where('payroll_entries.employee_id', $employee->id)
            ->orderByDesc('payroll_periods.end_date')
            ->limit(5)
            ->select(
                'payroll_entries.id',
                'payroll_entries.gross_pay',
                'payroll_entries.deductions',
                'payroll_entries.net_pay',
                'payroll_entries.status',
                'payroll_periods.start_date',
                'payroll_periods.end_date',
            )
            ->get();

        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;
        $tenure = null;
        if ($hireDate) {
            $diff = $hireDate->diff(Carbon::today());
            $tenure = [
                'years' => $diff->y,
                'months' => $diff->m,
                'days' => $diff->d,
            ];
        }

        $birthday = $employee->birthday ? Carbon::parse($employee->birthday) : null;
        $age = null;
        $nextBirthdayInDays = null;
        $isBirthdayToday = false;
        if ($birthday) {
            $today = Carbon::today();
            $age = $birthday->diffInYears($today);
            $isBirthdayToday = $birthday->format('m-d') === $today->format('m-d');

            $nextOccurrence = $birthday->copy()->setYear($today->year);
            if ($nextOccurrence->lt($today)) {
                $nextOccurrence->addYear();
            }
            $nextBirthdayInDays = (int) $today->diffInDays($nextOccurrence, false);
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'hire_date' => optional($employee->hire_date)->toDateString(),
                'birthday' => optional($birthday)->toDateString(),
                'birthday_display' => $birthday?->format('F j'),
                'age' => $age,
                'next_birthday_in_days' => $nextBirthdayInDays,
                'is_birthday_today' => $isBirthdayToday,
                'employment_type' => $employee->employment_type,
                'daily_rate' => $employee->daily_rate,
                'is_active' => (bool) $employee->is_active,
                'branch' => $employee->branch ? [
                    'id' => $employee->branch->id,
                    'name' => $employee->branch->name,
                ] : null,
                'tenure' => $tenure,
            ],
            'attendance_summary' => [
                'window_days' => 30,
                'counts' => $statusCounts,
                'hours_worked' => round($totalMinutes / 60, 1),
            ],
            'payroll_history' => $payrollHistory,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'employee_code' => ['nullable', 'string', 'max:30', 'unique:employees,employee_code'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:120', 'unique:employees,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'hire_date' => ['required', 'date'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'employment_type' => ['required', 'in:full_time,part_time,contract'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $nextId = (int) (Employee::max('id') ?? 0) + 1;
        $employeeCode = $validated['employee_code'] ?? 'EMP'.str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);

        Employee::create([
            'user_id' => null,
            'branch_id' => $validated['branch_id'],
            'employee_code' => $employeeCode,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'hire_date' => $validated['hire_date'],
            'birthday' => $validated['birthday'] ?? null,
            'employment_type' => $validated['employment_type'],
            'hourly_rate' => null,
            'daily_rate' => $validated['daily_rate'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Employee added successfully.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'employee_code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('employees', 'employee_code')->ignore($employee->id),
            ],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => [
                'nullable',
                'email',
                'max:120',
                Rule::unique('employees', 'email')->ignore($employee->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'hire_date' => ['required', 'date'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'employment_type' => ['required', 'in:full_time,part_time,contract'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $employee->update([
            'branch_id' => $validated['branch_id'],
            'employee_code' => $validated['employee_code'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'hire_date' => $validated['hire_date'],
            'birthday' => $validated['birthday'] ?? null,
            'employment_type' => $validated['employment_type'],
            'daily_rate' => $validated['daily_rate'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Employee updated successfully.');
    }

    public function toggleActive(Employee $employee): RedirectResponse
    {
        $employee->update(['is_active' => ! $employee->is_active]);

        $message = $employee->is_active
            ? 'Employee marked active.'
            : 'Employee marked inactive.';

        return back()->with('success', $message);
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $hasPayrollHistory = DB::table('payroll_entries')
            ->where('employee_id', $employee->id)
            ->exists();

        if ($hasPayrollHistory) {
            return back()->with('error', 'Cannot delete employee with payroll history.');
        }

        try {
            $employee->delete();
        } catch (QueryException) {
            return back()->with('error', 'Unable to delete employee due to related records.');
        }

        return back()->with('success', 'Employee deleted successfully.');
    }
}
