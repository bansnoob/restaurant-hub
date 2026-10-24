<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The Users page: the super admin manages every account (super admins, branch
 * admins, cashiers). Accounts are deactivated, never deleted, so the closed days,
 * sales and expenses they recorded keep pointing at them.
 */
class UserController extends Controller
{
    /** Role name => how it reads on screen. `owner` is the super admin. */
    public const ROLES = [
        'owner' => 'Super Admin',
        'admin' => 'Admin',
        'cashier' => 'Cashier',
    ];

    public function index(): View
    {
        $users = User::with(['roles', 'branch:id,name', 'employee.branch:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('modules.users.index', [
            'users' => $users,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules() + [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], $this->messages());
        $this->requireBranchUnlessSuperAdmin($validated);

        DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'branch_id' => $validated['branch_id'] ?? null,
                'is_active' => true,
            ]);
            $user->syncRoles([$validated['role']]);
        });

        return redirect()->route('users.index')->with('success', "Account created for {$validated['name']}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate($this->rules() + [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'is_active' => ['required', 'boolean'],
        ], $this->messages());
        $isActive = (bool) $validated['is_active'];

        // Also what keeps at least one active super admin: only an active super
        // admin can be here, and they cannot remove themselves.
        if ($user->is($request->user()) && (! $isActive || $validated['role'] !== 'owner')) {
            return back()->with('error', 'You cannot deactivate or demote your own account.');
        }

        // A cashier made from an employee has no branch of their own: it follows the
        // employee. Leaving the branch blank keeps it that way.
        if (! ($validated['role'] === 'cashier' && empty($validated['branch_id']) && $user->employee)) {
            $this->requireBranchUnlessSuperAdmin($validated);
        }

        DB::transaction(function () use ($user, $validated, $isActive) {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'branch_id' => $validated['branch_id'] ?? null,
                'is_active' => $isActive,
            ]);
            $user->syncRoles([$validated['role']]);

            // Deactivated: sign the iPad out now. The website session ends on its
            // next request (EnsureUserIsActive).
            if (! $isActive) {
                $user->tokens()->delete();
            }
        });

        return redirect()->route('users.index')->with('success', "{$validated['name']} updated.");
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $validated['password']]);
        // The old password's iPad sign-ins end; they sign in again with the new one.
        $user->tokens()->delete();

        return redirect()->route('users.index')->with('success', "New password set for {$user->name}.");
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['role.in' => 'Choose Super Admin, Admin or Cashier.'];
    }

    /** A branch admin or a cashier works one branch; the super admin works them all. */
    private function requireBranchUnlessSuperAdmin(array $validated): void
    {
        if ($validated['role'] !== 'owner' && empty($validated['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => 'An Admin or Cashier needs a branch.',
            ]);
        }
    }
}
