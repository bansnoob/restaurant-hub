<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $employees = Employee::query()
            ->with('branch')
            ->orderByDesc('id')
            ->paginate(20);

        return view('modules.employees.index', compact('branches', 'employees'));
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
            'employment_type' => $validated['employment_type'],
            'daily_rate' => $validated['daily_rate'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Employee updated successfully.');
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
