<x-app-layout>

    <div class="rh-dashboard">

        {{-- ── Greeting ──────────────────────────────────────────────── --}}
        @php
            $hour = now()->hour;
            $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        @endphp

        <div class="rh-greeting-section rh-greeting-row">
            <div>
                <p class="rh-greeting-salutation">{{ $greeting }},</p>
                <h1 class="rh-greeting-name">{{ Auth::user()->name }}</h1>
                <p class="rh-greeting-date">{{ strtoupper(now()->format('D · d F Y')) }}</p>
            </div>
            <div class="rh-dash-meta">
                <span class="rh-dash-meta-pill">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    {{ $activeEmployees }} {{ Str::plural('employee', $activeEmployees) }}
                </span>
                <span class="rh-dash-meta-pill">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    {{ $activeBranches }} {{ Str::plural('branch', $activeBranches) }}
                </span>
            </div>
        </div>

        <div class="rh-section-rule"></div>

        {{-- ── Today at a Glance ─────────────────────────────────────── --}}
        <p class="rh-dash-eyebrow">Today at a Glance</p>
        <div class="rh-stat-grid rh-stat-grid--4">
            <div class="rh-stat-card" style="--i:1">
                <p class="rh-stat-card-label">Revenue</p>
                <p class="rh-stat-card-value">₱{{ number_format($todaySales, 0) }}</p>
                <p class="rh-stat-card-sub">Completed sales today</p>
            </div>
            <div class="rh-stat-card" style="--i:2">
                <p class="rh-stat-card-label">Expenses</p>
                <p class="rh-stat-card-value">₱{{ number_format($todayExpenses, 0) }}</p>
                <p class="rh-stat-card-sub">Approved expenses today</p>
            </div>
            <div class="rh-stat-card" style="--i:3">
                <p class="rh-stat-card-label">Present</p>
                <p class="rh-stat-card-value">{{ $presentToday }}</p>
                <p class="rh-stat-card-sub">Staff clocked in{{ $lateToday > 0 ? ' &middot; '.$lateToday.' late' : '' }}</p>
            </div>
            <div class="rh-stat-card" style="--i:4">
                <p class="rh-stat-card-label">Absent</p>
                <p class="rh-stat-card-value {{ $absentToday > 0 ? 'rh-stat-value--warn' : '' }}">{{ $absentToday }}</p>
                <p class="rh-stat-card-sub">Absent records logged</p>
            </div>
        </div>

        {{-- ── This Month ────────────────────────────────────────────── --}}
        <p class="rh-dash-eyebrow" style="margin-top: 2.25rem;">{{ now()->format('F Y') }}</p>
        <div class="rh-stat-grid rh-stat-grid--3">
            <div class="rh-stat-card" style="--i:1">
                <p class="rh-stat-card-label">Month-to-Date Revenue</p>
                <p class="rh-stat-card-value rh-stat-value--accent">₱{{ number_format($mtdSales, 0) }}</p>
                <p class="rh-stat-card-sub">Completed sales since {{ now()->format('M 1') }}</p>
            </div>
            <div class="rh-stat-card" style="--i:2">
                <p class="rh-stat-card-label">Month-to-Date Expenses</p>
                <p class="rh-stat-card-value">₱{{ number_format($mtdExpenses, 0) }}</p>
                <p class="rh-stat-card-sub">Approved expenses since {{ now()->format('M 1') }}</p>
            </div>
            <div class="rh-stat-card" style="--i:3">
                <p class="rh-stat-card-label">Draft Payrolls</p>
                <p class="rh-stat-card-value {{ $draftPayrolls > 0 ? 'rh-stat-value--warn' : '' }}">{{ $draftPayrolls }}</p>
                <p class="rh-stat-card-sub">{{ $draftPayrolls > 0 ? 'Periods pending finalization' : 'No pending payroll periods' }}</p>
            </div>
        </div>

        {{-- ── 7-Day Chart + Attendance ──────────────────────────────── --}}
        <div class="rh-dash-cols rh-dash-cols--6-4" style="margin-top: 1px;">

            <div class="rh-dash-panel">
                <p class="rh-dash-panel-title">7-Day Sales Trend</p>
                <div class="rh-bar-chart">
                    @foreach($last7Days as $day)
                        @php
                            $barPx = $chartMax > 0 ? max(3, (int) round(($day['total'] / $chartMax) * 110)) : 3;
                        @endphp
                        <div class="rh-bar-col">
                            <div class="rh-bar {{ $day['is_today'] ? 'rh-bar--today' : '' }}"
                                 style="height: {{ $barPx }}px"
                                 title="{{ $day['label'] }}: ₱{{ number_format($day['total'], 0) }}">
                            </div>
                            <span class="rh-bar-label {{ $day['is_today'] ? 'rh-bar-label--today' : '' }}">
                                {{ $day['label'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
                <p class="rh-dash-chart-note">
                    7-day total &nbsp;·&nbsp; <strong>₱{{ number_format($last7Days->sum('total'), 0) }}</strong>
                    @php $todayTotal = $last7Days->firstWhere('is_today', true)['total'] ?? 0; @endphp
                    @if($todayTotal > 0)
                        &nbsp;·&nbsp; today ₱{{ number_format($todayTotal, 0) }}
                    @endif
                </p>
            </div>

            <div class="rh-dash-panel rh-dash-panel--sep">
                <p class="rh-dash-panel-title">Attendance Today</p>
                @php
                    $attTotal      = max($totalClockedToday, 1);
                    $presentPct    = (int) round(($presentToday / $attTotal) * 100);
                    $latePct       = (int) round(($lateToday    / $attTotal) * 100);
                    $absentPct     = (int) round(($absentToday  / $attTotal) * 100);
                @endphp
                <div class="rh-att-rows">
                    <div class="rh-att-row">
                        <div class="rh-att-row-top">
                            <span class="rh-att-label rh-att-label--present">Present</span>
                            <span class="rh-att-count">{{ $presentToday }}</span>
                        </div>
                        <div class="rh-att-track">
                            <div class="rh-att-fill rh-att-fill--present" style="width: {{ $presentPct }}%"></div>
                        </div>
                    </div>
                    <div class="rh-att-row">
                        <div class="rh-att-row-top">
                            <span class="rh-att-label rh-att-label--late">Late</span>
                            <span class="rh-att-count">{{ $lateToday }}</span>
                        </div>
                        <div class="rh-att-track">
                            <div class="rh-att-fill rh-att-fill--late" style="width: {{ $latePct }}%"></div>
                        </div>
                    </div>
                    <div class="rh-att-row">
                        <div class="rh-att-row-top">
                            <span class="rh-att-label rh-att-label--absent">Absent</span>
                            <span class="rh-att-count">{{ $absentToday }}</span>
                        </div>
                        <div class="rh-att-track">
                            <div class="rh-att-fill rh-att-fill--absent" style="width: {{ $absentPct }}%"></div>
                        </div>
                    </div>
                </div>
                @if($totalClockedToday === 0)
                    <p class="rh-dash-empty">No attendance records logged today.</p>
                @endif
            </div>

        </div>

        {{-- ── Recent Sales + Expenses ───────────────────────────────── --}}
        <div class="rh-dash-cols rh-dash-cols--2" style="margin-top: 1px;">

            <div class="rh-dash-panel">
                <div class="rh-dash-panel-header">
                    <p class="rh-dash-panel-title">Recent Sales</p>
                    <a href="{{ route('sales.index') }}" class="rh-dash-panel-link">View all &rarr;</a>
                </div>
                @if($recentSales->isEmpty())
                    <p class="rh-dash-empty">No completed sales yet.</p>
                @else
                    <div class="rh-activity-list">
                        @foreach($recentSales as $sale)
                            <div class="rh-activity-row">
                                <div class="rh-activity-left">
                                    <span class="rh-activity-code">{{ $sale->order_number }}</span>
                                    <span class="rh-activity-meta">
                                        {{ ucfirst(str_replace('_', ' ', $sale->order_type)) }}
                                        &nbsp;&middot;&nbsp;
                                        {{ \Carbon\Carbon::parse($sale->sale_datetime)->format('g:i A') }}
                                    </span>
                                </div>
                                <span class="rh-activity-amount">₱{{ number_format($sale->grand_total, 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rh-dash-panel rh-dash-panel--sep">
                <div class="rh-dash-panel-header">
                    <p class="rh-dash-panel-title">Recent Expenses</p>
                    <a href="{{ route('expenses.index') }}" class="rh-dash-panel-link">View all &rarr;</a>
                </div>
                @if($recentExpenses->isEmpty())
                    <p class="rh-dash-empty">No approved expenses yet.</p>
                @else
                    <div class="rh-activity-list">
                        @foreach($recentExpenses as $expense)
                            <div class="rh-activity-row">
                                <div class="rh-activity-left">
                                    <span class="rh-activity-code">
                                        {{ Str::limit($expense->description ?: $expense->vendor_name ?: '—', 32) }}
                                    </span>
                                    <span class="rh-activity-meta">
                                        {{ $expenseCategoryNames->get($expense->expense_category_id, 'Uncategorized') }}
                                        &nbsp;&middot;&nbsp;
                                        {{ \Carbon\Carbon::parse($expense->expense_date)->format('M j') }}
                                    </span>
                                </div>
                                <span class="rh-activity-amount rh-activity-amount--expense">
                                    ₱{{ number_format($expense->amount, 0) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

        {{-- ── Alerts ────────────────────────────────────────────────── --}}
        @if($lowStockItems > 0 || $draftPayrolls > 0)
            <div class="rh-dash-alerts">
                @if($lowStockItems > 0)
                    <a href="{{ route('inventory.index') }}" class="rh-alert-badge rh-alert-badge--amber">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        {{ $lowStockItems }} {{ Str::plural('ingredient', $lowStockItems) }} low on stock
                    </a>
                @endif
                @if($draftPayrolls > 0)
                    <a href="{{ route('payroll.index') }}" class="rh-alert-badge rh-alert-badge--neutral">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                        </svg>
                        {{ $draftPayrolls }} draft {{ Str::plural('payroll', $draftPayrolls) }} pending
                    </a>
                @endif
            </div>
        @endif

        {{-- ── Quick Access ──────────────────────────────────────────── --}}
        <p class="rh-dash-eyebrow" style="margin-top: 2.75rem;">Quick Access</p>
        <div class="rh-modules-grid">
            <a href="{{ route('employees.index') }}" class="rh-module-card" style="--i:1">
                <span class="rh-card-index">01</span>
                <h2 class="rh-card-name">Employees</h2>
                <p class="rh-card-desc">Manage staff records, rates, and branch assignments.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
            <a href="{{ route('attendance.index') }}" class="rh-module-card" style="--i:2">
                <span class="rh-card-index">02</span>
                <h2 class="rh-card-name">Attendance</h2>
                <p class="rh-card-desc">Clock-in/out tracking and daily attendance summaries.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
            <a href="{{ route('sales.index') }}" class="rh-module-card" style="--i:3">
                <span class="rh-card-index">03</span>
                <h2 class="rh-card-name">Sales</h2>
                <p class="rh-card-desc">POS reports, daily summaries, and revenue tracking.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
            <a href="{{ route('expenses.index') }}" class="rh-module-card" style="--i:4">
                <span class="rh-card-index">04</span>
                <h2 class="rh-card-name">Expenses</h2>
                <p class="rh-card-desc">Log and monitor operational expenses across all branches.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
            <a href="{{ route('payroll.index') }}" class="rh-module-card" style="--i:5">
                <span class="rh-card-index">05</span>
                <h2 class="rh-card-name">Payroll</h2>
                <p class="rh-card-desc">Generate payroll periods and review computed pay.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
            <a href="{{ route('inventory.index') }}" class="rh-module-card" style="--i:6">
                <span class="rh-card-index">06</span>
                <h2 class="rh-card-name">Inventory</h2>
                <p class="rh-card-desc">Track ingredients, stock levels, and reorder alerts.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>
        </div>

    </div>

</x-app-layout>
