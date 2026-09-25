<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Special Expenses</h2>
    </x-slot>

    @php
        $maxCategoryTotal = collect($categoryBreakdown)->max('total') ?: 1;
        $hasActiveFilters = ! empty($filters['branch_id']) || ! empty($filters['special_expense_category_id']);
        $methodLabels = [
            'cash' => 'Cash',
            'gcash' => 'GCash',
            'bank_transfer' => 'Bank',
            'other' => 'Other',
        ];
        $previousLabel = \Carbon\Carbon::parse($filters['month'])->subMonth()->format('M Y');
    @endphp

    <div
        class="rh-exp-page"
        x-data="specialExpensesPage({
            updateUrlTemplate: @js(route('special-expenses.update', ['specialExpense' => '__SPECIAL__'])),
            destroyUrlTemplate: @js(route('special-expenses.destroy', ['specialExpense' => '__SPECIAL__'])),
            csrfToken: @js(csrf_token()),
            currentMonth: @js($filters['month']),
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
        <div class="rh-exp-topbar">
            <div>
                <h1 class="rh-exp-title">Special Expenses</h1>
                <p class="rh-exp-sub">{{ strtoupper($filters['month_label']) }} · MONTHLY OVERHEAD</p>
            </div>
            <button type="button" class="rm-btn rm-btn--primary" @click="openCreate()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                Record Overhead
            </button>
        </div>

        {{-- Daily / Special tabs --}}
        <nav class="rh-exp-tabs" aria-label="Expense type">
            <a href="{{ route('expenses.index') }}" class="rh-exp-tab">
                Daily
                <span class="rh-exp-tab-hint">Operations</span>
            </a>
            <a href="{{ route('special-expenses.index') }}" class="rh-exp-tab rh-exp-tab--on" aria-current="page">
                Special
                <span class="rh-exp-tab-hint">Rent · Utilities</span>
            </a>
        </nav>

        {{-- Explains why these numbers never appear in the daily figures. Without
             it the obvious reading of an empty Expenses total is "the app lost my
             rent", and someone eventually "fixes" that by merging the tables. --}}
        <p class="rh-exp-note">
            Rent, electricity and other fixed monthly costs. These are kept out of daily
            expenses, today's net income and the end-of-day cash count on purpose — they
            are counted once per month, on the dashboard's <strong>Net after OH</strong>.
        </p>

        {{-- Month picker --}}
        <form method="GET" action="{{ route('special-expenses.index') }}" class="rh-exp-presets" x-ref="filterForm">
            <select name="month" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                @foreach ($monthOptions as $option)
                    <option value="{{ $option['value'] }}" {{ $filters['month'] === $option['value'] ? 'selected' : '' }}>{{ $option['label'] }}</option>
                @endforeach
            </select>
            <select name="branch_id" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                @endforeach
            </select>
            <select name="special_expense_category_id" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" {{ (string) $filters['special_expense_category_id'] === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </select>
            @if ($hasActiveFilters)
                <a href="{{ route('special-expenses.index', ['month' => $filters['month']]) }}" class="rh-emp-clear">Clear</a>
            @endif
        </form>

        {{-- Stats strip --}}
        <div class="rh-exp-stats">
            <div class="rh-exp-stat" style="--i:1;">
                <p class="rh-exp-stat-label">Total</p>
                <p class="rh-exp-stat-value">₱{{ number_format($summary['total'], 2) }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:2;">
                <p class="rh-exp-stat-label">Entries</p>
                <p class="rh-exp-stat-value">{{ $summary['count'] }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:3;">
                <p class="rh-exp-stat-label">{{ $previousLabel }}</p>
                <p class="rh-exp-stat-value">₱{{ number_format($summary['previous_total'], 2) }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:4;">
                <p class="rh-exp-stat-label">Change</p>
                <p class="rh-exp-stat-value {{ $summary['change'] > 0 ? 'rh-exp-stat-value--warn' : 'rh-exp-stat-value--success' }}">
                    {{ $summary['change'] < 0 ? '−' : ($summary['change'] > 0 ? '+' : '') }}₱{{ number_format(abs($summary['change']), 2) }}
                </p>
            </div>
        </div>

        {{-- Category breakdown. Same markup and classes as the daily tab's panel so
             the two tabs read as one page. --}}
        <div class="rh-exp-cat-panel">
            <h3 class="rh-exp-cat-title">By Category</h3>
            @if (empty($categoryBreakdown))
                <p class="rh-exp-cat-empty">No overhead recorded for {{ $filters['month_label'] }}.</p>
            @else
                @foreach ($categoryBreakdown as $idx => $cat)
                    @php
                        $pct = $maxCategoryTotal > 0 ? round(($cat['total'] / $maxCategoryTotal) * 100) : 0;
                        $altIdx = ($idx % 6) + 1;
                    @endphp
                    <div class="rh-exp-cat-row">
                        <span class="rh-exp-cat-label" title="{{ $cat['name'] }}">{{ $cat['name'] }}</span>
                        <span class="rh-exp-cat-track">
                            <span class="rh-exp-cat-fill rh-exp-cat-fill--alt-{{ $altIdx }}" style="width: {{ $pct }}%;"></span>
                        </span>
                        <span class="rh-exp-cat-value">
                            ₱{{ number_format($cat['total'], 0) }}
                            <span class="rh-exp-cat-count">{{ $cat['count'] }} {{ \Illuminate\Support\Str::plural('entry', $cat['count']) }}</span>
                        </span>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- List --}}
        @if ($specialExpenses->isEmpty())
            <div class="rh-exp-list">
                <div class="rh-exp-empty">
                    <svg class="rh-exp-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M3 21h18"/>
                        <path d="M5 21V7l7-4 7 4v14"/>
                        <path d="M10 21v-6h4v6"/>
                    </svg>
                    <p class="rh-exp-empty-title">No overhead recorded for {{ $filters['month_label'] }}</p>
                    <p style="font-size: 0.82rem;">Click <strong>Record Overhead</strong> to add rent or a utility bill.</p>
                </div>
            </div>
        @else
            <div class="rh-exp-list">
                @foreach ($specialExpenses as $item)
                    @php
                        $hasCategory = (bool) $item->category;
                        $methodKey = $item->payment_method;
                        $methodLabel = $methodLabels[$methodKey] ?? ucfirst($methodKey);
                        $payload = [
                            'id' => $item->id,
                            'branch_id' => $item->branch_id,
                            'special_expense_category_id' => $item->special_expense_category_id,
                            'period_month' => $item->period_month?->toDateString(),
                            'period_month_label' => $item->period_month?->format('F Y'),
                            'paid_date' => $item->paid_date?->toDateString(),
                            'paid_date_label' => $item->paid_date?->format('M j, Y'),
                            'description' => $item->description,
                            'vendor_name' => $item->vendor_name,
                            'reference_no' => $item->reference_no,
                            'amount' => (float) $item->amount,
                            'payment_method' => $item->payment_method,
                            'notes' => $item->notes,
                            'category_name' => $item->category?->name,
                            'branch_name' => $item->branch?->name,
                        ];
                    @endphp
                    <div
                        class="rh-exp-row"
                        role="button"
                        tabindex="0"
                        @click="openDetail(@js($payload))"
                        @keydown.enter="openDetail(@js($payload))"
                        @keydown.space.prevent="openDetail(@js($payload))"
                    >
                        <span class="rh-exp-date">
                            <strong>{{ $item->period_month?->format('M') }}</strong>
                            {{ $item->period_month?->format('Y') }}
                        </span>
                        <div class="rh-exp-desc">
                            <span class="rh-exp-desc-line">{{ $item->description ?: ($item->category?->name ?? 'Overhead') }}</span>
                            <span class="rh-exp-desc-meta">
                                {{-- Null branch is company-wide, not missing data. --}}
                                {{ $item->branch?->name ?? 'All branches' }}
                                @if ($item->vendor_name) · {{ $item->vendor_name }} @endif
                                @if ($item->paid_date) · Paid {{ $item->paid_date->format('M j') }} @endif
                            </span>
                        </div>
                        <span class="rh-exp-cat-pill {{ $hasCategory ? 'rh-exp-cat-pill--has' : '' }}">{{ $hasCategory ? $item->category->name : 'Uncategorized' }}</span>
                        <span class="rh-exp-method-badge rh-exp-method-badge--{{ $methodKey }}">{{ $methodLabel }}</span>
                        <span class="rh-exp-amount">₱{{ number_format($item->amount, 2) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="rh-exp-pagination">{{ $specialExpenses->links() }}</div>
        @endif

        {{-- Detail drawer --}}
    @include('modules.expenses.partials.special-drawers')
    </div>

    @include('modules.expenses.partials.special-script')
</x-app-layout>
