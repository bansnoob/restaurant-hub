<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Branches</h2>
    </x-slot>

    <div class="rh-page" x-data="{ editingId: null, showCreate: {{ $branches->isEmpty() ? 'true' : 'false' }} }">

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

        <div class="rh-emp-topbar">
            <div>
                <h1 class="rh-emp-title">Branches</h1>
                <p class="rh-emp-sub">{{ $branches->where('is_active', true)->count() }} active · {{ $branches->count() }} total</p>
            </div>
            <button type="button" class="rm-btn rm-btn--primary" @click="showCreate = !showCreate">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                <span x-text="showCreate ? 'Cancel' : 'Add Branch'"></span>
            </button>
        </div>

        @if ($branches->isEmpty())
            <div class="rh-empty-banner" style="margin-bottom:1.5rem;background:#fef3c7;border:1px solid #fbbf24;color:#78350f;padding:1rem 1.25rem;border-radius:0.75rem;">
                <strong>No branches yet.</strong> Create your first branch below — your owner account will be linked to it automatically so you can access the rest of the app.
            </div>
        @endif

        {{-- Create form --}}
        <div x-show="showCreate" x-collapse class="rh-card" style="padding:1.5rem;margin-bottom:1.5rem;">
            <h3 style="font-weight:600;margin-bottom:1rem;">New Branch</h3>
            <form method="POST" action="{{ route('branches.store') }}" class="rh-form-grid">
                @csrf
                <div>
                    <label class="rh-form-label">Code <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="code" required maxlength="32" placeholder="e.g. main"
                           value="{{ old('code') }}" class="rh-form-input">
                    <p class="rh-form-hint">Short identifier, lowercase. Used for URLs/SKUs.</p>
                </div>
                <div>
                    <label class="rh-form-label">Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" required maxlength="255" placeholder="Trio Bites"
                           value="{{ old('name') }}" class="rh-form-input">
                </div>
                <div>
                    <label class="rh-form-label">Phone</label>
                    <input type="text" name="phone" maxlength="32" value="{{ old('phone') }}" class="rh-form-input">
                </div>
                <div>
                    <label class="rh-form-label">Email</label>
                    <input type="email" name="email" maxlength="255" value="{{ old('email') }}" class="rh-form-input">
                </div>
                <div style="grid-column:1/-1;">
                    <label class="rh-form-label">Address</label>
                    <textarea name="address" rows="2" class="rh-form-input">{{ old('address') }}</textarea>
                </div>
                <div style="display:flex;align-items:center;gap:1.5rem;grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:0.5rem;">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>Active</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:0.5rem;">
                        <input type="checkbox" name="assign_to_me" value="1" {{ $branches->isEmpty() ? 'checked' : '' }}>
                        <span>Link my account to this branch</span>
                    </label>
                </div>
                <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:0.75rem;">
                    <button type="button" class="rm-btn" @click="showCreate = false">Cancel</button>
                    <button type="submit" class="rm-btn rm-btn--primary">Create Branch</button>
                </div>
            </form>
        </div>

        {{-- Branches list --}}
        @if ($branches->isNotEmpty())
            <div class="rh-card">
                <table class="rh-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f9fafb;text-align:left;">
                            <th style="padding:0.75rem 1rem;">Code</th>
                            <th style="padding:0.75rem 1rem;">Name</th>
                            <th style="padding:0.75rem 1rem;">Phone</th>
                            <th style="padding:0.75rem 1rem;">Status</th>
                            <th style="padding:0.75rem 1rem;text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($branches as $branch)
                            <tr style="border-top:1px solid #e5e7eb;">
                                <td style="padding:0.75rem 1rem;font-family:ui-monospace,monospace;">{{ $branch->code }}</td>
                                <td style="padding:0.75rem 1rem;font-weight:500;">
                                    {{ $branch->name }}
                                    @if ($branch->id === $myBranchId)
                                        <span style="display:inline-block;margin-left:0.5rem;padding:0.125rem 0.5rem;background:#dbeafe;color:#1e40af;border-radius:0.25rem;font-size:0.75rem;font-weight:600;">My branch</span>
                                    @endif
                                </td>
                                <td style="padding:0.75rem 1rem;color:#6b7280;">{{ $branch->phone ?? '—' }}</td>
                                <td style="padding:0.75rem 1rem;">
                                    @if ($branch->is_active)
                                        <span style="color:#059669;">Active</span>
                                    @else
                                        <span style="color:#9ca3af;">Inactive</span>
                                    @endif
                                </td>
                                <td style="padding:0.75rem 1rem;text-align:right;">
                                    @if ($branch->id !== $myBranchId)
                                        <form method="POST" action="{{ route('branches.assign-to-me', $branch) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="rm-btn" style="font-size:0.875rem;">Use this branch</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('branches.destroy', $branch) }}" style="display:inline;" onsubmit="return confirm('Delete branch &quot;{{ $branch->name }}&quot;? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rm-btn rm-btn--danger" style="font-size:0.875rem;">Delete</button>
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
        .rh-card { background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; }
        .rh-form-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:1rem; }
        .rh-form-label { display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem; }
        .rh-form-input { width:100%; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:0.375rem; }
        .rh-form-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
        .rh-form-hint { font-size:0.75rem; color:#6b7280; margin-top:0.25rem; }
        .rh-page { padding:1.5rem; }
        .rh-emp-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
        .rh-emp-title { font-size:1.5rem; font-weight:600; }
        .rh-emp-sub { color:#6b7280; font-size:0.875rem; }
        @media (max-width: 640px) { .rh-form-grid { grid-template-columns:1fr; } }
    </style>
</x-app-layout>
