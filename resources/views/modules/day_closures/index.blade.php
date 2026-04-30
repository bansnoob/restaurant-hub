<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cash Report</h2>
    </x-slot>

    @php
        $isOwner = (bool) auth()->user()?->hasRole('owner');
        $hasFilters = ! empty($filters['branch_id']);
        $rangeLabel = \Carbon\Carbon::parse($filters['date_from'])->format('M j').' – '.\Carbon\Carbon::parse($filters['date_to'])->format('M j, Y');
    @endphp

    <div class="rh-cash-page">
        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)"><span>{{ session('error') }}</span></div>
        @endif

        {{-- Top bar --}}
        <div class="rh-pay-topbar">
            <div>
                <h1 class="rh-pay-title">Cash Report</h1>
                <p class="rh-pay-sub">{{ strtoupper($rangeLabel) }} · {{ $totals['days_closed'] }} {{ \Illuminate\Support\Str::plural('day', $totals['days_closed']) }} closed</p>
            </div>
        </div>

        {{-- Stats strip --}}
        <div class="rh-pay-stats">
            <div class="rh-pay-stat" style="--i:1;">
                <p class="rh-pay-stat-label">Cash on Hand</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--success">₱{{ number_format($totals['cash_on_hand'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:2;">
                <p class="rh-pay-stat-label">Expected</p>
                <p class="rh-pay-stat-value">₱{{ number_format($totals['expected_total'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:3;">
                <p class="rh-pay-stat-label">Variance</p>
                <p class="rh-pay-stat-value {{ $totals['variance_total'] < 0 ? 'rh-pay-stat-value--warn' : ($totals['variance_total'] > 0 ? 'rh-pay-stat-value--accent' : 'rh-pay-stat-value--success') }}">
                    {{ $totals['variance_total'] >= 0 ? '+' : '' }}₱{{ number_format($totals['variance_total'], 2) }}
                </p>
            </div>
            <div class="rh-pay-stat" style="--i:4;">
                <p class="rh-pay-stat-label">Cash Expenses</p>
                <p class="rh-pay-stat-value">₱{{ number_format($totals['cash_expenses_total'], 2) }}</p>
            </div>
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('day-closures.index') }}" class="rh-pay-toolbar" x-ref="filterForm">
            <div class="rh-pay-toolbar-row">
                <select name="branch_id" class="rh-pay-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <span class="rh-att2-summary-picker-label">From</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="rh-att2-date" onchange="this.form.submit()">
                <span class="rh-att2-summary-picker-label">To</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="rh-att2-date" onchange="this.form.submit()">
                @if ($hasFilters || $filters['date_from'] !== now()->subDays(29)->toDateString() || $filters['date_to'] !== now()->toDateString())
                    <a href="{{ route('day-closures.index') }}" class="rh-emp-clear">Reset</a>
                @endif
            </div>
        </form>

        {{-- Closures table --}}
        @if ($closures->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No closures yet</p>
                    <p style="font-size: 0.82rem;">Day closures recorded from the dashboard will appear here.</p>
                </div>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="rh-cash-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>By</th>
                            <th class="num">Cash Sales</th>
                            <th class="num">Cash Exp.</th>
                            <th class="num">Expected</th>
                            <th class="num">Counted</th>
                            <th class="center">Variance</th>
                            @if ($isOwner)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($closures as $closure)
                            @php
                                $variance = (float) $closure->variance;
                                if (abs($variance) < 0.01) {
                                    $varClass = 'rh-cash-variance-pill--match';
                                    $varLabel = 'Match';
                                } elseif ($variance < 0) {
                                    $varClass = 'rh-cash-variance-pill--short';
                                    $varLabel = '−₱'.number_format(abs($variance), 2);
                                } else {
                                    $varClass = 'rh-cash-variance-pill--over';
                                    $varLabel = '+₱'.number_format($variance, 2);
                                }
                                $totalCashSales = (float) $closure->cash_sales_total + (float) $closure->mixed_cash_total;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ \Carbon\Carbon::parse($closure->closed_at_date)->format('M j') }}</strong>
                                    <span style="display: block; font-family: var(--rh-font-mono); font-size: 0.6rem; color: var(--rh-text-muted); margin-top: 0.15rem;">{{ \Carbon\Carbon::parse($closure->closed_at_date)->format('Y') }}</span>
                                </td>
                                <td>{{ $closure->branch?->name ?? '—' }}</td>
                                <td style="font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted);">{{ $closure->closedBy?->name ?? '—' }}</td>
                                <td class="num num--success">₱{{ number_format($totalCashSales, 2) }}</td>
                                <td class="num num--warn">₱{{ number_format((float) $closure->cash_expenses_total, 2) }}</td>
                                <td class="num">₱{{ number_format((float) $closure->expected_cash, 2) }}</td>
                                <td class="num num--accent">₱{{ number_format((float) $closure->counted_cash, 2) }}</td>
                                <td class="center"><span class="rh-cash-variance-pill {{ $varClass }}">{{ $varLabel }}</span></td>
                                @if ($isOwner)
                                    <td>
                                        <form method="POST" action="{{ route('day-close.destroy', $closure) }}" onsubmit="return confirm('Reopen this day? The closure record will be removed.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" style="font-family: var(--rh-font-mono); font-size: 0.6rem; letter-spacing: 0.06em; color: var(--rh-error-text); background: transparent; border: 1px solid var(--rh-error-border); padding: 0.3rem 0.6rem; border-radius: 5px; cursor: pointer;">Reopen</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $closures->links() }}</div>
        @endif
    </div>
</x-app-layout>
