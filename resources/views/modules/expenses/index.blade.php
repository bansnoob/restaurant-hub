<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Expenses</h2>
    </x-slot>

    @php
        $presets = [
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            '7d'        => '7 Days',
            '30d'       => '30 Days',
            'month'     => 'This Month',
        ];

        $maxChartTotal = collect($dailySeries)->max('total') ?: 1;
        $maxCategoryTotal = collect($categoryBreakdown)->max('total') ?: 1;
        $rangeLabel = \Carbon\Carbon::parse($filters['date_from'])->format('M j').' – '.\Carbon\Carbon::parse($filters['date_to'])->format('M j, Y');

        $hasActiveFilters = $filters['preset'] !== ''
            || $filters['search'] !== ''
            || ! empty($filters['branch_id'])
            || ! empty($filters['expense_category_id'])
            || $filters['payment_method'] !== '';

        $methodLabels = [
            'cash' => 'Cash',
            'gcash' => 'GCash',
            'bank_transfer' => 'Bank',
            'other' => 'Other',
        ];
    @endphp

    <div
        class="rh-exp-page"
        x-data="expensesPage({
            updateUrlTemplate: @js(route('expenses.update', ['expense' => '__EXPENSE__'])),
            destroyUrlTemplate: @js(route('expenses.destroy', ['expense' => '__EXPENSE__'])),
            detailUrlTemplate: @js(route('expenses.show', ['expense' => '__EXPENSE__'])),
            csrfToken: @js(csrf_token()),
            initialDate: @js($filters['date_from']),
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
                <h1 class="rh-exp-title">Expenses</h1>
                <p class="rh-exp-sub">{{ strtoupper($rangeLabel) }}</p>
            </div>
            <button type="button" class="rm-btn rm-btn--primary" @click="openCreate()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                Record Expense
            </button>
        </div>

        {{-- Date presets --}}
        <form method="GET" action="{{ route('expenses.index') }}" class="rh-exp-presets">
            @foreach ($presets as $key => $label)
                <a
                    href="{{ route('expenses.index', array_merge(request()->except(['preset', 'date_from', 'date_to', 'page']), ['preset' => $key])) }}"
                    class="rh-exp-preset {{ $filters['preset'] === $key ? 'rh-exp-preset--on' : '' }}"
                >{{ $label }}</a>
            @endforeach
            <span class="rh-exp-preset-range">
                <input type="hidden" name="preset" value="">
                @foreach (['branch_id', 'expense_category_id', 'payment_method', 'search'] as $f)
                    @if (! empty($filters[$f]))
                        <input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">
                    @endif
                @endforeach
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" onchange="this.form.submit()">
                <span class="rh-exp-preset-range-sep">→</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" onchange="this.form.submit()">
            </span>
        </form>

        {{-- Stats strip --}}
        <div class="rh-exp-stats">
            <div class="rh-exp-stat" style="--i:1;">
                <p class="rh-exp-stat-label">Total</p>
                <p class="rh-exp-stat-value">₱{{ number_format($summary['total'], 2) }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:2;">
                <p class="rh-exp-stat-label">Cash</p>
                <p class="rh-exp-stat-value rh-exp-stat-value--success">₱{{ number_format($summary['cash'], 2) }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:3;">
                <p class="rh-exp-stat-label">GCash</p>
                <p class="rh-exp-stat-value rh-exp-stat-value--accent">₱{{ number_format($summary['gcash'], 2) }}</p>
            </div>
            <div class="rh-exp-stat" style="--i:4;">
                <p class="rh-exp-stat-label">Bank</p>
                <p class="rh-exp-stat-value rh-exp-stat-value--warn">₱{{ number_format($summary['bank_transfer'], 2) }}</p>
            </div>
        </div>

        {{-- Daily chart --}}
        <div class="rh-exp-chart">
            <div class="rh-exp-chart-head">
                <h3 class="rh-exp-chart-title">Daily Expenses</h3>
                <span class="rh-exp-chart-meta">{{ count($dailySeries) }} days · max ₱{{ number_format($maxChartTotal, 0) }}</span>
            </div>
            <div class="rh-exp-chart-body">
                @foreach ($dailySeries as $day)
                    @php $h = $maxChartTotal > 0 ? max(2, (int) round(($day['total'] / $maxChartTotal) * 120)) : 2; @endphp
                    <div class="rh-exp-chart-col">
                        <div
                            class="rh-exp-bar {{ $day['is_today'] ? 'rh-exp-bar--today' : '' }}"
                            style="height: {{ $h }}px;"
                            title="{{ $day['label'] }}: ₱{{ number_format($day['total'], 0) }} · {{ $day['count'] }} expense{{ $day['count'] === 1 ? '' : 's' }}"
                        ></div>
                    </div>
                @endforeach
            </div>
            <div class="rh-exp-chart-labels">
                @foreach ($dailySeries as $day)
                    <span class="rh-exp-chart-label {{ $day['is_today'] ? 'rh-exp-chart-label--today' : '' }}">{{ $day['short'] }}</span>
                @endforeach
            </div>
        </div>

        {{-- Category breakdown --}}
        <div class="rh-exp-cat-panel">
            <h3 class="rh-exp-cat-title">By Category</h3>
            @if (empty($categoryBreakdown))
                <p class="rh-exp-cat-empty">No expenses in this range yet.</p>
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
                            <span class="rh-exp-cat-count">{{ $cat['count'] }} {{ \Illuminate\Support\Str::plural('expense', $cat['count']) }}</span>
                        </span>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('expenses.index') }}" class="rh-exp-toolbar" x-ref="filterForm">
            <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
            <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
            <input type="hidden" name="preset" value="{{ $filters['preset'] }}">
            <div class="rh-exp-toolbar-row">
                <label class="rh-exp-search">
                    <svg class="rh-exp-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Description, vendor, or reference…"
                        x-on:input.debounce.350ms="$refs.filterForm.requestSubmit()"
                    >
                </label>
                <select name="branch_id" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <select name="expense_category_id" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" {{ (string) $filters['expense_category_id'] === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
                <select name="payment_method" class="rh-exp-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All methods</option>
                    <option value="cash" {{ $filters['payment_method'] === 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="gcash" {{ $filters['payment_method'] === 'gcash' ? 'selected' : '' }}>GCash</option>
                    <option value="bank_transfer" {{ $filters['payment_method'] === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer</option>
                    <option value="other" {{ $filters['payment_method'] === 'other' ? 'selected' : '' }}>Other</option>
                </select>
                @if ($hasActiveFilters)
                    <a href="{{ route('expenses.index') }}" class="rh-emp-clear">Clear</a>
                @endif
            </div>
        </form>

        {{-- Expense list --}}
        @if ($expenses->isEmpty())
            <div class="rh-exp-list">
                <div class="rh-exp-empty">
                    <svg class="rh-exp-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <rect x="3" y="6" width="18" height="14" rx="2"/>
                        <path d="M3 10h18"/>
                    </svg>
                    <p class="rh-exp-empty-title">No expenses recorded</p>
                    <p style="font-size: 0.82rem;">Click <strong>Record Expense</strong> to add one.</p>
                </div>
            </div>
        @else
            <div class="rh-exp-list">
                @foreach ($expenses as $expense)
                    @php
                        $hasCategory = (bool) $expense->category;
                        $methodKey = $expense->payment_method;
                        $methodLabel = $methodLabels[$methodKey] ?? ucfirst($methodKey);
                        $payload = [
                            'id' => $expense->id,
                            'branch_id' => $expense->branch_id,
                            'expense_category_id' => $expense->expense_category_id,
                            'expense_date' => $expense->expense_date,
                            'reference_no' => $expense->reference_no,
                            'vendor_name' => $expense->vendor_name,
                            'description' => $expense->description,
                            'amount' => (float) $expense->amount,
                            'payment_method' => $expense->payment_method,
                            'notes' => $expense->notes,
                            'category_name' => $expense->category?->name,
                            'branch_name' => $expense->branch?->name,
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
                            <strong>{{ \Carbon\Carbon::parse($expense->expense_date)->format('M j') }}</strong>
                            {{ \Carbon\Carbon::parse($expense->expense_date)->format('Y') }}
                        </span>
                        <div class="rh-exp-desc">
                            <span class="rh-exp-desc-line">{{ $expense->description }}</span>
                            <span class="rh-exp-desc-meta">
                                @if ($expense->vendor_name)
                                    {{ $expense->vendor_name }}
                                @endif
                                @if ($expense->vendor_name && $expense->reference_no) · @endif
                                @if ($expense->reference_no)
                                    Ref {{ $expense->reference_no }}
                                @endif
                                @if (! $expense->vendor_name && ! $expense->reference_no)
                                    {{ $expense->branch?->name ?? '—' }}
                                @endif
                            </span>
                        </div>
                        <span class="rh-exp-cat-pill {{ $hasCategory ? 'rh-exp-cat-pill--has' : '' }}">{{ $hasCategory ? $expense->category->name : 'Uncategorized' }}</span>
                        <span class="rh-exp-method-badge rh-exp-method-badge--{{ $methodKey }}">{{ $methodLabel }}</span>
                        <span class="rh-exp-amount">₱{{ number_format($expense->amount, 2) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="rh-exp-pagination">{{ $expenses->links() }}</div>
        @endif

        {{-- Detail drawer --}}
        <template x-if="detailOpen">
            <div class="rm-overlay" @click.self="closeDetail()">
                <div class="rm-drawer rm-drawer--wide">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="detail.description"></h2>
                            <p class="rm-page-sub" x-text="detail.expense_date_label + ' · ' + (detail.branch_name || '—')"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDetail()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        {{-- Amount headline --}}
                        <div class="rh-exp-detail-section">
                            <p class="rh-exp-detail-amount" x-text="'₱' + Number(detail.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                            <p class="rh-exp-detail-amount-sub" x-text="(detail.category_name || 'Uncategorized') + ' · ' + formatMethod(detail.payment_method)"></p>
                        </div>

                        {{-- Details --}}
                        <div class="rh-exp-detail-section">
                            <p class="rh-exp-detail-label">Details</p>
                            <div class="rh-exp-detail-grid">
                                <div>
                                    <span class="rh-exp-detail-item-label">Branch</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.branch_name || '—'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">Date</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.expense_date_label"></span>
                                </div>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div class="rh-exp-detail-section" x-show="detail.notes">
                            <p class="rh-exp-detail-label">Notes</p>
                            <p class="rh-exp-detail-item-value" style="white-space: pre-wrap;" x-text="detail.notes"></p>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--danger" @click="deleteExpense()">Delete</button>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeDetail()">Close</button>
                            <button type="button" class="rm-btn rm-btn--primary" @click="openEditFromDetail()">Edit</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Add/Edit drawer --}}
        <template x-if="formOpen">
            <div class="rm-overlay" @click.self="closeForm()">
                <form
                    method="POST"
                    :action="form.mode === 'edit' ? form.action : '{{ route('expenses.store') }}'"
                    class="rm-drawer rm-drawer--wide"
                    @submit="submitting = true"
                >
                    @csrf
                    <template x-if="form.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="form.mode === 'edit' ? 'Edit Expense' : 'Record Expense'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeForm()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                <select name="branch_id" class="rm-input" x-model="form.branch_id" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Date</label>
                                <input type="date" name="expense_date" class="rm-input" x-model="form.expense_date" required>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Description</label>
                            <input type="text" name="description" class="rm-input" x-model="form.description" required maxlength="200">
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Amount <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="rm-input" x-model="form.amount" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Payment Method</label>
                                <select name="payment_method" class="rm-input" x-model="form.payment_method" required>
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeForm()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="form.mode === 'edit' ? 'Save changes' : 'Save expense'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function expensesPage(config) {
            return {
                updateUrlTemplate: config.updateUrlTemplate,
                destroyUrlTemplate: config.destroyUrlTemplate,
                detailUrlTemplate: config.detailUrlTemplate,
                csrfToken: config.csrfToken,
                detailOpen: false,
                detail: {
                    id: null,
                    branch_id: '',
                    expense_category_id: '',
                    expense_date: config.initialDate,
                    expense_date_label: '',
                    reference_no: '',
                    vendor_name: '',
                    description: '',
                    amount: 0,
                    payment_method: 'cash',
                    notes: '',
                    category_name: '',
                    branch_name: '',
                },
                formOpen: false,
                submitting: false,
                form: {
                    mode: 'create',
                    action: '',
                    branch_id: '',
                    expense_category_id: '',
                    new_category_name: '',
                    expense_date: config.initialDate,
                    reference_no: '',
                    vendor_name: '',
                    description: '',
                    amount: '',
                    payment_method: 'cash',
                    notes: '',
                },
                resetForm() {
                    this.form = {
                        mode: 'create',
                        action: '',
                        branch_id: '',
                        expense_category_id: '',
                        new_category_name: '',
                        expense_date: new Date().toISOString().slice(0, 10),
                        reference_no: '',
                        vendor_name: '',
                        description: '',
                        amount: '',
                        payment_method: 'cash',
                        notes: '',
                    };
                },
                openCreate() {
                    this.resetForm();
                    this.formOpen = true;
                    this.submitting = false;
                },
                openDetail(payload) {
                    this.detail = {
                        id: payload.id,
                        branch_id: payload.branch_id,
                        expense_category_id: payload.expense_category_id,
                        expense_date: payload.expense_date,
                        expense_date_label: this.formatDate(payload.expense_date),
                        reference_no: payload.reference_no,
                        vendor_name: payload.vendor_name,
                        description: payload.description,
                        amount: payload.amount,
                        payment_method: payload.payment_method,
                        notes: payload.notes,
                        category_name: payload.category_name,
                        branch_name: payload.branch_name,
                    };
                    this.detailOpen = true;
                },
                closeDetail() { this.detailOpen = false; },
                openEditFromDetail() {
                    if (!this.detail.id) return;
                    this.form = {
                        mode: 'edit',
                        action: this.updateUrlTemplate.replace('__EXPENSE__', this.detail.id),
                        branch_id: String(this.detail.branch_id ?? ''),
                        expense_category_id: this.detail.expense_category_id ? String(this.detail.expense_category_id) : '',
                        new_category_name: '',
                        expense_date: this.detail.expense_date,
                        reference_no: this.detail.reference_no || '',
                        vendor_name: this.detail.vendor_name || '',
                        description: this.detail.description || '',
                        amount: this.detail.amount,
                        payment_method: this.detail.payment_method || 'cash',
                        notes: this.detail.notes || '',
                    };
                    this.detailOpen = false;
                    this.formOpen = true;
                    this.submitting = false;
                },
                closeForm() {
                    this.formOpen = false;
                    this.submitting = false;
                },
                deleteExpense() {
                    if (!this.detail.id) return;
                    if (!confirm('Delete this expense? This cannot be undone.')) return;
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = this.destroyUrlTemplate.replace('__EXPENSE__', this.detail.id);
                    f.innerHTML = `<input type="hidden" name="_token" value="${this.csrfToken}"><input type="hidden" name="_method" value="DELETE">`;
                    document.body.appendChild(f);
                    f.submit();
                },
                closeAll() {
                    this.detailOpen = false;
                    this.formOpen = false;
                },
                formatDate(d) {
                    if (!d) return '—';
                    try {
                        const date = new Date(d);
                        return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
                    } catch { return d; }
                },
                formatMethod(m) {
                    return ({ cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', other: 'Other' })[m] || m;
                },
            };
        }
    </script>
</x-app-layout>
