<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Payroll</h2>
    </x-slot>

    @php
        $defaultRules = [
            'standard_daily_hours' => '8',
            'required_clock_in_time' => '09:00',
            'first_deduction_time' => '09:15',
            'first_deduction_amount' => '50.00',
            'second_deduction_time' => '09:30',
            'second_deduction_amount' => '100.00',
            'third_deduction_time' => '10:00',
            'third_deduction_percent' => '50.00',
        ];
        $rulesByBranch = $rules->mapWithKeys(fn ($rule, $branchId) => [
            (string) $branchId => [
                'standard_daily_hours' => (string) $rule->standard_daily_hours,
                'required_clock_in_time' => \Illuminate\Support\Carbon::parse($rule->required_clock_in_time)->format('H:i'),
                'first_deduction_time' => \Illuminate\Support\Carbon::parse($rule->first_deduction_time)->format('H:i'),
                'first_deduction_amount' => (string) $rule->first_deduction_amount,
                'second_deduction_time' => \Illuminate\Support\Carbon::parse($rule->second_deduction_time)->format('H:i'),
                'second_deduction_amount' => (string) $rule->second_deduction_amount,
                'third_deduction_time' => \Illuminate\Support\Carbon::parse($rule->third_deduction_time)->format('H:i'),
                'third_deduction_percent' => (string) $rule->third_deduction_percent,
            ],
        ])->toArray();
        $hasActiveFilters = $filters['search'] !== '' || ! empty($filters['branch_id']) || $filters['status'] !== '';

        $employeesPayload = $employees->map(fn ($e) => [
            'id' => $e->id,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'employee_code' => $e->employee_code,
            'branch_id' => $e->branch_id,
            'branch_name' => $e->branch?->name,
            'daily_rate' => (float) ($e->daily_rate ?? 0),
        ])->values();
    @endphp

    <div
        class="rh-pay-page"
        x-data="payrollPage({
            defaultRules: @js($defaultRules),
            rulesByBranch: @js($rulesByBranch),
            employees: @js($employeesPayload),
            initialBranchId: @js((int) (optional($branches->first())->id ?? 0)),
            today: @js(now()->toDateString()),
        })"
        @keydown.escape.window="closeAll()"
    >
        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)"><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)"><span>{{ $errors->first() }}</span></div>
        @endif

        {{-- Top bar --}}
        <div class="rh-pay-topbar">
            <div>
                <h1 class="rh-pay-title">Payroll</h1>
                <p class="rh-pay-sub">{{ $stats['drafts'] }} draft · {{ $stats['finalized_this_month'] }} finalized this month</p>
            </div>
            <div class="rh-pay-topbar-actions">
                <button type="button" class="rm-btn rm-btn--ghost" @click="openRules()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="3"/>
                        <path stroke-linecap="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09c0 .67.4 1.27 1 1.51a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.24.6.84 1 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    Rules
                </button>
                <button type="button" class="rm-btn rm-btn--ghost" @click="openSingle()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    Generate
                </button>
                <button type="button" class="rm-btn rm-btn--primary" @click="openBulk()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Bulk Generate
                </button>
            </div>
        </div>

        {{-- Stats strip --}}
        <div class="rh-pay-stats">
            <div class="rh-pay-stat" style="--i:1;">
                <p class="rh-pay-stat-label">Drafts</p>
                <p class="rh-pay-stat-value {{ $stats['drafts'] > 0 ? 'rh-pay-stat-value--warn' : '' }}">{{ $stats['drafts'] }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:2;">
                <p class="rh-pay-stat-label">Finalized · {{ now()->format('F') }}</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--success">{{ $stats['finalized_this_month'] }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:3;">
                <p class="rh-pay-stat-label">Paid This Month</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--accent">₱{{ number_format($stats['paid_total_this_month'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:4;">
                <p class="rh-pay-stat-label">Pending Drafts</p>
                <p class="rh-pay-stat-value">₱{{ number_format($stats['pending_total'], 2) }}</p>
            </div>
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('payroll.index') }}" class="rh-pay-toolbar" x-ref="filterForm">
            <div class="rh-pay-toolbar-row">
                <label class="rh-pay-search">
                    <svg class="rh-pay-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search by employee or code…" x-on:input.debounce.350ms="$refs.filterForm.requestSubmit()">
                </label>
                <select name="branch_id" class="rh-pay-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <div class="rh-pay-status-chips">
                    <a href="{{ route('payroll.index', array_merge(request()->except(['status', 'page']), ['status' => ''])) }}" class="rh-pay-chip {{ $filters['status'] === '' ? 'rh-pay-chip--on' : '' }}">All</a>
                    <a href="{{ route('payroll.index', array_merge(request()->except(['page']), ['status' => 'draft'])) }}" class="rh-pay-chip {{ $filters['status'] === 'draft' ? 'rh-pay-chip--on' : '' }}">Draft</a>
                    <a href="{{ route('payroll.index', array_merge(request()->except(['page']), ['status' => 'paid'])) }}" class="rh-pay-chip {{ $filters['status'] === 'paid' ? 'rh-pay-chip--on' : '' }}">Paid</a>
                </div>
                @if ($hasActiveFilters)
                    <a href="{{ route('payroll.index') }}" class="rh-emp-clear">Clear</a>
                @endif
            </div>
        </form>

        {{-- Reports list --}}
        @if ($reports->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <svg class="rh-pay-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <rect x="3" y="6" width="18" height="14" rx="2"/>
                        <path d="M3 10h18M8 2v6M16 2v6"/>
                    </svg>
                    <p class="rh-pay-empty-title">No payroll reports</p>
                    <p style="font-size: 0.82rem;">Click <strong>Generate</strong> or <strong>Bulk Generate</strong> to create one.</p>
                </div>
            </div>
        @else
            <div class="rh-pay-list">
                @foreach ($reports as $report)
                    @php
                        $period = $report->payrollPeriod;
                        $employee = $report->employee;
                        $branch = $period?->branch ?? $employee?->branch;
                        $colorIdx = (($employee?->branch_id ?? 0) % 6) + 1;
                        $initials = strtoupper(substr($employee?->first_name ?? '?', 0, 1) . substr($employee?->last_name ?? '?', 0, 1));
                        $startLabel = $period ? \Illuminate\Support\Carbon::parse($period->start_date)->format('M j') : '-';
                        $endLabel = $period ? \Illuminate\Support\Carbon::parse($period->end_date)->format('M j, Y') : '-';
                    @endphp
                    <div class="rh-pay-row">
                        <span class="rh-pay-avatar rh-pay-avatar--c{{ $colorIdx }}">{{ $initials }}</span>
                        <div class="rh-pay-employee">
                            <div class="rh-pay-employee-name">{{ trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')) ?: 'Unknown' }}</div>
                            <span class="rh-pay-employee-meta">{{ $employee?->employee_code ?? '—' }}</span>
                        </div>
                        <div class="rh-pay-period">
                            {{ $startLabel }} – {{ $endLabel }}
                            <span class="rh-pay-period-meta">{{ $branch?->name ?? '—' }}</span>
                        </div>
                        <span class="rh-pay-amount">
                            ₱{{ number_format((float) $report->net_pay, 2) }}
                            <span class="rh-pay-amount-sub">Gross ₱{{ number_format((float) $report->gross_pay, 0) }}</span>
                        </span>
                        <span class="rh-pay-status rh-pay-status--{{ $report->status }}">
                            <span class="rh-pay-status-dot"></span>
                            {{ $report->status }}
                        </span>
                        <span class="rh-pay-actions" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="rh-pay-kebab" @click.stop="open = !open" aria-label="Actions">
                                <svg fill="currentColor" viewBox="0 0 24 24">
                                    <circle cx="5" cy="12" r="1.6"/>
                                    <circle cx="12" cy="12" r="1.6"/>
                                    <circle cx="19" cy="12" r="1.6"/>
                                </svg>
                            </button>
                            <div class="rh-pay-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                @if ($period)
                                    <a href="{{ route('payroll.show', $period) }}?employee_id={{ $report->employee_id }}">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        View Details
                                    </a>
                                @endif
                                @if ($report->status === 'draft')
                                    <form method="POST" action="{{ route('payroll.finalize') }}">
                                        @csrf
                                        <input type="hidden" name="payroll_entry_id" value="{{ $report->id }}">
                                        <button type="submit" class="is-success">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            Finalize
                                        </button>
                                    </form>
                                    <hr>
                                    <form method="POST" action="{{ route('payroll.reports.destroy', $report) }}" onsubmit="return confirm('Delete this payroll report? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="is-danger">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="rh-pay-pagination">{{ $reports->links() }}</div>
        @endif

        {{-- Rules drawer --}}
        <template x-if="rulesOpen">
            <div class="rm-overlay" @click.self="rulesOpen = false">
                <form method="POST" action="{{ route('payroll.rules.update') }}" class="rm-drawer rm-drawer--wide">
                    @csrf
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title">Payroll Rules</h2>
                        <button type="button" class="rm-drawer-close" @click="rulesOpen = false">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field">
                            <label class="rm-field-label">Branch</label>
                            <select name="branch_id" class="rm-input" x-model="rulesForm.branch_id" @change="loadBranchRules()" required>
                                <option value="">Select branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="rh-pay-rules-diagram">
                            <strong>Deduction Tiers</strong> · From required time-in onward:<br>
                            <strong>1st Hit</strong> (after grace) → fixed amount &nbsp;·&nbsp; <strong>2nd Hit</strong> → fixed amount &nbsp;·&nbsp; <strong>3rd Hit</strong> → percent of daily rate
                        </div>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Standard Daily Hours</label>
                                <input type="number" step="0.25" min="1" max="24" name="standard_daily_hours" class="rm-input" x-model="rulesForm.standard_daily_hours" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Required Time-In</label>
                                <input type="time" name="required_clock_in_time" class="rm-input" x-model="rulesForm.required_clock_in_time" required>
                            </div>
                        </div>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">1st Hit Time</label>
                                <input type="time" name="first_deduction_time" class="rm-input" x-model="rulesForm.first_deduction_time" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">1st Deduction <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0" name="first_deduction_amount" class="rm-input" x-model="rulesForm.first_deduction_amount" required>
                            </div>
                        </div>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">2nd Hit Time</label>
                                <input type="time" name="second_deduction_time" class="rm-input" x-model="rulesForm.second_deduction_time" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">2nd Deduction <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0" name="second_deduction_amount" class="rm-input" x-model="rulesForm.second_deduction_amount" required>
                            </div>
                        </div>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">3rd Hit Time</label>
                                <input type="time" name="third_deduction_time" class="rm-input" x-model="rulesForm.third_deduction_time" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">3rd Deduction <span class="rm-field-opt">(% of daily)</span></label>
                                <input type="number" step="0.01" min="0" max="100" name="third_deduction_percent" class="rm-input" x-model="rulesForm.third_deduction_percent" required>
                            </div>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--ghost" @click="resetRules()">Reset to defaults</button>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="rulesOpen = false">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary">Save rules</button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- Single generate drawer --}}
        <template x-if="singleOpen">
            <div class="rm-overlay" @click.self="singleOpen = false">
                <form method="POST" action="{{ route('payroll.generate') }}" class="rm-drawer rm-drawer--wide">
                    @csrf
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title">Generate Report</h2>
                        <button type="button" class="rm-drawer-close" @click="singleOpen = false">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field">
                            <label class="rm-field-label">Employee</label>
                            <select name="employee_id" class="rm-input" x-model="singleForm.employee_id" required>
                                <option value="">Select employee</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">
                                        {{ $employee->first_name }} {{ $employee->last_name }} · {{ $employee->branch?->name ?? '—' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Start Date</label>
                                <input type="date" name="start_date" class="rm-input" x-model="singleForm.start_date" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">End Date</label>
                                <input type="date" name="end_date" class="rm-input" x-model="singleForm.end_date" required>
                            </div>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="singleOpen = false">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary">Generate</button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- Bulk generate drawer --}}
        <template x-if="bulkOpen">
            <div class="rm-overlay" @click.self="bulkOpen = false">
                <form method="POST" action="{{ route('payroll.bulk-generate') }}" class="rm-drawer rm-drawer--wide">
                    @csrf
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title">Bulk Generate Reports</h2>
                        <button type="button" class="rm-drawer-close" @click="bulkOpen = false">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Start Date</label>
                                <input type="date" name="start_date" class="rm-input" x-model="bulkForm.start_date" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">End Date</label>
                                <input type="date" name="end_date" class="rm-input" x-model="bulkForm.end_date" required>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Filter by Branch <span class="rm-field-opt">(visual filter only)</span></label>
                            <select class="rm-input" x-model="bulkBranchFilter">
                                <option value="">All branches</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Employees <span class="rm-field-opt" x-text="'· ' + selectedEmployeeIds.length + ' selected'"></span></label>
                            <div class="rh-pay-emp-picker">
                                <div class="rh-pay-emp-picker-head">
                                    <span class="rh-pay-emp-picker-meta" x-text="visibleEmployees().length + ' visible'"></span>
                                    <div>
                                        <button type="button" class="rh-pay-emp-picker-action" @click="selectAllVisible()">Select all</button>
                                        <button type="button" class="rh-pay-emp-picker-action" @click="clearSelection()">Clear</button>
                                    </div>
                                </div>
                                <template x-for="emp in visibleEmployees()" :key="emp.id">
                                    <label class="rh-pay-emp-row" :class="{'rh-pay-emp-row--no-rate': emp.daily_rate <= 0}">
                                        <input type="checkbox" name="employee_ids[]" :value="emp.id" :checked="selectedEmployeeIds.includes(emp.id)" @change="toggleEmployee(emp.id)">
                                        <span class="rh-pay-emp-row-name" x-text="emp.first_name + ' ' + emp.last_name + (emp.daily_rate <= 0 ? ' (no rate)' : '')"></span>
                                        <span class="rh-pay-emp-row-meta" x-text="(emp.employee_code || '—') + ' · ' + (emp.branch_name || '—')"></span>
                                    </label>
                                </template>
                                <template x-if="visibleEmployees().length === 0">
                                    <div class="rh-pay-emp-row" style="justify-content: center; color: var(--rh-text-muted); font-style: italic;">No employees match this branch.</div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="bulkOpen = false">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="selectedEmployeeIds.length === 0">
                                <span x-text="'Generate ' + selectedEmployeeIds.length + ' report' + (selectedEmployeeIds.length === 1 ? '' : 's')"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function payrollPage(config) {
            return {
                rulesOpen: false,
                singleOpen: false,
                bulkOpen: false,
                rulesForm: { ...config.defaultRules, branch_id: '' },
                singleForm: { employee_id: '', start_date: '', end_date: config.today },
                bulkForm: { start_date: '', end_date: config.today },
                bulkBranchFilter: '',
                selectedEmployeeIds: [],
                openRules() {
                    const initial = String(config.initialBranchId || '');
                    this.rulesForm = { ...config.defaultRules, branch_id: initial };
                    this.loadBranchRules();
                    this.rulesOpen = true;
                },
                loadBranchRules() {
                    const id = this.rulesForm.branch_id;
                    const branchRules = id ? config.rulesByBranch[String(id)] : null;
                    if (branchRules) {
                        this.rulesForm = { ...this.rulesForm, ...branchRules };
                    } else {
                        this.rulesForm = { ...config.defaultRules, branch_id: id };
                    }
                },
                resetRules() {
                    this.rulesForm = { ...config.defaultRules, branch_id: this.rulesForm.branch_id };
                },
                openSingle() {
                    this.singleForm = { employee_id: '', start_date: '', end_date: config.today };
                    this.singleOpen = true;
                },
                openBulk() {
                    this.bulkForm = { start_date: '', end_date: config.today };
                    this.bulkBranchFilter = '';
                    this.selectedEmployeeIds = [];
                    this.bulkOpen = true;
                },
                visibleEmployees() {
                    if (!this.bulkBranchFilter) return config.employees;
                    const id = parseInt(this.bulkBranchFilter, 10);
                    return config.employees.filter(e => e.branch_id === id);
                },
                toggleEmployee(id) {
                    const idx = this.selectedEmployeeIds.indexOf(id);
                    if (idx >= 0) this.selectedEmployeeIds.splice(idx, 1);
                    else this.selectedEmployeeIds.push(id);
                },
                selectAllVisible() {
                    const visible = this.visibleEmployees().map(e => e.id);
                    visible.forEach(id => {
                        if (!this.selectedEmployeeIds.includes(id)) this.selectedEmployeeIds.push(id);
                    });
                },
                clearSelection() {
                    this.selectedEmployeeIds = [];
                },
                closeAll() {
                    this.rulesOpen = false;
                    this.singleOpen = false;
                    this.bulkOpen = false;
                },
            };
        }
    </script>
</x-app-layout>
