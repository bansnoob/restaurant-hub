<x-app-layout>

    @php
        $closureBadgeClass = '';
        $closureBadgeLabel = '';
        $closureCardClass = 'rh-dash-closure--open';
        if (! empty($todayClosure)) {
            $variance = (float) $todayClosure->variance;
            if (abs($variance) < 0.01) {
                $closureBadgeClass = 'rh-close-status-badge--match';
                $closureBadgeLabel = 'Closed · Match';
                $closureCardClass = 'rh-dash-closure--match';
            } elseif ($variance < 0) {
                $closureBadgeClass = 'rh-close-status-badge--short';
                $closureBadgeLabel = 'Closed · Short ₱'.number_format(abs($variance), 0);
                $closureCardClass = 'rh-dash-closure--short';
            } else {
                $closureBadgeClass = 'rh-close-status-badge--over';
                $closureBadgeLabel = 'Closed · Over ₱'.number_format($variance, 0);
                $closureCardClass = 'rh-dash-closure--over';
            }
        }
    @endphp

    <div
        class="rh-dashboard"
        x-data="dayCloseDashboard({
            previewUrl: @js(route('day-close.preview')),
            storeUrl: @js(route('day-close.store')),
            csrfToken: @js(csrf_token()),
        })"
        @keydown.escape.window="closeDrawer()"
    >
        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)"><span>{{ session('error') }}</span></div>
        @endif

        {{-- ── Greeting row ──────────────────────────────────────────── --}}
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
            <div class="rh-dash-meta" style="align-items: center;">
                <span class="rh-dash-meta-pill">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    {{ $activeEmployees }} {{ Str::plural('employee', $activeEmployees) }}
                </span>
                <span class="rh-dash-meta-pill">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    {{ $activeBranches }} {{ Str::plural('branch', $activeBranches) }}
                </span>
                @if (! empty($todayClosure))
                    <span class="rh-close-status-badge {{ $closureBadgeClass }}">{{ $closureBadgeLabel }}</span>
                @else
                    <button type="button" class="rh-close-cta" @click="openDrawer()">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="6" width="18" height="13" rx="2"/>
                            <path stroke-linecap="round" d="M3 10h18M7 14h2"/>
                        </svg>
                        Close Day
                    </button>
                @endif
            </div>
        </div>

        <div class="rh-section-rule"></div>

        {{-- ── Hero: Today's Net Income ──────────────────────────────── --}}
        <div class="rh-dash-hero">
            <div class="rh-dash-hero-main">
                <p class="rh-dash-hero-label">Today's Net Income</p>
                <p class="rh-dash-hero-value {{ $todayNetIncome < 0 ? 'rh-dash-hero-value--neg' : '' }}">
                    @if ($todayNetIncome < 0)−@endif₱{{ number_format(abs($todayNetIncome), 2) }}
                </p>
            </div>
            <div class="rh-dash-hero-breakdown">
                <div class="rh-dash-hero-line">
                    <span class="rh-dash-hero-line-label">Revenue</span>
                    <span class="rh-dash-hero-line-value rh-dash-hero-line-value--success">₱{{ number_format($todaySales, 0) }}</span>
                </div>
                <span class="rh-dash-hero-divider">−</span>
                <div class="rh-dash-hero-line">
                    <span class="rh-dash-hero-line-label">Expenses</span>
                    <span class="rh-dash-hero-line-value rh-dash-hero-line-value--danger">₱{{ number_format($todayExpenses, 0) }}</span>
                </div>
            </div>
        </div>

        {{-- ── Stats strip ───────────────────────────────────────────── --}}
        <div class="rh-stat-grid rh-stat-grid--4">
            <div class="rh-stat-card" style="--i:1">
                <p class="rh-stat-card-label">Orders</p>
                <p class="rh-stat-card-value">{{ number_format($todayOrderCount) }}</p>
                <p class="rh-stat-card-sub">{{ $todayCashOrderCount }} cash · {{ $todayGcashOrderCount }} gcash · {{ $todayMixedOrderCount }} mixed</p>
            </div>
            <div class="rh-stat-card" style="--i:2">
                <p class="rh-stat-card-label">Cash on Hand</p>
                <p class="rh-stat-card-value {{ $todayCashOnHand < 0 ? 'rh-stat-card-value--danger' : 'rh-stat-card-value--success' }}">₱{{ number_format($todayCashOnHand, 0) }}</p>
                <p class="rh-stat-card-sub">Cash in − cash out</p>
            </div>
            <div class="rh-stat-card" style="--i:3">
                <p class="rh-stat-card-label">Present</p>
                <p class="rh-stat-card-value">{{ $presentToday }}</p>
                <p class="rh-stat-card-sub">{{ $lateToday > 0 ? $lateToday.' late' : 'On time' }}</p>
            </div>
            <div class="rh-stat-card" style="--i:4">
                <p class="rh-stat-card-label">Absent</p>
                <p class="rh-stat-card-value {{ $absentToday > 0 ? 'rh-stat-value--warn' : '' }}">{{ $absentToday }}</p>
                <p class="rh-stat-card-sub">{{ $absentToday > 0 ? 'No clock-in' : 'Everyone accounted for' }}</p>
            </div>
        </div>

        {{-- ── Closure status panel ──────────────────────────────────── --}}
        @if (! empty($todayClosure))
            <div class="rh-dash-closure {{ $closureCardClass }}" style="margin-top: 1.25rem;">
                <div class="rh-dash-closure-left">
                    <span class="rh-dash-closure-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </span>
                    <div class="rh-dash-closure-text">
                        <span class="rh-dash-closure-title">Day closed at {{ $todayClosure->closed_at?->format('h:i A') }}</span>
                        <span class="rh-dash-closure-meta">{{ $todayClosure->closedBy?->name ?? 'Unknown' }} · {{ $todayClosure->branch?->name ?? '—' }}</span>
                    </div>
                </div>
                <div class="rh-dash-closure-right">
                    <div class="rh-dash-closure-cell">
                        <p class="rh-dash-closure-cell-label">Counted</p>
                        <p class="rh-dash-closure-cell-value">₱{{ number_format((float) $todayClosure->counted_cash, 0) }}</p>
                    </div>
                    <div class="rh-dash-closure-cell">
                        <p class="rh-dash-closure-cell-label">Variance</p>
                        @php $vAbs = abs((float) $todayClosure->variance); @endphp
                        <p class="rh-dash-closure-cell-value" style="color: {{ $vAbs < 0.01 ? 'var(--rh-success-text)' : ((float) $todayClosure->variance < 0 ? 'var(--rh-error-text)' : 'var(--rh-amber-text)') }};">
                            @if ($vAbs < 0.01)
                                ₱0
                            @elseif ((float) $todayClosure->variance < 0)
                                −₱{{ number_format($vAbs, 0) }}
                            @else
                                +₱{{ number_format((float) $todayClosure->variance, 0) }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        @else
            <div class="rh-dash-closure rh-dash-closure--open" style="margin-top: 1.25rem;">
                <div class="rh-dash-closure-left">
                    <span class="rh-dash-closure-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                            <path stroke-linecap="round" d="M12 7v5l3 2"/>
                        </svg>
                    </span>
                    <div class="rh-dash-closure-text">
                        <span class="rh-dash-closure-title">Day not closed yet</span>
                        <span class="rh-dash-closure-meta">Reconcile cash, auto-clock-out staff, lock today's books</span>
                    </div>
                </div>
                <button type="button" class="rh-close-cta" @click="openDrawer()">Close Day Now</button>
            </div>
        @endif

        {{-- ── 7-Day Sales Trend + Payment Method ────────────────────── --}}
        <div class="rh-dash-cols rh-dash-cols--6-4" style="margin-top: 1.5rem;">

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
                <div class="rh-dash-panel-header">
                    <p class="rh-dash-panel-title">Payment Method · Today</p>
                    <a href="{{ route('sales.index') }}" class="rh-dash-panel-link">View sales &rarr;</a>
                </div>
                @if ($todayCashSales == 0 && $todayGcashSales == 0)
                    <p class="rh-dash-pay-empty">No sales yet today.</p>
                @else
                    @php $maxPay = max($todayCashSales, $todayGcashSales, 1); @endphp
                    <div class="rh-dash-pay-row">
                        <span class="rh-dash-pay-label">Cash</span>
                        <span class="rh-dash-pay-track">
                            <span class="rh-dash-pay-fill rh-dash-pay-fill--cash" style="width: {{ round(($todayCashSales / $maxPay) * 100) }}%"></span>
                        </span>
                        <span class="rh-dash-pay-value">
                            ₱{{ number_format($todayCashSales, 0) }}
                            <span class="rh-dash-pay-count">{{ $todayCashOrderCount + $todayMixedOrderCount }} orders</span>
                        </span>
                    </div>
                    <div class="rh-dash-pay-row">
                        <span class="rh-dash-pay-label">GCash</span>
                        <span class="rh-dash-pay-track">
                            <span class="rh-dash-pay-fill rh-dash-pay-fill--gcash" style="width: {{ round(($todayGcashSales / $maxPay) * 100) }}%"></span>
                        </span>
                        <span class="rh-dash-pay-value">
                            ₱{{ number_format($todayGcashSales, 0) }}
                            <span class="rh-dash-pay-count">{{ $todayGcashOrderCount + $todayMixedOrderCount }} orders</span>
                        </span>
                    </div>
                    @if ($todayMixedOrderCount > 0)
                        <p style="font-family: var(--rh-font-mono); font-size: 0.6rem; color: var(--rh-text-muted); margin-top: 0.85rem; padding-top: 0.65rem; border-top: 1px solid var(--rh-border); letter-spacing: 0.04em;">
                            Mixed payments split into both totals · {{ $todayMixedOrderCount }} mixed order{{ $todayMixedOrderCount === 1 ? '' : 's' }}
                        </p>
                    @endif
                @endif
            </div>

        </div>

        {{-- ── Recent Sales + Recent Expenses ────────────────────────── --}}
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

        {{-- ── This Month + Attendance ───────────────────────────────── --}}
        <div class="rh-dash-cols rh-dash-cols--6-4" style="margin-top: 1.5rem;">

            <div class="rh-dash-panel">
                <div class="rh-dash-panel-header">
                    <p class="rh-dash-panel-title">{{ now()->format('F Y') }}</p>
                    <span class="rh-dash-panel-link" style="cursor: default; opacity: 0.6;">Month-to-date</span>
                </div>
                <div class="rh-stat-grid rh-stat-grid--3" style="border: none; background: transparent; margin: 0;">
                    <div class="rh-stat-card" style="--i:1; padding: 0.5rem 0;">
                        <p class="rh-stat-card-label">Revenue</p>
                        <p class="rh-stat-card-value rh-stat-value--accent">₱{{ number_format($mtdSales, 0) }}</p>
                    </div>
                    <div class="rh-stat-card" style="--i:2; padding: 0.5rem 0;">
                        <p class="rh-stat-card-label">Expenses</p>
                        <p class="rh-stat-card-value">₱{{ number_format($mtdExpenses, 0) }}</p>
                    </div>
                    <div class="rh-stat-card" style="--i:3; padding: 0.5rem 0;">
                        <p class="rh-stat-card-label">Net</p>
                        <p class="rh-stat-card-value {{ ($mtdSales - $mtdExpenses) < 0 ? 'rh-stat-value--warn' : 'rh-stat-card-value--success' }}">
                            ₱{{ number_format($mtdSales - $mtdExpenses, 0) }}
                        </p>
                    </div>
                </div>
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

        {{-- ── Alerts ────────────────────────────────────────────────── --}}
        @if($lowStockItems > 0 || $draftPayrolls > 0)
            <div class="rh-dash-alerts" style="margin-top: 1.5rem;">
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

        {{-- Close Day drawer --}}
        <template x-if="drawerOpen">
            <div class="rm-overlay" @click.self="closeDrawer()">
                <form
                    method="POST"
                    :action="storeUrl"
                    class="rm-drawer rm-drawer--wide"
                    @submit="submitting = true"
                >
                    <input type="hidden" name="_token" :value="csrfToken">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title">Close Day</h2>
                            <p class="rm-page-sub" x-show="data" x-text="data ? (data.date_label + ' · ' + data.branch.name) : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDrawer()">×</button>
                    </div>

                    <div class="rm-drawer-body">
                        <template x-if="!data">
                            <div class="rh-emp-detail-loading">Loading…</div>
                        </template>

                        <template x-if="data && data.already_closed">
                            <div class="rh-close-already">
                                <h3 class="rh-close-already-title">Already closed today</h3>
                                <p class="rh-close-already-meta">
                                    Closed at <span x-text="data.already_closed.closed_at_label"></span>
                                    <span x-show="data.already_closed.closed_by"> by <span x-text="data.already_closed.closed_by"></span></span>
                                </p>
                                <div class="rh-close-already-grid">
                                    <div>
                                        <p class="rh-close-already-cell-label">Counted</p>
                                        <p class="rh-close-already-cell-value" x-text="'₱' + Number(data.already_closed.counted_cash).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                                    </div>
                                    <div>
                                        <p class="rh-close-already-cell-label">Expected</p>
                                        <p class="rh-close-already-cell-value" x-text="'₱' + Number(data.already_closed.expected_cash).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                                    </div>
                                    <div>
                                        <p class="rh-close-already-cell-label">Variance</p>
                                        <p class="rh-close-already-cell-value" :style="data.already_closed.variance < 0 ? 'color: var(--rh-error-text);' : (data.already_closed.variance > 0 ? 'color: var(--rh-amber-text);' : 'color: var(--rh-success-text);')" x-text="(data.already_closed.variance > 0 ? '+' : '') + '₱' + Number(data.already_closed.variance).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-if="data && !data.already_closed">
                            <div>
                                <input type="hidden" name="branch_id" :value="data.branch.id">
                                <input type="hidden" name="closed_at_date" :value="data.date">

                                <div class="rm-field">
                                    <label class="rm-field-label">Branch</label>
                                    <select class="rm-input" x-model.number="selectedBranchId" @change="reloadPreview()">
                                        <template x-for="b in data.available_branches" :key="b.id">
                                            <option :value="b.id" x-text="b.name"></option>
                                        </template>
                                    </select>
                                </div>

                                <div class="rh-close-breakdown">
                                    <div class="rh-close-line">
                                        <span>+ Cash sales <span style="opacity: 0.6;" x-text="'(' + data.totals.order_count + ' orders)'"></span></span>
                                        <strong x-text="'₱' + Number(data.totals.cash_sales_total + data.totals.mixed_cash_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                    </div>
                                    <div class="rh-close-line rh-close-line--neg" x-show="data.totals.cash_expenses_total > 0">
                                        <span>− Cash expenses <span style="opacity: 0.6;" x-text="'(' + data.totals.expense_count + ')'"></span></span>
                                        <strong x-text="'−₱' + Number(data.totals.cash_expenses_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                    </div>
                                    <div class="rh-close-line rh-close-line--total">
                                        <span>Expected in drawer</span>
                                        <strong x-text="'₱' + Number(expectedCash).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                    </div>
                                    <div class="rh-close-line" x-show="data.totals.gcash_sales_total > 0" style="opacity: 0.7;">
                                        <span>GCash today (no count needed)</span>
                                        <strong x-text="'₱' + Number(data.totals.gcash_sales_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                    </div>
                                </div>

                                <div class="rh-close-counted">
                                    <label class="rm-field-label">Counted Cash</label>
                                    <input type="number" step="0.01" min="0" name="counted_cash" class="rh-close-counted-input" x-model.number="countedCash" placeholder="0.00" required>
                                    <div
                                        class="rh-close-variance"
                                        :class="varianceClass()"
                                        x-show="countedCash !== '' && countedCash !== null"
                                    >
                                        <span x-text="varianceLabel()"></span>
                                        <strong x-show="Math.abs(varianceValue()) > 0.001" x-text="'₱' + Number(Math.abs(varianceValue())).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                    </div>
                                </div>

                                {{-- Still clocked in employees: silently auto-clock-out at close --}}
                                <template x-for="emp in data.still_clocked_in" :key="emp.attendance_id">
                                    <input type="hidden" name="auto_clockout_attendance_ids[]" :value="emp.attendance_id">
                                </template>

                                <div class="rm-field">
                                    <label class="rm-field-label">Notes <span class="rm-field-opt">(optional)</span></label>
                                    <textarea name="notes" class="rm-input rm-textarea" x-model="notes" rows="2" placeholder="Anything unusual?"></textarea>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeDrawer()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting || !data || data.already_closed || countedCash === '' || countedCash === null">Close Day</button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

    </div>

    <script>
        function dayCloseDashboard(config) {
            return {
                previewUrl: config.previewUrl,
                storeUrl: config.storeUrl,
                csrfToken: config.csrfToken,
                drawerOpen: false,
                data: null,
                submitting: false,
                selectedBranchId: '',
                countedCash: '',
                notes: '',
                forcedClockoutIds: [],
                async openDrawer() {
                    this.drawerOpen = true;
                    this.data = null;
                    this.countedCash = '';
                    this.notes = '';
                    await this.loadPreview();
                },
                async loadPreview() {
                    try {
                        const url = new URL(this.previewUrl, window.location.origin);
                        if (this.selectedBranchId) url.searchParams.set('branch_id', this.selectedBranchId);
                        const res = await fetch(url, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        const data = await res.json();
                        this.data = data;
                        this.selectedBranchId = data.branch.id;
                        this.forcedClockoutIds = data.still_clocked_in.map(e => e.attendance_id);
                    } catch (err) {
                        this.drawerOpen = false;
                        console.error(err);
                    }
                },
                async reloadPreview() {
                    this.data = null;
                    await this.loadPreview();
                },
                get expectedCash() {
                    if (!this.data) return 0;
                    return Number(this.data.totals.cash_sales_total) + Number(this.data.totals.mixed_cash_total) - Number(this.data.totals.cash_expenses_total);
                },
                varianceValue() {
                    return Number(this.countedCash || 0) - this.expectedCash;
                },
                varianceClass() {
                    const v = this.varianceValue();
                    if (Math.abs(v) < 0.01) return 'rh-close-variance--match';
                    if (v < 0) return 'rh-close-variance--short';
                    return 'rh-close-variance--over';
                },
                varianceLabel() {
                    const v = this.varianceValue();
                    if (Math.abs(v) < 0.01) return '✓ Match';
                    if (v < 0) return 'Short by';
                    return 'Over by';
                },
                closeDrawer() {
                    this.drawerOpen = false;
                    this.submitting = false;
                },
            };
        }
    </script>

</x-app-layout>
