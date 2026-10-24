<x-app-layout>
    @php
        $me = auth()->user();
        $usersPayload = $users->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->roles->pluck('name')->first(fn ($r) => array_key_exists($r, $roles)) ?? '',
            'branch_id' => $u->branch_id ? (string) $u->branch_id : '',
            'is_active' => (bool) $u->is_active,
            'is_me' => $u->is($me),
            'has_employee' => $u->employee !== null,
        ])->values();
    @endphp

    <div class="rh-usr-page" x-data="usersPage({
            storeUrl: @js(route('users.store')),
            updateUrlTemplate: @js(route('users.update', ['user' => '__USER__'])),
            passwordUrlTemplate: @js(route('users.password', ['user' => '__USER__'])),
            users: @js($usersPayload),
        })" @keydown.escape.window="closeAll()">

        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)"><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)"><span>{{ $errors->first() }}</span></div>
        @endif

        <div class="rm-topbar">
            <div>
                <h1 class="rm-page-title">Users</h1>
                <p class="rm-page-sub">{{ $users->where('is_active', true)->count() }} active · {{ $users->count() }} total</p>
            </div>
            <div class="rm-topbar-actions">
                <button type="button" class="rm-btn rm-btn--primary" @click="openCreate()">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 3v10M3 8h10"/></svg>
                    New User
                </button>
            </div>
        </div>

        <div class="rm-table-wrap">
            <table class="rm-table">
                <thead>
                    <tr>
                        <th class="rm-th">User</th>
                        <th class="rm-th">Role</th>
                        <th class="rm-th">Branch</th>
                        <th class="rm-th rm-th--center">Status</th>
                        <th class="rm-th rm-th--right"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        @php
                            $role = $user->roles->pluck('name')->first(fn ($r) => array_key_exists($r, $roles));
                        @endphp
                        <tr class="rm-row {{ $user->is_active ? '' : 'rm-row--off' }}">
                            <td class="rm-td">
                                <span class="rm-item-name">{{ $user->name }}@if ($user->is($me)) <span class="rh-usr-you">You</span>@endif</span>
                                <span class="rm-item-desc">{{ $user->email }}</span>
                            </td>
                            <td class="rm-td">
                                <span class="rh-usr-role rh-usr-role--{{ $role ?? 'none' }}">{{ $role ? $roles[$role] : 'No role' }}</span>
                            </td>
                            <td class="rm-td">
                                @if ($role === 'owner')
                                    <span class="rh-usr-muted">All branches</span>
                                @elseif ($user->branch)
                                    {{ $user->branch->name }}
                                @elseif ($user->employee?->branch)
                                    {{ $user->employee->branch->name }} <span class="rh-usr-muted">· follows employee</span>
                                @else
                                    <span class="rh-usr-warn">No branch</span>
                                @endif
                            </td>
                            <td class="rm-td rm-td--center">
                                <span class="rm-status-dot {{ $user->is_active ? 'rm-status-dot--on' : 'rm-status-dot--off' }}"></span>
                                <span class="rm-status-label">{{ $user->is_active ? 'Active' : 'Deactivated' }}</span>
                            </td>
                            <td class="rm-td rm-td--right rh-usr-actions">
                                <button type="button" class="rm-btn rm-btn--ghost" @click="openEdit({{ $user->id }})">Edit</button>
                                <button type="button" class="rm-btn rm-btn--ghost" @click="openPassword({{ $user->id }})">Set password</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Create / edit --}}
        <template x-if="formOpen">
            <div class="rm-overlay" @click.self="closeAll()">
                <form method="POST" :action="form.mode === 'edit' ? form.action : storeUrl" class="rm-drawer" @submit="submitting = true">
                    @csrf
                    <template x-if="form.mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="form.mode === 'edit' ? 'Edit User' : 'New User'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeAll()">&times;</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field">
                            <label class="rm-field-label">Name</label>
                            <input type="text" name="name" class="rm-input" required maxlength="255" x-model="form.name" autocomplete="off">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Email</label>
                            <input type="email" name="email" class="rm-input" required maxlength="255" x-model="form.email" autocomplete="off">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Role</label>
                            <select name="role" class="rm-input" required x-model="form.role" :disabled="form.is_me">
                                @foreach ($roles as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            {{-- A disabled select is not submitted; your own role stays as it is. --}}
                            <template x-if="form.is_me"><input type="hidden" name="role" :value="form.role"></template>
                            <p class="rh-usr-hint" x-text="roleHint()"></p>
                        </div>
                        <div class="rm-field" x-show="form.role !== 'owner'">
                            <label class="rm-field-label">Branch</label>
                            <select name="branch_id" class="rm-input" x-model="form.branch_id" :required="form.role !== 'owner' && !(form.role === 'cashier' && form.has_employee)">
                                <option value="" x-text="form.role === 'cashier' && form.has_employee ? 'Follow their employee record' : 'Select branch'"></option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <template x-if="form.mode === 'create'">
                            <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                                <div class="rm-field">
                                    <label class="rm-field-label">Password</label>
                                    <input type="password" name="password" class="rm-input" required minlength="8" autocomplete="new-password">
                                </div>
                                <div class="rm-field">
                                    <label class="rm-field-label">Confirm</label>
                                    <input type="password" name="password_confirmation" class="rm-input" required minlength="8" autocomplete="new-password">
                                </div>
                            </div>
                        </template>
                        <template x-if="form.mode === 'edit'">
                            <div class="rm-field">
                                <label class="rm-toggle-wrap">
                                    <input type="hidden" name="is_active" :value="form.is_active ? '1' : '0'">
                                    <input type="checkbox" class="rm-toggle" x-model="form.is_active" :disabled="form.is_me">
                                    <span class="rm-toggle-label" x-text="form.is_active ? 'Active' : 'Deactivated: cannot sign in'"></span>
                                </label>
                            </div>
                        </template>
                    </div>
                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--ghost" @click="closeAll()">Cancel</button>
                        <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting" x-text="form.mode === 'edit' ? 'Save Changes' : 'Create User'"></button>
                    </div>
                </form>
            </div>
        </template>

        {{-- Set password --}}
        <template x-if="passwordOpen">
            <div class="rm-overlay" @click.self="closeAll()">
                <form method="POST" :action="passwordAction" class="rm-drawer" @submit="submitting = true">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="'Set password · ' + passwordName"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeAll()">&times;</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field">
                            <label class="rm-field-label">New password</label>
                            <input type="password" name="password" class="rm-input" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Confirm</label>
                            <input type="password" name="password_confirmation" class="rm-input" required minlength="8" autocomplete="new-password">
                        </div>
                        <p class="rh-usr-hint">Their iPad will sign out; they sign back in with the new password.</p>
                    </div>
                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--ghost" @click="closeAll()">Cancel</button>
                        <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">Set Password</button>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <style>
        .rh-usr-page { padding: 1.5rem; }
        .rh-usr-actions { white-space: nowrap; }
        .rh-usr-actions .rm-btn + .rm-btn { margin-left: 0.4rem; }
        .rh-usr-you {
            margin-left: 0.4rem; padding: 0.05rem 0.45rem; border-radius: 999px;
            font-size: 0.65rem; font-weight: 600; vertical-align: middle;
            border: 1px solid var(--rh-border-2); color: var(--rh-text-muted);
        }
        .rh-usr-role {
            display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px;
            font-size: 0.72rem; font-weight: 600; border: 1px solid var(--rh-border-2); color: var(--rh-text);
        }
        .rh-usr-role--owner { color: var(--rh-amber-text); border-color: currentColor; }
        .rh-usr-role--admin { color: var(--rh-success-text); border-color: currentColor; }
        .rh-usr-role--none { color: var(--rh-error-text); border-color: currentColor; }
        .rh-usr-muted { color: var(--rh-text-muted); font-size: 0.8rem; }
        .rh-usr-warn { color: var(--rh-error-text); font-size: 0.8rem; }
        .rh-usr-hint { margin: 0.35rem 0 0; font-size: 0.72rem; color: var(--rh-text-muted); }
    </style>

    <script>
        function usersPage(config) {
            const blank = () => ({ mode: 'create', action: '', name: '', email: '', role: 'admin', branch_id: '', is_active: true, is_me: false, has_employee: false });
            return {
                storeUrl: config.storeUrl,
                users: config.users,
                formOpen: false,
                passwordOpen: false,
                submitting: false,
                form: blank(),
                passwordAction: '',
                passwordName: '',
                openCreate() {
                    this.form = blank();
                    this.submitting = false;
                    this.formOpen = true;
                },
                openEdit(id) {
                    const user = this.users.find(u => u.id === id);
                    if (!user) return;
                    this.form = { ...blank(), ...user, mode: 'edit', action: config.updateUrlTemplate.replace('__USER__', id) };
                    this.submitting = false;
                    this.formOpen = true;
                },
                openPassword(id) {
                    const user = this.users.find(u => u.id === id);
                    if (!user) return;
                    this.passwordAction = config.passwordUrlTemplate.replace('__USER__', id);
                    this.passwordName = user.name;
                    this.submitting = false;
                    this.passwordOpen = true;
                },
                closeAll() {
                    this.formOpen = false;
                    this.passwordOpen = false;
                },
                roleHint() {
                    if (this.form.is_me) return 'You cannot change your own role or deactivate yourself.';
                    return {
                        owner: 'Every branch and every tab, including Branches and Users.',
                        admin: 'Every tab for one branch, with no branch picker. Can use the iPad POS.',
                        cashier: 'The iPad POS only, at one branch.',
                    }[this.form.role] || '';
                },
            };
        }
    </script>
</x-app-layout>
