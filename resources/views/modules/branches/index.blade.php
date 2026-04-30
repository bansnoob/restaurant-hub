<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Branches</h2>
    </x-slot>

    <div class="rh-br-page" x-data="{ showCreate: {{ $branches->isEmpty() ? 'true' : 'false' }} }">

        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)">
                <span>{{ session('success') }}</span>
            </div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)">
                <span>{{ session('error') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)">
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <div class="rh-br-topbar">
            <div>
                <h1 class="rh-br-title">Branches</h1>
                <p class="rh-br-sub">{{ $branches->where('is_active', true)->count() }} active · {{ $branches->count() }} total</p>
            </div>
            <button type="button" class="rm-btn rm-btn--primary" @click="showCreate = !showCreate">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                <span x-text="showCreate ? 'Cancel' : 'Add Branch'"></span>
            </button>
        </div>

        @if ($branches->isEmpty())
            <div class="rh-br-empty">
                <strong>No branches yet.</strong>
                <span>Create your first branch — your owner account will be linked automatically so you can access the rest of the app.</span>
            </div>
        @endif

        <div x-show="showCreate" x-collapse class="rh-br-card rh-br-form-card">
            <h3 class="rh-br-form-title">New Branch</h3>
            <form method="POST" action="{{ route('branches.store') }}" class="rh-br-form-grid">
                @csrf
                <div>
                    <label class="rh-br-label">Code <span class="rh-br-required">*</span></label>
                    <input type="text" name="code" required maxlength="32" placeholder="e.g. main"
                           value="{{ old('code') }}" class="rh-br-input">
                    <p class="rh-br-hint">Short identifier, lowercase. Used for URLs and SKUs.</p>
                </div>
                <div>
                    <label class="rh-br-label">Name <span class="rh-br-required">*</span></label>
                    <input type="text" name="name" required maxlength="255" placeholder="Trio Bites"
                           value="{{ old('name') }}" class="rh-br-input">
                </div>
                <div>
                    <label class="rh-br-label">Phone</label>
                    <input type="text" name="phone" maxlength="32" value="{{ old('phone') }}" class="rh-br-input">
                </div>
                <div>
                    <label class="rh-br-label">Email</label>
                    <input type="email" name="email" maxlength="255" value="{{ old('email') }}" class="rh-br-input">
                </div>
                <div class="rh-br-form-full">
                    <label class="rh-br-label">Address</label>
                    <textarea name="address" rows="2" class="rh-br-input">{{ old('address') }}</textarea>
                </div>
                <div class="rh-br-form-full rh-br-checks">
                    <label class="rh-br-check">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>Active</span>
                    </label>
                    <label class="rh-br-check">
                        <input type="checkbox" name="assign_to_me" value="1" {{ $branches->isEmpty() ? 'checked' : '' }}>
                        <span>Link my account to this branch</span>
                    </label>
                </div>
                <div class="rh-br-form-full rh-br-actions">
                    <button type="button" class="rm-btn rm-btn--ghost" @click="showCreate = false">Cancel</button>
                    <button type="submit" class="rm-btn rm-btn--primary">Create Branch</button>
                </div>
            </form>
        </div>

        @if ($branches->isNotEmpty())
            <div class="rh-br-card">
                <table class="rh-br-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th class="rh-br-th-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($branches as $branch)
                            <tr>
                                <td class="rh-br-code">{{ $branch->code }}</td>
                                <td>
                                    <span class="rh-br-name">{{ $branch->name }}</span>
                                    @if ($branch->id === $myBranchId)
                                        <span class="rh-br-pill">My branch</span>
                                    @endif
                                </td>
                                <td class="rh-br-muted">{{ $branch->phone ?? '—' }}</td>
                                <td>
                                    @if ($branch->is_active)
                                        <span class="rh-br-status rh-br-status--on">Active</span>
                                    @else
                                        <span class="rh-br-status rh-br-status--off">Inactive</span>
                                    @endif
                                </td>
                                <td class="rh-br-td-right">
                                    @if ($branch->id !== $myBranchId)
                                        <form method="POST" action="{{ route('branches.assign-to-me', $branch) }}" class="rh-br-inline-form">
                                            @csrf
                                            <button type="submit" class="rm-btn rm-btn--ghost">Use this branch</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('branches.destroy', $branch) }}" class="rh-br-inline-form" onsubmit="return confirm('Delete branch &quot;{{ $branch->name }}&quot;? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rm-btn rm-btn--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <style>
        .rh-br-page { padding: 1.5rem; }

        .rh-br-topbar {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 1.5rem;
        }
        .rh-br-title {
            font-family: var(--rh-font-serif);
            font-size: 1.875rem; font-weight: 600;
            color: var(--rh-text);
            line-height: 1.1;
        }
        .rh-br-sub {
            color: var(--rh-text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .rh-br-empty {
            background: var(--rh-amber-bg);
            border: 1px solid var(--rh-amber-border);
            color: var(--rh-amber-text);
            padding: 0.85rem 1.25rem;
            border-radius: 0.6rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .rh-br-card {
            background: var(--rh-surface);
            border: 1px solid var(--rh-border);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .rh-br-form-card { padding: 1.5rem; margin-bottom: 1.5rem; }
        .rh-br-form-title {
            font-weight: 600;
            color: var(--rh-text);
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        .rh-br-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .rh-br-form-full { grid-column: 1 / -1; }

        .rh-br-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--rh-text-muted);
            margin-bottom: 0.35rem;
            letter-spacing: 0.02em;
        }
        .rh-br-required { color: var(--rh-error-text); }
        .rh-br-hint {
            font-size: 0.75rem;
            color: var(--rh-text-muted);
            margin-top: 0.35rem;
        }

        .rh-br-input {
            width: 100%;
            padding: 0.55rem 0.75rem;
            background: var(--rh-bg);
            border: 1px solid var(--rh-border);
            border-radius: 0.5rem;
            color: var(--rh-text);
            font-family: var(--rh-font-sans);
            font-size: 0.9rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .rh-br-input::placeholder { color: var(--rh-placeholder); }
        .rh-br-input:focus {
            outline: none;
            border-color: var(--rh-accent);
            box-shadow: 0 0 0 3px var(--rh-accent-glow);
        }

        .rh-br-checks {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .rh-br-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--rh-text);
            font-size: 0.875rem;
            cursor: pointer;
        }
        .rh-br-check input { accent-color: var(--rh-accent); }

        .rh-br-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
        }

        .rh-br-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .rh-br-table thead th {
            background: var(--rh-surface-2);
            color: var(--rh-text-muted);
            text-align: left;
            padding: 0.65rem 1rem;
            font-weight: 500;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--rh-border);
        }
        .rh-br-table tbody td {
            padding: 0.85rem 1rem;
            border-top: 1px solid var(--rh-border);
            color: var(--rh-text);
            vertical-align: middle;
        }
        .rh-br-table tbody tr:hover td { background: var(--rh-surface-2); }
        .rh-br-th-right, .rh-br-td-right { text-align: right; }
        .rh-br-td-right { white-space: nowrap; }

        .rh-br-code {
            font-family: var(--rh-font-mono);
            color: var(--rh-text-muted);
            font-size: 0.8125rem;
        }
        .rh-br-name { font-weight: 500; }
        .rh-br-muted { color: var(--rh-text-muted); }

        .rh-br-pill {
            display: inline-block;
            margin-left: 0.5rem;
            padding: 0.15rem 0.55rem;
            background: var(--rh-accent-dim);
            color: var(--rh-accent);
            border: 1px solid var(--rh-accent-border);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .rh-br-status {
            font-size: 0.8125rem;
            font-weight: 500;
        }
        .rh-br-status--on { color: var(--rh-success-text); }
        .rh-br-status--off { color: var(--rh-text-muted); }

        .rh-br-inline-form {
            display: inline-block;
            margin-left: 0.4rem;
        }

        @media (max-width: 640px) {
            .rh-br-form-grid { grid-template-columns: 1fr; }
            .rh-br-topbar { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .rh-br-table thead { display: none; }
            .rh-br-table tbody td { display: block; padding: 0.5rem 1rem; border-top: none; }
            .rh-br-table tbody tr { display: block; padding: 0.5rem 0; border-top: 1px solid var(--rh-border); }
        }
    </style>
</x-app-layout>
