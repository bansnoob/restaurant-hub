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
        <template x-if="detailOpen">
            <div class="rm-overlay" @click.self="closeDetail()">
                <div class="rm-drawer rm-drawer--wide">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="detail.description || detail.category_name || 'Overhead'"></h2>
                            <p class="rm-page-sub" x-text="detail.period_month_label + ' · ' + (detail.branch_name || 'All branches')"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeDetail()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rh-exp-detail-section">
                            <p class="rh-exp-detail-amount" x-text="'₱' + Number(detail.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></p>
                            <p class="rh-exp-detail-amount-sub" x-text="(detail.category_name || 'Uncategorized') + ' · ' + formatMethod(detail.payment_method)"></p>
                        </div>
                        <div class="rh-exp-detail-section">
                            <p class="rh-exp-detail-label">Details</p>
                            <div class="rh-exp-detail-grid">
                                <div>
                                    <span class="rh-exp-detail-item-label">Branch</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.branch_name || 'All branches'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">For month</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.period_month_label || '—'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">Paid on</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.paid_date_label || 'Not recorded'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">Vendor</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.vendor_name || '—'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">Reference #</span>
                                    <span class="rh-exp-detail-item-value" x-text="detail.reference_no || '—'"></span>
                                </div>
                                <div>
                                    <span class="rh-exp-detail-item-label">Method</span>
                                    <span class="rh-exp-detail-item-value" x-text="formatMethod(detail.payment_method)"></span>
                                </div>
                            </div>
                        </div>
                        <div class="rh-exp-detail-section" x-show="detail.notes">
                            <p class="rh-exp-detail-label">Notes</p>
                            <p class="rh-exp-detail-item-value" style="white-space: pre-wrap;" x-text="detail.notes"></p>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--danger" @click="deleteItem()">Delete</button>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeDetail()">Close</button>
                            <button type="button" class="rm-btn rm-btn--primary" @click="openEditFromDetail()">Edit</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Create / edit drawer --}}
        <template x-if="formOpen">
            <div class="rm-overlay" @click.self="closeForm()">
                <form
                    class="rm-drawer rm-drawer--wide"
                    method="POST"
                    :action="form.mode === 'edit' ? form.action : @js(route('special-expenses.store'))"
                    @submit="submitting = true"
                >
                    @csrf
                    <template x-if="form.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="form.mode === 'edit' ? 'Edit Special Expense' : 'Record Overhead'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeForm()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                {{-- Blank is a real, meaningful choice here, unlike the daily
                                     form where branch_id is required: overhead may cover the
                                     whole business. --}}
                                <label class="rm-field-label">Branch <span class="rm-field-opt">(blank = all)</span></label>
                                <select name="branch_id" class="rm-input" x-model="form.branch_id">
                                    <option value="">All branches</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rm-field">
                                {{-- Options MUST be server-rendered, not built with x-for.
                                     x-model applies its value by selecting the matching option,
                                     and with x-for the options do not exist yet at that moment,
                                     so the browser falls back to the FIRST one and x-model then
                                     writes that back into the state — editing a March 2024 row
                                     silently re-filed it into the current month. Static options
                                     are already in the DOM when the binding runs, which is why
                                     the branch and category selects below bind correctly.

                                     This is safe because monthOptions() always covers the month
                                     being viewed, and scopeForMonth() is an exact match, so every
                                     row in this list has exactly that month. --}}
                                <label class="rm-field-label">For month</label>
                                <select name="period_month" class="rm-input" x-model="form.period_month" required>
                                    @foreach ($monthOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Category <span class="rm-field-opt">(optional)</span></label>
                                <select name="special_expense_category_id" class="rm-input" x-model="form.special_expense_category_id">
                                    <option value="">No category</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            {{-- Disabled rather than hidden: x-show only sets display:none and a
                                 hidden input still submits. new_category_name wins over the
                                 picker in the controller, so a stale value would file the entry
                                 under a newly created category instead of the chosen one. --}}
                            <div class="rm-field" x-show="!form.special_expense_category_id">
                                <label class="rm-field-label">New category <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="new_category_name" class="rm-input" x-model="form.new_category_name" maxlength="100" placeholder="Creates it if new" :disabled="!! form.special_expense_category_id">
                            </div>
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Amount <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="rm-input" x-model="form.amount" required>
                            </div>
                            <div class="rm-field">
                                {{-- Every method the controller accepts. A select whose bound
                                     value matches no option falls back to its first, silently
                                     rewriting the row on edit. --}}
                                <label class="rm-field-label">Payment Method</label>
                                <select name="payment_method" class="rm-input" x-model="form.payment_method" required>
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Description <span class="rm-field-opt">(optional)</span></label>
                            <input type="text" name="description" class="rm-input" x-model="form.description" maxlength="200" placeholder="e.g. Meralco — August reading">
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Vendor <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="vendor_name" class="rm-input" x-model="form.vendor_name" maxlength="140">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Paid on <span class="rm-field-opt">(optional)</span></label>
                                <input type="date" name="paid_date" class="rm-input" x-model="form.paid_date">
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Reference # <span class="rm-field-opt">(optional)</span></label>
                            <input type="text" name="reference_no" class="rm-input" x-model="form.reference_no" maxlength="60">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Notes <span class="rm-field-opt">(optional)</span></label>
                            <textarea name="notes" class="rm-input rm-textarea" x-model="form.notes" rows="2" maxlength="2000"></textarea>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeForm()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="form.mode === 'edit' ? 'Save changes' : 'Save overhead'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function specialExpensesPage(config) {
            const blankForm = (month) => ({
                mode: 'create',
                action: '',
                branch_id: '',
                special_expense_category_id: '',
                new_category_name: '',
                period_month: month,
                paid_date: '',
                description: '',
                vendor_name: '',
                reference_no: '',
                amount: '',
                payment_method: 'cash',
                notes: '',
            });

            return {
                updateUrlTemplate: config.updateUrlTemplate,
                destroyUrlTemplate: config.destroyUrlTemplate,
                csrfToken: config.csrfToken,
                currentMonth: config.currentMonth,
                detailOpen: false,
                detail: {},
                formOpen: false,
                submitting: false,
                form: blankForm(config.currentMonth),

                openCreate() {
                    this.form = blankForm(this.currentMonth);
                    this.formOpen = true;
                    this.submitting = false;
                },
                openDetail(payload) {
                    this.detail = { ...payload };
                    this.detailOpen = true;
                },
                closeDetail() { this.detailOpen = false; },
                openEditFromDetail() {
                    if (!this.detail.id) return;
                    this.form = {
                        mode: 'edit',
                        action: this.updateUrlTemplate.replace('__SPECIAL__', this.detail.id),
                        branch_id: this.detail.branch_id ? String(this.detail.branch_id) : '',
                        special_expense_category_id: this.detail.special_expense_category_id ? String(this.detail.special_expense_category_id) : '',
                        new_category_name: '',
                        period_month: this.detail.period_month || this.currentMonth,
                        paid_date: this.detail.paid_date || '',
                        description: this.detail.description || '',
                        vendor_name: this.detail.vendor_name || '',
                        reference_no: this.detail.reference_no || '',
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
                deleteItem() {
                    if (!this.detail.id) return;
                    if (!confirm('Delete this special expense? This cannot be undone.')) return;
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = this.destroyUrlTemplate.replace('__SPECIAL__', this.detail.id);
                    f.innerHTML = `<input type="hidden" name="_token" value="${this.csrfToken}"><input type="hidden" name="_method" value="DELETE">`;
                    document.body.appendChild(f);
                    f.submit();
                },
                closeAll() {
                    this.detailOpen = false;
                    this.formOpen = false;
                },
                formatMethod(m) {
                    return ({ cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', other: 'Other' })[m] || m;
                },
            };
        }
    </script>
</x-app-layout>
