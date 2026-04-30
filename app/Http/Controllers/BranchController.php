<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        $branches = Branch::orderBy('name')->get();
        $myBranchId = Auth::user()?->resolveBranchId();

        return view('modules.branches.index', compact('branches', 'myBranchId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'assign_to_me' => ['nullable', 'boolean'],
        ]);

        $branch = Branch::create([
            'code' => Str::lower($validated['code']),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        if (! empty($validated['assign_to_me'])) {
            $user = $request->user();
            $user->branch_id = $branch->id;
            $user->save();
        }

        return redirect()->route('branches.index')->with('success', "Branch \"{$branch->name}\" created.");
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('branches', 'code')->ignore($branch->id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch->update([
            'code' => Str::lower($validated['code']),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('branches.index')->with('success', "Branch \"{$branch->name}\" updated.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $employeeCount = Employee::where('branch_id', $branch->id)->count();
        $userCount = User::where('branch_id', $branch->id)->count();

        if ($employeeCount > 0 || $userCount > 0) {
            return redirect()->route('branches.index')->with(
                'error',
                "Cannot delete \"{$branch->name}\": linked to {$employeeCount} employee(s) and {$userCount} user(s)."
            );
        }

        $name = $branch->name;
        $branch->delete();

        return redirect()->route('branches.index')->with('success', "Branch \"{$name}\" deleted.");
    }

    public function assignToMe(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        $user->branch_id = $branch->id;
        $user->save();

        return redirect()->route('branches.index')->with('success', "You are now linked to \"{$branch->name}\".");
    }
}
