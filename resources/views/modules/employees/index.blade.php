<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Employees</h2>
    </x-slot>

    @php
        $isOwner = (bool) auth()->user()?->hasRole('owner');
        $allBranchesUrl = route('employees.index');
        $typeLabels = ['full_time' => 'Full-Time', 'part_time' => 'Part-Time', 'contract' => 'Contract'];
        $typeCounts = [
            'full_time' => $stats['full_time'] ?? 0,
            'part_time' => $stats['part_time'] ?? 0,
            'contract' => $stats['contract'] ?? 0,
        ];
        $hasFilters = $filters['search'] !== '' || ! empty($filters['branch_id']) || ! empty($filters['types']) || $filters['active'] !== 'all';
    @endphp

    <div
        class="rh-emp-page"
        x-data="employeesPage({
            updateUrlTemplate: @js(route('employees.update', ['employee' => '__EMPLOYEE__'])),
            detailUrlTemplate: @js(route('employees.show', ['employee' => '__EMPLOYEE__'])),
            isOwner: {{ $isOwner ? 'true' : 'false' }},
            initialFilters: @js($filters),
        })"
        @keydown.escape.window="closeAll()"
    >
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

        {{-- Top bar --}}
        <div class="rh-emp-topbar">
            <div>
                <h1 class="rh-emp-title">Employees</h1>
                <p class="rh-emp-sub">{{ $stats['active'] }} active · {{ $stats['total'] }} total</p>
            </div>
            @if ($isOwner)
                <button type="button" class="rm-btn rm-btn--primary" @click="openCreate()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    Add Employee
                </button>
            @endif
        </div>

        {{-- Stats strip --}}
        <div class="rh-emp-stats">
            <div class="rh-emp-stat" style="--i: 1;">
                <p class="rh-emp-stat-label">Total</p>
                <p class="rh-emp-stat-value">{{ $stats['total'] }}</p>
                <p class="rh-emp-stat-sub">All employees</p>
            </div>
            <div class="rh-emp-stat" style="--i: 2;">
                <p class="rh-emp-stat-label">Active</p>
                <p class="rh-emp-stat-value rh-emp-stat-value--accent">{{ $stats['active'] }}</p>
                <p class="rh-emp-stat-sub">{{ $stats['inactive'] }} inactive</p>
            </div>
            <div class="rh-emp-stat" style="--i: 3;">
                <p class="rh-emp-stat-label">Full-Time</p>
                <p class="rh-emp-stat-value">{{ $stats['full_time'] }}</p>
                <p class="rh-emp-stat-sub">Active only</p>
            </div>
            <div class="rh-emp-stat" style="--i: 4;">
                <p class="rh-emp-stat-label">Part-Time / Contract</p>
                <p class="rh-emp-stat-value">{{ $stats['part_time'] + $stats['contract'] }}</p>
                <p class="rh-emp-stat-sub">{{ $stats['part_time'] }} PT · {{ $stats['contract'] }} contract</p>
            </div>
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('employees.index') }}" class="rh-emp-toolbar" x-ref="filterForm">
            <div class="rh-emp-toolbar-row">
                <label class="rh-emp-search">
                    <svg class="rh-emp-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Search by name, code, or email…"
                        x-ref="searchInput"
                        x-on:input.debounce.350ms="$refs.filterForm.requestSubmit()"
                    >
                </label>

                <select name="branch_id" class="rh-emp-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>

                <div class="rh-emp-chips">
                    @foreach ($typeLabels as $key => $label)
                        @php $on = in_array($key, $filters['types'], true); @endphp
                        <label class="rh-emp-chip {{ $on ? 'rh-emp-chip--on' : '' }}">
                            <input type="checkbox" name="types[]" value="{{ $key }}" {{ $on ? 'checked' : '' }} hidden @change="$refs.filterForm.requestSubmit()">
                            <span>{{ $label }}</span>
                            <span class="rh-emp-chip-count">{{ $typeCounts[$key] }}</span>
                        </label>
                    @endforeach
                </div>

                @php
                    $activeOptions = [
                        'all' => ['label' => 'All', 'class' => ''],
                        'active' => ['label' => 'Active', 'class' => 'rh-emp-active-toggle--on'],
                        'inactive' => ['label' => 'Inactive', 'class' => 'rh-emp-active-toggle--off'],
                    ];
                    $current = $activeOptions[$filters['active']] ?? $activeOptions['all'];
                    $next = match ($filters['active']) {
                        'all' => 'active',
                        'active' => 'inactive',
                        default => 'all',
                    };
                @endphp
                <input type="hidden" name="active" :value="activeFilter">
                <button type="button" class="rh-emp-active-toggle {{ $current['class'] }}" @click="cycleActive()">
                    <span x-text="activeLabel()">{{ $current['label'] }}</span>
                </button>

                @if ($hasFilters)
                    <a href="{{ $allBranchesUrl }}" class="rh-emp-clear">Clear filters</a>
                @endif
            </div>
        </form>

        {{-- Employee list --}}
        @if ($employees->isEmpty())
            <div class="rh-emp-list">
                <div class="rh-emp-empty">
                    <svg class="rh-emp-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21v-1a8 8 0 0 1 16 0v1"/>
                    </svg>
                    <p class="rh-emp-empty-title">No employees found</p>
                    <p class="rh-emp-empty-sub">
                        @if ($hasFilters)
                            Try clearing filters or adjusting your search.
                        @else
                            Add your first employee to get started.
                        @endif
                    </p>
                </div>
            </div>
        @else
            <div class="rh-emp-list">
                @foreach ($employees as $employee)
                    @php
                        $initials = strtoupper(substr($employee->first_name ?? '?', 0, 1) . substr($employee->last_name ?? '?', 0, 1));
                        $colorIdx = (($employee->branch_id ?? 0) % 6) + 1;
                        $typeKey = $employee->employment_type;
                        $typeClass = match ($typeKey) {
                            'full_time' => 'rh-emp-type--full',
                            'part_time' => 'rh-emp-type--part',
                            'contract' => 'rh-emp-type--contract',
                            default => '',
                        };
                        $typeShort = match ($typeKey) {
                            'full_time' => 'Full',
                            'part_time' => 'Part',
                            'contract' => 'Contract',
                            default => $typeKey,
                        };
                        $hireDate = $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date) : null;
                        $tenureLabel = '—';
                        if ($hireDate) {
                            $diff = $hireDate->diff(now());
                            if ($diff->y > 0) {
                                $tenureLabel = $diff->y . 'y ' . ($diff->m > 0 ? $diff->m . 'mo' : '');
                            } elseif ($diff->m > 0) {
                                $tenureLabel = $diff->m . 'mo';
                            } else {
                                $tenureLabel = max(1, $diff->d) . 'd';
                            }
                            $tenureLabel = trim($tenureLabel);
                        }
                        $employeePayload = [
                            'id' => $employee->id,
                            'branch_id' => $employee->branch_id,
                            'employee_code' => $employee->employee_code,
                            'first_name' => $employee->first_name,
                            'last_name' => $employee->last_name,
                            'email' => $employee->email,
                            'phone' => $employee->phone,
                            'hire_date' => optional($employee->hire_date)->toDateString(),
                            'birthday' => optional($employee->birthday)->toDateString(),
                            'employment_type' => $employee->employment_type,
                            'daily_rate' => $employee->daily_rate,
                            'is_active' => (bool) $employee->is_active,
                        ];
                    @endphp
                    <div
                        class="rh-emp-row {{ $employee->is_active ? '' : 'rh-emp-row--inactive' }}"
                        role="button"
                        tabindex="0"
                        @click="openDetail({{ $employee->id }})"
                        @keydown.enter="openDetail({{ $employee->id }})"
                        @keydown.space.prevent="openDetail({{ $employee->id }})"
                    >
                        <span class="rh-emp-avatar rh-emp-avatar--c{{ $colorIdx }}">{{ $initials }}</span>
                        <span class="rh-emp-name-block">
                            <span class="rh-emp-name">{{ $employee->first_name }} {{ $employee->last_name }}</span>
                            <span class="rh-emp-meta">{{ $employee->employee_code }} · {{ $tenureLabel }}</span>
                        </span>
                        <span class="rh-emp-type {{ $typeClass }}">{{ $typeShort }}</span>
                        <span class="rh-emp-branch">{{ $employee->branch?->name ?? '—' }}</span>
                        <span>
                            @if ($employee->daily_rate !== null)
                                <span class="rh-emp-rate">
                                    ₱{{ number_format((float) $employee->daily_rate, 0) }}
                                    <span class="rh-emp-rate-unit">per day</span>
                                </span>
                            @else
                                <span class="rh-emp-rate rh-emp-rate--missing">No rate</span>
                            @endif
                        </span>
                        <span class="rh-emp-status {{ $employee->is_active ? 'rh-emp-status--active' : 'rh-emp-status--inactive' }}">
                            <span class="rh-emp-status-dot"></span>
                            {{ $employee->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        @if ($isOwner)
                            <span class="rh-emp-actions" @click.stop x-data="{ open: false }" @click.outside="open = false" @keydown.enter.stop @keydown.space.stop>
                                <button type="button" class="rh-emp-kebab" @click.stop="open = !open" aria-label="Actions">
                                    <svg fill="currentColor" viewBox="0 0 24 24">
                                        <circle cx="5" cy="12" r="1.6"/>
                                        <circle cx="12" cy="12" r="1.6"/>
                                        <circle cx="19" cy="12" r="1.6"/>
                                    </svg>
                                </button>
                                <div class="rh-emp-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                    <button type="button" @click="open = false; openEdit(@js($employeePayload))">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-9 1 8.5-8.5a1.41 1.41 0 0 1 2 2L13 15l-3 .5.5-3Z"/>
                                        </svg>
                                        Edit
                                    </button>
                                    <form method="POST" action="{{ route('employees.toggle-active', $employee) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="9"/>
                                                <path stroke-linecap="round" d="M12 7v5l3 2"/>
                                            </svg>
                                            {{ $employee->is_active ? 'Set inactive' : 'Set active' }}
                                        </button>
                                    </form>
                                    <hr>
                                    <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Delete this employee record?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="is-danger">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="rh-emp-pagination">{{ $employees->links() }}</div>
        @endif

        {{-- Detail drawer --}}
        <template x-if="detailOpen">
            <div class="rm-overlay" @click.self="closeDetail()">
                <div class="rm-drawer rm-drawer--wide">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="detailData ? (detailData.employee.first_name + ' ' + detailData.employee.last_name) : 'Loading…'"></h2>
                            <p class="rm-page-sub" x-show="detailData" x-text="detailData ? (detailData.employee.employee_code + ' · ' + (detailData.employee.branch ? detailData.employee.branch.name : '—')) : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDetail()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="!detailData">
                            <div class="rh-emp-detail-loading">Loading…</div>
                        </template>
                        <template x-if="detailData">
                            <div>
                                {{-- Contact --}}
                                <div class="rh-emp-detail-section">
                                    <p class="rh-emp-detail-label">Contact</p>
                                    <div class="rh-emp-detail-grid">
                                        <div>
                                            <span class="rh-emp-detail-item-label">Email</span>
                                            <span class="rh-emp-detail-item-value" :class="{'rh-emp-detail-item-value--muted': !detailData.employee.email}" x-text="detailData.employee.email || '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Phone</span>
                                            <span class="rh-emp-detail-item-value" :class="{'rh-emp-detail-item-value--muted': !detailData.employee.phone}" x-text="detailData.employee.phone || '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Birthday</span>
                                            <span class="rh-emp-detail-item-value" :class="{'rh-emp-detail-item-value--muted': !detailData.employee.birthday}">
                                                <span x-text="formatBirthday(detailData.employee)"></span>
                                                <template x-if="detailData.employee.is_birthday_today">
                                                    <span class="rh-emp-bday-pill rh-emp-bday-pill--today">🎂 Today</span>
                                                </template>
                                                <template x-if="!detailData.employee.is_birthday_today && detailData.employee.next_birthday_in_days !== null && detailData.employee.next_birthday_in_days <= 30">
                                                    <span class="rh-emp-bday-pill" x-text="'in ' + detailData.employee.next_birthday_in_days + 'd'"></span>
                                                </template>
                                            </span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Age</span>
                                            <span class="rh-emp-detail-item-value" :class="{'rh-emp-detail-item-value--muted': detailData.employee.age === null}" x-text="detailData.employee.age !== null ? detailData.employee.age : '—'"></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Employment --}}
                                <div class="rh-emp-detail-section">
                                    <p class="rh-emp-detail-label">Employment</p>
                                    <div class="rh-emp-detail-grid">
                                        <div>
                                            <span class="rh-emp-detail-item-label">Type</span>
                                            <span class="rh-emp-detail-item-value" x-text="formatType(detailData.employee.employment_type)"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Status</span>
                                            <span class="rh-emp-detail-item-value" :class="detailData.employee.is_active ? '' : 'rh-emp-detail-item-value--muted'" x-text="detailData.employee.is_active ? 'Active' : 'Inactive'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Hire Date</span>
                                            <span class="rh-emp-detail-item-value" x-text="formatDate(detailData.employee.hire_date)"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Tenure</span>
                                            <span class="rh-emp-detail-item-value" x-text="formatTenure(detailData.employee.tenure)"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Daily Rate</span>
                                            <span class="rh-emp-detail-item-value rh-emp-detail-item-value--num" x-text="detailData.employee.daily_rate ? '₱' + Number(detailData.employee.daily_rate).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-emp-detail-item-label">Branch</span>
                                            <span class="rh-emp-detail-item-value" x-text="detailData.employee.branch ? detailData.employee.branch.name : '—'"></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Attendance summary --}}
                                <div class="rh-emp-detail-section">
                                    <p class="rh-emp-detail-label">Attendance · Last 30 Days</p>
                                    <div class="rh-emp-att-grid">
                                        <div class="rh-emp-att-cell">
                                            <span class="rh-emp-att-num rh-emp-att-num--present" x-text="detailData.attendance_summary.counts.present"></span>
                                            <span class="rh-emp-att-label">Present</span>
                                        </div>
                                        <div class="rh-emp-att-cell">
                                            <span class="rh-emp-att-num rh-emp-att-num--late" x-text="detailData.attendance_summary.counts.late"></span>
                                            <span class="rh-emp-att-label">Late</span>
                                        </div>
                                        <div class="rh-emp-att-cell">
                                            <span class="rh-emp-att-num rh-emp-att-num--absent" x-text="detailData.attendance_summary.counts.absent"></span>
                                            <span class="rh-emp-att-label">Absent</span>
                                        </div>
                                        <div class="rh-emp-att-cell">
                                            <span class="rh-emp-att-num" x-text="detailData.attendance_summary.counts.leave + detailData.attendance_summary.counts.holiday"></span>
                                            <span class="rh-emp-att-label">Leave / Holiday</span>
                                        </div>
                                    </div>
                                    <div class="rh-emp-att-hours">
                                        <span>Hours worked</span>
                                        <strong x-text="detailData.attendance_summary.hours_worked + 'h'"></strong>
                                    </div>
                                </div>

                                {{-- Payroll history --}}
                                <div class="rh-emp-detail-section">
                                    <p class="rh-emp-detail-label">Recent Payroll</p>
                                    <template x-if="detailData.payroll_history.length === 0">
                                        <p class="rh-emp-detail-empty">No payroll history yet.</p>
                                    </template>
                                    <template x-for="entry in detailData.payroll_history" :key="entry.id">
                                        <div class="rh-emp-pay-row">
                                            <span class="rh-emp-pay-period" x-text="formatDate(entry.start_date) + ' – ' + formatDate(entry.end_date)"></span>
                                            <div>
                                                <span class="rh-emp-pay-net" x-text="'₱' + Number(entry.net_pay).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                                <span class="rh-emp-pay-status" :class="entry.status === 'paid' ? 'rh-emp-pay-status--paid' : 'rh-emp-pay-status--draft'" x-text="entry.status"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    @if ($isOwner)
                        <div class="rm-drawer-foot">
                            <div></div>
                            <div class="rm-drawer-foot-right">
                                <button type="button" class="rm-btn rm-btn--ghost" @click="closeDetail()">Close</button>
                                <button type="button" class="rm-btn rm-btn--primary" x-show="detailData" @click="editFromDetail()">Edit</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </template>

        {{-- Add/Edit drawer (owner + cashier can add; only owner can edit) --}}
        <template x-if="formOpen">
            <div class="rm-overlay" @click.self="closeForm()">
                <form
                    method="POST"
                    :action="form.mode === 'edit' ? form.action : '{{ route('employees.store') }}'"
                    class="rm-drawer rm-drawer--wide"
                    @submit="submitting = true"
                >
                    @csrf
                    <template x-if="form.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="form.mode === 'edit' ? 'Edit Employee' : 'Add Employee'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeForm()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field">
                            <label class="rm-field-label">Branch</label>
                            <select name="branch_id" class="rm-input" x-model="form.branch_id" required>
                                <option value="">Select branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rm-field-row">
                            <div class="rm-field">
                                <label class="rm-field-label">Code <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="employee_code" class="rm-input" x-model="form.employee_code" placeholder="Auto" :required="form.mode === 'edit'">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Hire Date</label>
                                <input type="date" name="hire_date" class="rm-input" x-model="form.hire_date" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Type</label>
                                <select name="employment_type" class="rm-input" x-model="form.employment_type" required>
                                    <option value="full_time">Full Time</option>
                                    <option value="part_time">Part Time</option>
                                    <option value="contract">Contract</option>
                                </select>
                            </div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">First Name</label>
                                <input type="text" name="first_name" class="rm-input" x-model="form.first_name" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Last Name</label>
                                <input type="text" name="last_name" class="rm-input" x-model="form.last_name" required>
                            </div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Email <span class="rm-field-opt">(optional)</span></label>
                                <input type="email" name="email" class="rm-input" x-model="form.email">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Phone <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="phone" class="rm-input" x-model="form.phone">
                            </div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Birthday <span class="rm-field-opt">(optional)</span></label>
                                <input type="date" name="birthday" class="rm-input" x-model="form.birthday">
                            </div>
                            <div></div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Daily Rate <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0" name="daily_rate" class="rm-input" x-model="form.daily_rate" placeholder="0.00">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Status</label>
                                <label class="rm-toggle-wrap" style="margin-top: 0.25rem;">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" class="rm-toggle" x-model="form.is_active">
                                    <span class="rm-toggle-label" x-text="form.is_active ? 'Active' : 'Inactive'"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeForm()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="form.mode === 'edit' ? 'Save changes' : 'Add employee'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function employeesPage(config) {
            return {
                isOwner: config.isOwner,
                updateUrlTemplate: config.updateUrlTemplate,
                detailUrlTemplate: config.detailUrlTemplate,
                activeFilter: config.initialFilters.active || 'all',
                detailOpen: false,
                detailData: null,
                detailController: null,
                formOpen: false,
                submitting: false,
                form: {
                    mode: 'create',
                    action: '',
                    branch_id: '',
                    employee_code: '',
                    first_name: '',
                    last_name: '',
                    email: '',
                    phone: '',
                    hire_date: new Date().toISOString().slice(0, 10),
                    birthday: '',
                    employment_type: 'full_time',
                    daily_rate: '',
                    is_active: true,
                },
                resetForm() {
                    this.form = {
                        mode: 'create',
                        action: '',
                        branch_id: '',
                        employee_code: '',
                        first_name: '',
                        last_name: '',
                        email: '',
                        phone: '',
                        hire_date: new Date().toISOString().slice(0, 10),
                        birthday: '',
                        employment_type: 'full_time',
                        daily_rate: '',
                        is_active: true,
                    };
                },
                openCreate() {
                    if (!this.isOwner) return;
                    this.resetForm();
                    this.formOpen = true;
                    this.submitting = false;
                },
                openEdit(employee) {
                    if (!this.isOwner) return;
                    this.form = {
                        mode: 'edit',
                        action: this.updateUrlTemplate.replace('__EMPLOYEE__', employee.id),
                        branch_id: String(employee.branch_id ?? ''),
                        employee_code: employee.employee_code ?? '',
                        first_name: employee.first_name ?? '',
                        last_name: employee.last_name ?? '',
                        email: employee.email ?? '',
                        phone: employee.phone ?? '',
                        hire_date: employee.hire_date ?? '',
                        birthday: employee.birthday ?? '',
                        employment_type: employee.employment_type ?? 'full_time',
                        daily_rate: employee.daily_rate ?? '',
                        is_active: Boolean(employee.is_active),
                    };
                    this.formOpen = true;
                    this.detailOpen = false;
                    this.submitting = false;
                },
                closeForm() {
                    this.formOpen = false;
                    this.submitting = false;
                },
                async openDetail(employeeId) {
                    if (this.detailController) this.detailController.abort();
                    this.detailData = null;
                    this.detailOpen = true;
                    this.detailController = new AbortController();
                    try {
                        const url = this.detailUrlTemplate.replace('__EMPLOYEE__', employeeId);
                        const res = await fetch(url, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            signal: this.detailController.signal,
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        this.detailData = await res.json();
                    } catch (err) {
                        if (err.name !== 'AbortError') {
                            this.detailOpen = false;
                            console.error(err);
                        }
                    }
                },
                closeDetail() {
                    this.detailOpen = false;
                    this.detailData = null;
                    if (this.detailController) this.detailController.abort();
                },
                editFromDetail() {
                    if (!this.detailData) return;
                    this.openEdit(this.detailData.employee);
                },
                closeAll() {
                    this.closeDetail();
                    this.closeForm();
                },
                cycleActive() {
                    const order = ['all', 'active', 'inactive'];
                    const i = order.indexOf(this.activeFilter);
                    this.activeFilter = order[(i + 1) % order.length];
                    this.$refs.filterForm.requestSubmit();
                },
                activeLabel() {
                    return this.activeFilter === 'all' ? 'All' : (this.activeFilter === 'active' ? 'Active' : 'Inactive');
                },
                formatType(t) {
                    return ({ full_time: 'Full Time', part_time: 'Part Time', contract: 'Contract' })[t] || t;
                },
                formatDate(d) {
                    if (!d) return '—';
                    try {
                        const date = new Date(d);
                        return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
                    } catch { return d; }
                },
                formatTenure(t) {
                    if (!t) return '—';
                    const parts = [];
                    if (t.years) parts.push(t.years + 'y');
                    if (t.months) parts.push(t.months + 'mo');
                    if (parts.length === 0) parts.push((t.days || 0) + 'd');
                    return parts.join(' ');
                },
                formatBirthday(emp) {
                    if (!emp.birthday) return '—';
                    return emp.birthday_display || this.formatDate(emp.birthday);
                },
            };
        }
    </script>
</x-app-layout>
