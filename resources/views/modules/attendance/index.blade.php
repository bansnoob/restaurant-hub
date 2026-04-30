<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance</h2>
    </x-slot>

    @php
        $isOwner = (bool) auth()->user()?->hasRole('owner');
        $stateLabels = [
            'working' => 'Working Now',
            'done'    => 'Clocked Out',
            'not_yet' => 'Not In Yet',
            'absent'  => 'Absent',
            'leave'   => 'Leave / Holiday',
        ];
        $stateOrder = ['working', 'done', 'not_yet', 'absent', 'leave'];
        $hasFilters = ! empty($branchFilter) || $workDate !== now()->toDateString();
        $todayLabel = \Carbon\Carbon::parse($workDate)->isToday()
            ? 'Today · '.\Carbon\Carbon::parse($workDate)->format('D, M j')
            : \Carbon\Carbon::parse($workDate)->format('l, M j, Y');
    @endphp

    <div
        class="rh-att2-page"
        x-data="attendancePage({
            isOwner: {{ $isOwner ? 'true' : 'false' }},
            initialWorkDate: @js($workDate),
            initialBranch: @js($branchFilter ?? ''),
            initialSearch: '',
            roster: @js($roster->all()),
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
        <div class="rh-att2-topbar">
            <div>
                <h1 class="rh-att2-title">Attendance</h1>
                <p class="rh-att2-sub">{{ strtoupper($todayLabel) }}</p>
            </div>
            @if ($isOwner)
                <button type="button" class="rm-btn rm-btn--primary" @click="openCreate()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    Manual Entry
                </button>
            @endif
        </div>

        {{-- Day stats strip --}}
        <div class="rh-att2-stats">
            <div class="rh-att2-stat" style="--i:1;">
                <p class="rh-att2-stat-label">Working Now</p>
                <p class="rh-att2-stat-value rh-att2-stat-value--success">{{ $stats['working_now'] }}</p>
                <p class="rh-att2-stat-sub">Currently clocked in</p>
            </div>
            <div class="rh-att2-stat" style="--i:2;">
                <p class="rh-att2-stat-label">Present</p>
                <p class="rh-att2-stat-value">{{ $stats['present'] }}</p>
                <p class="rh-att2-stat-sub">{{ $stats['working_now'] }} active · {{ $stats['present'] - $stats['working_now'] }} done</p>
            </div>
            <div class="rh-att2-stat" style="--i:3;">
                <p class="rh-att2-stat-label">Late</p>
                <p class="rh-att2-stat-value {{ $stats['late'] > 0 ? 'rh-att2-stat-value--warn' : '' }}">{{ $stats['late'] }}</p>
                <p class="rh-att2-stat-sub">After grace window</p>
            </div>
            <div class="rh-att2-stat" style="--i:4;">
                <p class="rh-att2-stat-label">Absent</p>
                <p class="rh-att2-stat-value {{ $stats['absent'] > 0 ? 'rh-att2-stat-value--danger' : '' }}">{{ $stats['absent'] }}</p>
                <p class="rh-att2-stat-sub">{{ $stats['not_yet'] }} not in yet</p>
            </div>
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('attendance.index') }}" class="rh-att2-toolbar" x-ref="filterForm">
            <div class="rh-att2-toolbar-row">
                <label class="rh-att2-search">
                    <svg class="rh-att2-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input
                        type="text"
                        placeholder="Search by name or code…"
                        x-model="search"
                    >
                </label>

                <select name="branch_id" class="rh-att2-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $branchFilter === (string) $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>

                <input type="date" name="work_date" value="{{ $workDate }}" class="rh-att2-date" @change="$refs.filterForm.requestSubmit()">

                @if ($hasFilters)
                    <a href="{{ route('attendance.index') }}" class="rh-emp-clear">Clear</a>
                @endif
            </div>
        </form>

        {{-- Roster sections --}}
        @php
            $stateDotMap = [
                'working' => 'rh-att2-section-dot--working',
                'done' => 'rh-att2-section-dot--done',
                'not_yet' => 'rh-att2-section-dot--not_yet',
                'absent' => 'rh-att2-section-dot--absent',
                'leave' => 'rh-att2-section-dot--leave',
            ];
        @endphp

        @foreach ($stateOrder as $state)
            @php $items = $rosterByState[$state] ?? collect(); @endphp
            <section class="rh-att2-section" x-show="hasMatches('{{ $state }}')">
                <div class="rh-att2-section-head">
                    <h3 class="rh-att2-section-title">
                        <span class="rh-att2-section-dot {{ $stateDotMap[$state] }}"></span>
                        {{ $stateLabels[$state] }}
                        <span class="rh-att2-section-count" x-text="countMatches('{{ $state }}')">{{ $items->count() }}</span>
                    </h3>
                </div>

                @if ($items->isEmpty())
                    <div class="rh-att2-empty">
                        @if ($state === 'working') Nobody is currently clocked in.
                        @elseif ($state === 'done') No one has finished their shift yet.
                        @elseif ($state === 'not_yet') Everyone is accounted for.
                        @elseif ($state === 'absent') No absences recorded.
                        @else No leave or holiday records.
                        @endif
                    </div>
                @else
                    <div class="rh-att2-grid">
                        @foreach ($items as $item)
                            @php
                                $initials = strtoupper(substr($item['first_name'] ?? '?', 0, 1) . substr($item['last_name'] ?? '?', 0, 1));
                                $colorIdx = (($item['branch_id'] ?? 0) % 6) + 1;
                            @endphp
                            <div
                                class="rh-att2-card rh-att2-card--{{ $state }}"
                                style="--i:{{ $loop->iteration }};"
                                x-show="matchesSearch(@js($item))"
                                @click="openEdit(@js($item))"
                            >
                                <div class="rh-att2-card-head">
                                    <span class="rh-att2-avatar rh-att2-avatar--c{{ $colorIdx }}">{{ $initials }}</span>
                                    <div class="rh-att2-card-name">
                                        <div class="rh-att2-card-name-line">
                                            {{ $item['full_name'] }}
                                            @if ($item['is_late'])
                                                <span class="rh-att2-late-pill">Late</span>
                                            @endif
                                        </div>
                                        <span class="rh-att2-card-meta">{{ $item['employee_code'] }} · {{ $item['branch_name'] ?? '—' }}</span>
                                    </div>
                                </div>

                                {{-- Body varies by state --}}
                                @if ($state === 'working')
                                    <div class="rh-att2-card-body">
                                        <div class="rh-att2-card-info">
                                            <div class="rh-att2-card-time">{{ $item['clock_in_label'] }}</div>
                                            <span class="rh-att2-card-time-sub">
                                                Clocked in
                                                @if ($item['clock_in_at'])
                                                    · <span x-data="{ ago: '' }" x-init="ago = elapsedSince(@js($item['clock_in_at'])); setInterval(() => ago = elapsedSince(@js($item['clock_in_at'])), 60000)" x-text="ago"></span>
                                                @endif
                                            </span>
                                        </div>
                                        <span class="rh-att2-card-tag rh-att2-card-tag--working">
                                            <span class="rh-att2-tag-dot"></span>
                                            Live
                                        </span>
                                    </div>
                                    <div class="rh-att2-card-actions" @click.stop>
                                        <form method="POST" action="{{ route('attendance.clock-out') }}" style="flex: 1;">
                                            @csrf
                                            <input type="hidden" name="record_id" value="{{ $item['record_id'] }}">
                                            <button type="submit" class="rh-att2-action-btn rh-att2-action-btn--primary" style="width: 100%;">
                                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                Clock Out
                                            </button>
                                        </form>
                                        @if ($isOwner)
                                            <button type="button" class="rh-att2-action-btn rh-att2-action-btn--ghost" @click.stop="openEdit(@js($item))">
                                                Edit
                                            </button>
                                        @endif
                                    </div>
                                @elseif ($state === 'done')
                                    <div class="rh-att2-card-body">
                                        <div class="rh-att2-card-info">
                                            <div class="rh-att2-card-time">{{ $item['clock_in_label'] }} <span class="rh-att2-card-arrow">→</span> {{ $item['clock_out_label'] }}</div>
                                            <span class="rh-att2-card-time-sub">{{ number_format($item['hours_worked'], 1) }} hours worked</span>
                                        </div>
                                    </div>
                                @elseif ($state === 'not_yet')
                                    <div class="rh-att2-card-body">
                                        <div class="rh-att2-card-info">
                                            <span class="rh-att2-card-time-sub">No record yet</span>
                                        </div>
                                    </div>
                                    <div class="rh-att2-card-actions" @click.stop>
                                        <form method="POST" action="{{ route('attendance.clock-in') }}" style="flex: 1;">
                                            @csrf
                                            <input type="hidden" name="employee_id" value="{{ $item['employee_id'] }}">
                                            <input type="hidden" name="work_date" value="{{ $workDate }}">
                                            <button type="submit" class="rh-att2-action-btn rh-att2-action-btn--primary" style="width: 100%;">
                                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                                                Clock In
                                            </button>
                                        </form>
                                        @if ($isOwner)
                                            <button type="button" class="rh-att2-action-btn rh-att2-action-btn--ghost" @click.stop="openEdit(@js($item))">
                                                Manual
                                            </button>
                                        @endif
                                    </div>
                                @elseif ($state === 'absent' || $state === 'leave')
                                    <div class="rh-att2-card-body">
                                        <div class="rh-att2-card-info">
                                            <div class="rh-att2-card-time" style="font-size: 1rem;">{{ ucfirst($item['status']) }}</div>
                                            @if ($item['notes'])
                                                <span class="rh-att2-card-time-sub" style="text-transform: none; letter-spacing: 0;">{{ \Illuminate\Support\Str::limit($item['notes'], 80) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        {{-- Weekly summary panel --}}
        @if ($isOwner)
            <section class="rh-att2-summary" :class="summaryOpen ? 'rh-att2-summary--open' : ''">
                <div class="rh-att2-summary-head" @click="summaryOpen = !summaryOpen">
                    <h3 class="rh-att2-summary-title">Period Summary</h3>
                    <div class="rh-att2-summary-meta">
                        <span class="rh-att2-summary-range">{{ \Carbon\Carbon::parse($dateFrom)->format('M j') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M j, Y') }}</span>
                        <span class="rh-att2-summary-toggle">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                        </span>
                    </div>
                </div>
                <div class="rh-att2-summary-body" x-show="summaryOpen" x-cloak x-transition.opacity>
                    <form method="GET" action="{{ route('attendance.index') }}" class="rh-att2-summary-pickers">
                        <input type="hidden" name="work_date" value="{{ $workDate }}">
                        @if ($branchFilter)
                            <input type="hidden" name="branch_id" value="{{ $branchFilter }}">
                        @endif
                        <span class="rh-att2-summary-picker-label">From</span>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" class="rh-att2-date" onchange="this.form.submit()">
                        <span class="rh-att2-summary-picker-label">To</span>
                        <input type="date" name="date_to" value="{{ $dateTo }}" class="rh-att2-date" onchange="this.form.submit()">
                    </form>
                    <div class="rh-att2-summary-table-wrap">
                        @if ($summaries->isEmpty())
                            <div class="rh-att2-summary-empty">No employees in range.</div>
                        @else
                            <table class="rh-att2-summary-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th class="rh-att2-th-num">Days</th>
                                        <th class="rh-att2-th-num">Late</th>
                                        <th class="rh-att2-th-num">Worked Hrs</th>
                                        <th class="rh-att2-th-num">Regular Hrs</th>
                                        <th class="rh-att2-th-num">Est. Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($summaries as $summary)
                                        <tr>
                                            <td>
                                                <strong>{{ $summary['employee_name'] }}</strong>
                                                <span style="color: var(--rh-text-muted); font-family: var(--rh-font-mono); font-size: 0.65rem; margin-left: 0.4rem;">{{ $summary['employee_code'] }}</span>
                                            </td>
                                            <td class="rh-att2-td-num">{{ $summary['days_with_logs'] }}</td>
                                            <td class="rh-att2-td-num">{{ $summary['late_days'] }}</td>
                                            <td class="rh-att2-td-num">{{ number_format($summary['worked_hours'], 1) }}</td>
                                            <td class="rh-att2-td-num">{{ number_format($summary['regular_hours'], 1) }}</td>
                                            <td class="rh-att2-td-num rh-att2-td-num--accent">₱{{ number_format($summary['estimated_net_pay'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- Edit / Manual Entry drawer --}}
        <template x-if="drawerOpen">
            <div class="rm-overlay" @click.self="closeDrawer()">
                <form
                    method="POST"
                    :action="form.action"
                    class="rm-drawer rm-drawer--wide"
                    @submit="submitting = true"
                >
                    @csrf
                    <template x-if="form.method === 'PATCH' || form.method === 'PUT' || form.method === 'DELETE'">
                        <input type="hidden" name="_method" :value="form.method">
                    </template>
                    <template x-if="form.recordId">
                        <input type="hidden" name="record_id" :value="form.recordId">
                    </template>
                    <template x-if="form.mode === 'create'">
                        <input type="hidden" name="work_date" :value="form.workDate">
                    </template>

                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="form.title"></h2>
                            <span class="rh-att2-drawer-state" x-show="form.stateLabel">
                                <span class="rh-att2-drawer-state-dot" :class="'rh-att2-drawer-state-dot--' + form.state"></span>
                                <span x-text="form.stateLabel"></span>
                            </span>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDrawer()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        {{-- Employee picker only for create --}}
                        <template x-if="form.mode === 'create'">
                            <div class="rm-field">
                                <label class="rm-field-label">Employee</label>
                                <select name="employee_id" class="rm-input" x-model="form.employeeId" required>
                                    <option value="">Select employee</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}">{{ $employee->employee_code }} · {{ $employee->first_name }} {{ $employee->last_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        <template x-if="form.mode === 'create'">
                            <div class="rm-field">
                                <label class="rm-field-label">Work Date</label>
                                <input type="date" name="work_date" class="rm-input" x-model="form.workDate" required>
                            </div>
                        </template>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Clock In</label>
                                <input type="time" name="clock_in_time" class="rm-input" x-model="form.clockInTime" :required="form.mode === 'create'">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Clock Out <span class="rm-field-opt">(optional)</span></label>
                                <input type="time" name="clock_out_time" class="rm-input" x-model="form.clockOutTime">
                            </div>
                        </div>

                        <div class="rm-field">
                            <label class="rm-field-label">Notes <span class="rm-field-opt">(optional)</span></label>
                            <textarea name="notes" class="rm-input rm-textarea" x-model="form.notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div>
                            <template x-if="form.mode === 'edit' && form.recordId && {{ $isOwner ? 'true' : 'false' }}">
                                <button type="button" class="rm-btn rm-btn--danger" @click="deleteRecord()">Delete</button>
                            </template>
                        </div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeDrawer()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="form.mode === 'edit' ? 'Save changes' : 'Save entry'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function attendancePage(config) {
            return {
                isOwner: config.isOwner,
                roster: config.roster,
                workDate: config.initialWorkDate,
                branch: config.initialBranch,
                search: config.initialSearch,
                summaryOpen: false,
                drawerOpen: false,
                submitting: false,
                form: {
                    mode: 'create',
                    method: 'POST',
                    action: '',
                    title: 'Manual Entry',
                    state: 'not_yet',
                    stateLabel: '',
                    employeeId: '',
                    workDate: config.initialWorkDate,
                    recordId: '',
                    clockInTime: '',
                    clockOutTime: '',
                    notes: '',
                },
                matchesSearch(item) {
                    const q = (this.search || '').trim().toLowerCase();
                    if (!q) return true;
                    const hay = [item.full_name, item.first_name, item.last_name, item.employee_code, item.branch_name]
                        .filter(Boolean).join(' ').toLowerCase();
                    return hay.includes(q);
                },
                matchingForState(state) {
                    return this.roster.filter(r => r.state === state && this.matchesSearch(r));
                },
                hasMatches(state) {
                    return this.matchingForState(state).length > 0;
                },
                countMatches(state) {
                    return this.matchingForState(state).length;
                },
                openCreate() {
                    if (!this.isOwner) return;
                    this.form = {
                        mode: 'create',
                        method: 'POST',
                        action: '{{ route('attendance.manual-entry') }}',
                        title: 'New Manual Entry',
                        state: 'not_yet',
                        stateLabel: '',
                        employeeId: '',
                        workDate: this.workDate,
                        recordId: '',
                        clockInTime: '',
                        clockOutTime: '',
                        notes: '',
                    };
                    this.drawerOpen = true;
                    this.submitting = false;
                },
                openEdit(item) {
                    if (item.record_id) {
                        this.form = {
                            mode: 'edit',
                            method: 'POST',
                            action: '{{ route('attendance.update-times') }}',
                            title: item.full_name,
                            state: item.state,
                            stateLabel: this.formatState(item.state, item.is_late),
                            employeeId: item.employee_id,
                            workDate: this.workDate,
                            recordId: item.record_id,
                            clockInTime: item.clock_in_at ? this.timeFromIso(item.clock_in_at) : '',
                            clockOutTime: item.clock_out_at ? this.timeFromIso(item.clock_out_at) : '',
                            notes: item.notes || '',
                        };
                    } else {
                        if (!this.isOwner) return;
                        this.form = {
                            mode: 'create',
                            method: 'POST',
                            action: '{{ route('attendance.manual-entry') }}',
                            title: 'Manual entry · ' + item.full_name,
                            state: item.state,
                            stateLabel: this.formatState(item.state, item.is_late),
                            employeeId: item.employee_id,
                            workDate: this.workDate,
                            recordId: '',
                            clockInTime: '',
                            clockOutTime: '',
                            notes: '',
                        };
                    }
                    this.drawerOpen = true;
                    this.submitting = false;
                },
                closeDrawer() {
                    this.drawerOpen = false;
                    this.submitting = false;
                },
                closeAll() {
                    this.closeDrawer();
                },
                deleteRecord() {
                    if (!this.form.recordId) return;
                    if (!confirm('Delete this attendance record? Draft payroll will recalculate.')) return;
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = '{{ route('attendance.index') }}'.replace(/\/+$/, '') + '/' + this.form.recordId;
                    f.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="DELETE">`;
                    document.body.appendChild(f);
                    f.submit();
                },
                formatState(state, isLate) {
                    const labels = {
                        working: 'Working',
                        done: 'Clocked out',
                        not_yet: 'Not in yet',
                        absent: 'Absent',
                        leave: 'Leave',
                    };
                    let s = labels[state] || state;
                    if (isLate) s += ' · Late';
                    return s;
                },
                timeFromIso(iso) {
                    try {
                        const d = new Date(iso);
                        const hh = String(d.getHours()).padStart(2, '0');
                        const mm = String(d.getMinutes()).padStart(2, '0');
                        return `${hh}:${mm}`;
                    } catch { return ''; }
                },
                elapsedSince(iso) {
                    if (!iso) return '';
                    try {
                        const start = new Date(iso).getTime();
                        const now = Date.now();
                        const minutes = Math.max(0, Math.round((now - start) / 60000));
                        if (minutes < 1) return 'just now';
                        if (minutes < 60) return minutes + 'm ago';
                        const h = Math.floor(minutes / 60);
                        const m = minutes % 60;
                        return m > 0 ? `${h}h ${m}m ago` : `${h}h ago`;
                    } catch { return ''; }
                },
            };
        }
    </script>
</x-app-layout>
