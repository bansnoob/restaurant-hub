<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Sales</h2>
    </x-slot>

    @php
        $presets = [
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            '7d'        => '7 Days',
            '30d'       => '30 Days',
            'month'     => 'This Month',
        ];
        $statusOptions = ['completed', 'open', 'voided', 'refunded'];

        $orderTypeLabels = [
            'dine_in' => 'Dine-in',
            'takeout' => 'Takeout',
            'delivery' => 'Delivery',
        ];

        $maxChartTotal = collect($dailySeries)->max('total') ?: 1;

        $cashTotal = $paymentBreakdown['cash']['total'] ?? 0;
        $gcashTotal = $paymentBreakdown['gcash']['total'] ?? 0;
        $maxPayment = max($cashTotal, $gcashTotal, 1);

        $maxOrderTypeRev = collect($orderTypeBreakdown)->max('total') ?: 1;

        $rangeLabel = \Carbon\Carbon::parse($filters['date_from'])->format('M j').' – '.\Carbon\Carbon::parse($filters['date_to'])->format('M j, Y');

        $hasActiveFilters = $filters['preset'] !== ''
            || $filters['search'] !== ''
            || ! empty($filters['statuses'])
            || $filters['payment_method'] !== ''
            || ! empty($filters['branch_id']);
    @endphp

    <div
        class="rh-sal-page"
        x-data="salesPage({
            detailUrlTemplate: @js(route('sales.show', ['sale' => '__SALE__'])),
            initialStatuses: @js($filters['statuses']),
        })"
        @keydown.escape.window="closeDetail()"
    >
        {{-- Top bar --}}
        <div class="rh-sal-topbar">
            <div>
                <h1 class="rh-sal-title">Sales</h1>
                <p class="rh-sal-sub">{{ strtoupper($rangeLabel) }}</p>
            </div>
        </div>

        {{-- Date presets + custom range --}}
        <form method="GET" action="{{ route('sales.index') }}" class="rh-sal-presets" x-ref="rangeForm">
            @foreach ($presets as $key => $label)
                <a
                    href="{{ route('sales.index', array_merge(request()->except(['preset', 'date_from', 'date_to', 'page']), ['preset' => $key])) }}"
                    class="rh-sal-preset {{ $filters['preset'] === $key ? 'rh-sal-preset--on' : '' }}"
                >
                    {{ $label }}
                </a>
            @endforeach
            <span class="rh-sal-preset-range">
                <input type="hidden" name="preset" value="">
                @foreach (['branch_id', 'search', 'payment_method'] as $f)
                    @if (! empty($filters[$f]))
                        <input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">
                    @endif
                @endforeach
                @foreach ($filters['statuses'] as $s)
                    <input type="hidden" name="statuses[]" value="{{ $s }}">
                @endforeach
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" onchange="this.form.submit()">
                <span class="rh-sal-preset-range-sep">→</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" onchange="this.form.submit()">
            </span>
        </form>

        {{-- Stats strip --}}
        <div class="rh-sal-stats">
            <div class="rh-sal-stat" style="--i:1;">
                <p class="rh-sal-stat-label">Orders</p>
                <p class="rh-sal-stat-value">{{ number_format($summary['orders']) }}</p>
            </div>
            <div class="rh-sal-stat" style="--i:2;">
                <p class="rh-sal-stat-label">Sales</p>
                <p class="rh-sal-stat-value rh-sal-stat-value--success">₱{{ number_format($summary['net_sales'], 2) }}</p>
            </div>
            <div class="rh-sal-stat" style="--i:3;">
                <p class="rh-sal-stat-label">Cash</p>
                <p class="rh-sal-stat-value rh-sal-stat-value--success">₱{{ number_format($paymentBreakdown['cash']['total'], 2) }}</p>
            </div>
            <div class="rh-sal-stat" style="--i:4;">
                <p class="rh-sal-stat-label">GCash</p>
                <p class="rh-sal-stat-value rh-sal-stat-value--accent">₱{{ number_format($paymentBreakdown['gcash']['total'], 2) }}</p>
            </div>
        </div>

        {{-- Daily chart --}}
        <div class="rh-sal-chart">
            <div class="rh-sal-chart-head">
                <h3 class="rh-sal-chart-title">Daily Trend</h3>
                <span class="rh-sal-chart-meta">{{ count($dailySeries) }} days · max ₱{{ number_format($maxChartTotal, 0) }}</span>
            </div>
            <div class="rh-sal-chart-body">
                @foreach ($dailySeries as $day)
                    @php
                        $h = $maxChartTotal > 0 ? max(2, (int) round(($day['total'] / $maxChartTotal) * 120)) : 2;
                    @endphp
                    <div class="rh-sal-chart-col">
                        <div
                            class="rh-sal-bar {{ $day['is_today'] ? 'rh-sal-bar--today' : '' }}"
                            style="height: {{ $h }}px;"
                            title="{{ $day['label'] }}: ₱{{ number_format($day['total'], 0) }} · {{ $day['orders'] }} orders"
                        ></div>
                    </div>
                @endforeach
            </div>
            <div class="rh-sal-chart-labels">
                @foreach ($dailySeries as $day)
                    <span class="rh-sal-chart-label {{ $day['is_today'] ? 'rh-sal-chart-label--today' : '' }}">{{ $day['short'] }}</span>
                @endforeach
            </div>
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('sales.index') }}" class="rh-sal-toolbar" x-ref="filterForm">
            <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
            <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
            <input type="hidden" name="preset" value="{{ $filters['preset'] }}">
            <div class="rh-sal-toolbar-row">
                <label class="rh-sal-search">
                    <svg class="rh-sal-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Order number…"
                        x-on:input.debounce.350ms="$refs.filterForm.requestSubmit()"
                    >
                </label>

                <select name="branch_id" class="rh-sal-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>

                <select name="payment_method" class="rh-sal-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All payments</option>
                    <option value="cash"   {{ $filters['payment_method'] === 'cash'   ? 'selected' : '' }}>Cash</option>
                    <option value="gcash"  {{ $filters['payment_method'] === 'gcash'  ? 'selected' : '' }}>GCash</option>
                    <option value="mixed"  {{ $filters['payment_method'] === 'mixed'  ? 'selected' : '' }}>Mixed</option>
                    <option value="unpaid" {{ $filters['payment_method'] === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                </select>

                <div class="rh-sal-status-chips">
                    @foreach ($statusOptions as $status)
                        @php $on = in_array($status, $filters['statuses'], true); @endphp
                        <label class="rh-sal-chip {{ $on ? 'rh-sal-chip--on' : '' }}">
                            <input type="checkbox" name="statuses[]" value="{{ $status }}" {{ $on ? 'checked' : '' }} hidden @change="$refs.filterForm.requestSubmit()">
                            {{ ucfirst($status) }}
                        </label>
                    @endforeach
                </div>

                @if ($hasActiveFilters)
                    <a href="{{ route('sales.index') }}" class="rh-emp-clear">Clear</a>
                @endif
            </div>
        </form>

        {{-- Sales list --}}
        @if ($sales->isEmpty())
            <div class="rh-sal-list">
                <div class="rh-sal-empty">
                    <svg class="rh-sal-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M3 5h2l3 11h13l3-7H6"/>
                        <circle cx="9" cy="20" r="1.5"/>
                        <circle cx="18" cy="20" r="1.5"/>
                    </svg>
                    <p class="rh-sal-empty-title">No orders found</p>
                    <p style="font-size: 0.82rem;">Try widening the date range or clearing filters.</p>
                </div>
            </div>
        @else
            <div class="rh-sal-list">
                @foreach ($sales as $sale)
                    @php
                        $statusClass = 'rh-sal-pill--'.$sale->status;
                        $typeClass = 'rh-sal-pill--'.$sale->order_type;
                        $payClass = 'rh-sal-pay-badge--'.$sale->payment_method;
                        $isVoided = in_array($sale->status, ['voided', 'refunded'], true);
                    @endphp
                    <div
                        class="rh-sal-row {{ $isVoided ? 'rh-sal-row--'.$sale->status : '' }}"
                        role="button"
                        tabindex="0"
                        @click="openDetail({{ $sale->id }})"
                        @keydown.enter="openDetail({{ $sale->id }})"
                        @keydown.space.prevent="openDetail({{ $sale->id }})"
                    >
                        <div>
                            <span class="rh-sal-order-id">{{ $sale->order_number }}</span>
                            <span class="rh-sal-order-time">{{ \Carbon\Carbon::parse($sale->sale_datetime)->format('M j · h:i A') }}</span>
                        </div>
                        <span class="rh-sal-pill {{ $typeClass }}">{{ str_replace('_', '-', $sale->order_type) }}</span>
                        <span class="rh-sal-pill {{ $statusClass }}">{{ $sale->status }}</span>
                        <span class="rh-sal-pay-badge {{ $payClass }}">{{ $sale->payment_method === 'gcash' ? 'GCash' : ucfirst($sale->payment_method) }}</span>
                        <span class="rh-sal-items-count">{{ $sale->sale_items_count }} {{ \Illuminate\Support\Str::plural('item', $sale->sale_items_count) }}</span>
                        <span class="rh-sal-row-total">₱{{ number_format($sale->grand_total, 2) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="rh-sal-pagination">{{ $sales->links() }}</div>
        @endif

        {{-- Detail drawer --}}
        <template x-if="detailOpen">
            <div class="rm-overlay" @click.self="closeDetail()">
                <div class="rm-drawer rm-drawer--wide">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="detailData ? detailData.sale.order_number : 'Loading…'"></h2>
                            <p class="rm-page-sub" x-show="detailData" x-text="detailData ? detailData.sale.sale_datetime_label + ' · ' + (detailData.sale.branch ? detailData.sale.branch.name : '—') : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDetail()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="!detailData">
                            <div class="rh-sal-detail-loading">Loading…</div>
                        </template>
                        <template x-if="detailData">
                            <div>
                                {{-- Status row --}}
                                <div class="rh-sal-detail-section">
                                    <div class="rh-sal-detail-grid">
                                        <div>
                                            <span class="rh-sal-detail-item-label">Status</span>
                                            <span class="rh-sal-detail-item-value">
                                                <span class="rh-sal-pill" :class="'rh-sal-pill--' + detailData.sale.status" x-text="detailData.sale.status"></span>
                                            </span>
                                        </div>
                                        <div>
                                            <span class="rh-sal-detail-item-label">Order Type</span>
                                            <span class="rh-sal-detail-item-value">
                                                <span class="rh-sal-pill" :class="'rh-sal-pill--' + detailData.sale.order_type" x-text="(detailData.sale.order_type || '').replace('_', '-')"></span>
                                            </span>
                                        </div>
                                        <div>
                                            <span class="rh-sal-detail-item-label">Cashier</span>
                                            <span class="rh-sal-detail-item-value" :class="{'rh-sal-detail-item-value--muted': !detailData.sale.cashier}" x-text="detailData.sale.cashier ? detailData.sale.cashier.name : '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-sal-detail-item-label">Table</span>
                                            <span class="rh-sal-detail-item-value" :class="{'rh-sal-detail-item-value--muted': !detailData.sale.table_label}" x-text="detailData.sale.table_label || '—'"></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Items --}}
                                <div class="rh-sal-detail-section">
                                    <p class="rh-sal-detail-label">Line Items <span x-text="'· ' + detailData.items.length"></span></p>
                                    <div class="rh-sal-items">
                                        <template x-for="item in detailData.items" :key="item.id">
                                            <div class="rh-sal-item">
                                                <span class="rh-sal-item-name" x-text="item.item_name"></span>
                                                <span class="rh-sal-item-qty" x-text="Number(item.quantity) + ' × ₱' + Number(item.unit_price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                                <span class="rh-sal-item-total" x-text="'₱' + Number(item.line_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                            </div>
                                        </template>
                                        <template x-if="detailData.items.length === 0">
                                            <div class="rh-sal-item" style="justify-content: center; color: var(--rh-text-muted); font-style: italic;">No line items</div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Totals --}}
                                <div class="rh-sal-detail-section">
                                    <p class="rh-sal-detail-label">Totals</p>
                                    <div class="rh-sal-totals">
                                        <div class="rh-sal-total-row">
                                            <span>Subtotal</span>
                                            <strong x-text="'₱' + Number(detailData.sale.sub_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                        </div>
                                        <div class="rh-sal-total-row" x-show="detailData.sale.discount_total > 0">
                                            <span>Discount</span>
                                            <strong x-text="'−₱' + Number(detailData.sale.discount_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                        </div>
                                        <div class="rh-sal-total-row" x-show="detailData.sale.tax_total > 0">
                                            <span>Tax</span>
                                            <strong x-text="'₱' + Number(detailData.sale.tax_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                        </div>
                                        <div class="rh-sal-total-row rh-sal-total-row--grand">
                                            <span>Grand Total</span>
                                            <strong x-text="'₱' + Number(detailData.sale.grand_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong>
                                        </div>
                                    </div>
                                </div>

                                {{-- Payment split --}}
                                <div class="rh-sal-detail-section">
                                    <p class="rh-sal-detail-label">Payment <span x-text="'· ' + (detailData.sale.payment_method === 'gcash' ? 'GCash' : (detailData.sale.payment_method.charAt(0).toUpperCase() + detailData.sale.payment_method.slice(1)))"></span></p>
                                    <div class="rh-sal-pay-split" x-show="detailData.sale.payment_method !== 'unpaid'">
                                        <div class="rh-sal-pay-cell">
                                            <p class="rh-sal-pay-cell-label">Cash</p>
                                            <p class="rh-sal-pay-cell-value rh-sal-pay-cell-value--cash" x-text="'₱' + Number(detailData.sale.cash_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                                        </div>
                                        <div class="rh-sal-pay-cell">
                                            <p class="rh-sal-pay-cell-label">GCash</p>
                                            <p class="rh-sal-pay-cell-value rh-sal-pay-cell-value--gcash" x-text="'₱' + Number(detailData.sale.gcash_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                                        </div>
                                    </div>
                                    <div x-show="detailData.sale.change_total > 0" style="margin-top: 0.65rem; font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted); letter-spacing: 0.04em;">
                                        Paid ₱<span x-text="Number(detailData.sale.paid_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span> · Change ₱<span x-text="Number(detailData.sale.change_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                    </div>
                                </div>

                                {{-- Notes --}}
                                <div class="rh-sal-detail-section" x-show="detailData.sale.notes">
                                    <p class="rh-sal-detail-label">Notes</p>
                                    <p class="rh-sal-detail-item-value" style="white-space: pre-wrap;" x-text="detailData.sale.notes"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <script>
        function salesPage(config) {
            return {
                detailUrlTemplate: config.detailUrlTemplate,
                detailOpen: false,
                detailData: null,
                detailController: null,
                async openDetail(saleId) {
                    if (this.detailController) this.detailController.abort();
                    this.detailData = null;
                    this.detailOpen = true;
                    this.detailController = new AbortController();
                    try {
                        const url = this.detailUrlTemplate.replace('__SALE__', saleId);
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
            };
        }
    </script>
</x-app-layout>
