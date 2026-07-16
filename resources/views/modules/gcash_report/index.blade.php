<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">GCash Report</h2>
    </x-slot>

    @php
        $rangeLabel = \Carbon\Carbon::parse($filters['date_from'])->format('M j').' – '.\Carbon\Carbon::parse($filters['date_to'])->format('M j, Y');
        $isFiltered = ! empty($filters['branch_id'])
            || $filters['search'] !== ''
            || $filters['date_from'] !== $defaults['date_from']
            || $filters['date_to'] !== $defaults['date_to'];
    @endphp

    <div class="rh-cash-page">
        {{-- Top bar --}}
        <div class="rh-pay-topbar">
            <div>
                <h1 class="rh-pay-title">GCash Report</h1>
                <p class="rh-pay-sub">
                    {{ strtoupper($rangeLabel) }} ·
                    {{ number_format($totals['transaction_count']) }} {{ \Illuminate\Support\Str::plural('transaction', $totals['transaction_count']) }}
                </p>
            </div>
        </div>

        {{-- Stats strip --}}
        <div class="rh-pay-stats">
            <div class="rh-pay-stat" style="--i:1;">
                <p class="rh-pay-stat-label">GCash Sales</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--success">₱{{ number_format($totals['gcash_sales_total'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:2;">
                <p class="rh-pay-stat-label">GCash Expenses</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--warn">₱{{ number_format($totals['gcash_expenses_total'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:3;">
                <p class="rh-pay-stat-label">Net GCash</p>
                <p class="rh-pay-stat-value {{ $totals['net_gcash'] < 0 ? 'rh-pay-stat-value--warn' : 'rh-pay-stat-value--accent' }}">
                    {{ $totals['net_gcash'] < 0 ? '−' : '' }}₱{{ number_format(abs($totals['net_gcash']), 2) }}
                </p>
            </div>
            <div class="rh-pay-stat" style="--i:4;">
                <p class="rh-pay-stat-label">Transactions</p>
                <p class="rh-pay-stat-value">{{ number_format($totals['transaction_count']) }}</p>
            </div>
        </div>

        {{-- Toolbar --}}
        {{-- Native onchange rather than Alpine: this content sits outside any x-data scope,
             so Alpine directives on the form would never bind. --}}
        <form method="GET" action="{{ route('gcash-report.index') }}" class="rh-pay-toolbar">
            <div class="rh-pay-toolbar-row">
                <select name="branch_id" class="rh-pay-select" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <span class="rh-att2-summary-picker-label">From</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="rh-att2-date" onchange="this.form.submit()">
                <span class="rh-att2-summary-picker-label">To</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="rh-att2-date" onchange="this.form.submit()">
                <label class="rh-pay-search">
                    <svg class="rh-pay-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search by order number…" onchange="this.form.submit()">
                </label>
                @if ($isFiltered)
                    <a href="{{ route('gcash-report.index') }}" class="rh-emp-clear">Reset</a>
                @endif
            </div>
        </form>

        {{-- Transactions table --}}
        @if ($sales->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No GCash transactions</p>
                    <p style="font-size: 0.82rem;">GCash payments taken at the POS will appear here.</p>
                </div>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="rh-cash-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Cashier</th>
                            <th class="center">Type</th>
                            <th class="num">Order Total</th>
                            <th class="num">GCash Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            @php
                                $isMixed = $sale->payment_method === 'mixed';
                                $gcashValue = $sale->gcashValue();
                            @endphp
                            <tr>
                                <td><strong>{{ $sale->order_number }}</strong></td>
                                <td>
                                    {{ $sale->sale_datetime?->format('M j') ?? '—' }}
                                    <span style="display: block; font-family: var(--rh-font-mono); font-size: 0.6rem; color: var(--rh-text-muted); margin-top: 0.15rem;">{{ $sale->sale_datetime?->format('h:i A') }}</span>
                                </td>
                                <td>{{ $sale->branch?->name ?? '—' }}</td>
                                <td style="font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted);">{{ $sale->cashier?->name ?? '—' }}</td>
                                <td class="center">
                                    <span class="rh-cash-variance-pill {{ $isMixed ? 'rh-cash-variance-pill--over' : 'rh-cash-variance-pill--match' }}">
                                        {{ $isMixed ? 'Mixed' : 'GCash' }}
                                    </span>
                                </td>
                                <td class="num">₱{{ number_format((float) $sale->grand_total, 2) }}</td>
                                <td class="num num--accent">₱{{ number_format($gcashValue, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $sales->links() }}</div>
        @endif
    </div>
</x-app-layout>
