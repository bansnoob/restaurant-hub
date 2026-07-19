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
        $defaultBranchId = $filters['branch_id'] ?: (auth()->user()?->branch_id ?: ($branches->first()->id ?? ''));
    @endphp

    <div class="rh-cash-page"
        x-data="gcashReportPage({
            recordUpdateTemplate: @js(route('gcash-report.records.update', ['sale' => '__ID__'])),
            recordDestroyTemplate: @js(route('gcash-report.records.destroy', ['sale' => '__ID__'])),
            expenseUpdateTemplate: @js(route('expenses.update', ['expense' => '__ID__'])),
            expenseDestroyTemplate: @js(route('expenses.destroy', ['expense' => '__ID__'])),
            adjustmentUpdateTemplate: @js(route('gcash-report.adjustments.update', ['gcashAdjustment' => '__ID__'])),
            adjustmentDestroyTemplate: @js(route('gcash-report.adjustments.destroy', ['gcashAdjustment' => '__ID__'])),
            defaultBranchId: @js((string) $defaultBranchId),
            defaultDate: @js($filters['date_to']),
        })"
    >
        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)"><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)"><span>{{ $errors->first() }}</span></div>
        @endif

        {{-- Top bar --}}
        <div class="rh-pay-topbar">
            <div>
                <h1 class="rh-pay-title">GCash Report</h1>
                <p class="rh-pay-sub">
                    {{ strtoupper($rangeLabel) }} ·
                    {{ number_format($totals['transaction_count']) }} {{ \Illuminate\Support\Str::plural('transaction', $totals['transaction_count']) }}
                </p>
            </div>
            <div class="rh-pay-topbar-actions">
                <button type="button" class="rm-btn rm-btn--ghost" @click="openAdjustmentCreate()">Add Adjustment</button>
                <button type="button" class="rm-btn rm-btn--ghost" @click="openExpenseCreate()">Add GCash Expense</button>
                <button type="button" class="rm-btn rm-btn--primary" @click="openRecordCreate()">Add GCash Record</button>
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
                <p class="rh-pay-stat-label">Adjustments</p>
                <p class="rh-pay-stat-value {{ $totals['adjustments_total'] < 0 ? 'rh-pay-stat-value--warn' : '' }}">
                    {{ $totals['adjustments_total'] < 0 ? '−' : '' }}₱{{ number_format(abs($totals['adjustments_total']), 2) }}
                </p>
            </div>
            <div class="rh-pay-stat" style="--i:4;">
                <p class="rh-pay-stat-label">Net GCash</p>
                <p class="rh-pay-stat-value {{ $totals['net_gcash'] < 0 ? 'rh-pay-stat-value--warn' : 'rh-pay-stat-value--accent' }}">
                    {{ $totals['net_gcash'] < 0 ? '−' : '' }}₱{{ number_format(abs($totals['net_gcash']), 2) }}
                </p>
            </div>
        </div>

        {{-- Toolbar. Native onchange rather than Alpine: these controls submit a plain GET
             form, and a native handler keeps working regardless of component scope. --}}
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

        {{-- GCash in --}}
        <h2 class="rh-pay-section-title">GCash In · Sales</h2>
        @if ($sales->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No GCash transactions</p>
                    <p style="font-size: 0.82rem;">GCash payments taken at the POS will appear here, along with any records you add.</p>
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
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            @php
                                $isMixed = $sale->payment_method === 'mixed';
                                $isManual = $sale->isManualGcashRecord();
                                $gcashValue = $sale->gcashValue();
                                $payload = [
                                    'id' => $sale->id,
                                    'branch_id' => (string) $sale->branch_id,
                                    'sale_date' => $sale->sale_datetime?->toDateString(),
                                    'amount' => number_format((float) $sale->grand_total, 2, '.', ''),
                                    'description' => (string) $sale->notes,
                                ];
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
                                    @if ($isManual)
                                        <span class="rh-cash-variance-pill rh-cash-variance-pill--over">Manual</span>
                                    @else
                                        <span class="rh-cash-variance-pill rh-cash-variance-pill--match">{{ $isMixed ? 'Mixed' : 'GCash' }}</span>
                                    @endif
                                </td>
                                <td class="num">₱{{ number_format((float) $sale->grand_total, 2) }}</td>
                                <td class="num num--accent">₱{{ number_format($gcashValue, 2) }}</td>
                                <td>
                                    @if ($isManual)
                                        <div class="rh-gcash-row-actions">
                                            <button type="button" class="rh-gcash-link" @click="openRecordEdit(@js($payload))">Edit</button>
                                            <button type="button" class="rh-gcash-link rh-gcash-link--danger" @click="deleteRecord(@js($payload))">Delete</button>
                                        </div>
                                    @else
                                        {{-- POS orders are edited on the POS: grand_total is the sum of sale_items. --}}
                                        <span class="rh-gcash-muted">POS</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $sales->links() }}</div>
        @endif

        {{-- GCash out --}}
        <h2 class="rh-pay-section-title">GCash Out · Expenses</h2>
        @if ($expenses->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No GCash expenses</p>
                    <p style="font-size: 0.82rem;">Expenses paid by GCash will appear here.</p>
                </div>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="rh-cash-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Category</th>
                            <th class="num">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expenses as $expense)
                            @php
                                $expensePayload = [
                                    'id' => $expense->id,
                                    'branch_id' => (string) $expense->branch_id,
                                    'expense_date' => \Carbon\Carbon::parse($expense->expense_date)->toDateString(),
                                    'amount' => number_format((float) $expense->amount, 2, '.', ''),
                                    'description' => (string) $expense->description,
                                    'expense_category_id' => $expense->expense_category_id ? (string) $expense->expense_category_id : '',
                                    'vendor_name' => (string) $expense->vendor_name,
                                    'reference_no' => (string) $expense->reference_no,
                                    'notes' => (string) $expense->notes,
                                ];
                            @endphp
                            <tr>
                                <td><strong>{{ $expense->description }}</strong></td>
                                <td>{{ \Carbon\Carbon::parse($expense->expense_date)->format('M j, Y') }}</td>
                                <td>{{ $expense->branch?->name ?? '—' }}</td>
                                <td style="font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted);">{{ $expense->category?->name ?? '—' }}</td>
                                <td class="num num--warn">₱{{ number_format((float) $expense->amount, 2) }}</td>
                                <td>
                                    <div class="rh-gcash-row-actions">
                                        <button type="button" class="rh-gcash-link" @click="openExpenseEdit(@js($expensePayload))">Edit</button>
                                        <button type="button" class="rh-gcash-link rh-gcash-link--danger" @click="deleteExpense(@js($expensePayload))">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $expenses->links() }}</div>
        @endif

        {{-- Corrections --}}
        <h2 class="rh-pay-section-title">Adjustments · Correcting Entries</h2>
        @if ($adjustments->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No adjustments</p>
                    <p style="font-size: 0.82rem;">Use an adjustment to deduct GCash that was recorded in error or later reversed. It does not touch the Sales or Expenses pages.</p>
                </div>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="rh-cash-table">
                    <thead>
                        <tr>
                            <th>Reason</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>By</th>
                            <th class="num">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            @php
                                $amount = (float) $adjustment->amount;
                                $adjustmentPayload = [
                                    'id' => $adjustment->id,
                                    'branch_id' => (string) $adjustment->branch_id,
                                    'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                                    // The form edits a positive figure; the sign is the server's.
                                    'amount' => number_format(abs($amount), 2, '.', ''),
                                    'reason' => (string) $adjustment->reason,
                                ];
                            @endphp
                            <tr>
                                <td><strong>{{ $adjustment->reason }}</strong></td>
                                <td>{{ $adjustment->adjustment_date?->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $adjustment->branch?->name ?? '—' }}</td>
                                <td style="font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted);">{{ $adjustment->recordedBy?->name ?? '—' }}</td>
                                <td class="num {{ $amount < 0 ? 'num--danger' : 'num--success' }}">
                                    {{ $amount < 0 ? '−' : '+' }}₱{{ number_format(abs($amount), 2) }}
                                </td>
                                <td>
                                    <div class="rh-gcash-row-actions">
                                        <button type="button" class="rh-gcash-link" @click="openAdjustmentEdit(@js($adjustmentPayload))">Edit</button>
                                        <button type="button" class="rh-gcash-link rh-gcash-link--danger" @click="deleteAdjustment(@js($adjustmentPayload))">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $adjustments->links() }}</div>
        @endif

        {{-- Adjustment drawer --}}
        <template x-if="adjustmentOpen">
            <div class="rm-overlay" @click.self="closeAdjustment()">
                <form method="POST"
                      :action="adjustment.mode === 'edit' ? adjustment.action : '{{ route('gcash-report.adjustments.store') }}'"
                      class="rm-drawer"
                      @submit="submitting = true">
                    @csrf
                    <template x-if="adjustment.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="adjustment.mode === 'edit' ? 'Edit Adjustment' : 'Add Adjustment'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeAdjustment()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <p class="rh-gcash-hint">Deducts from GCash takings without touching the Sales or Expenses pages. Enter the amount to remove as a positive figure — it is subtracted for you. Safe to use on a day that is already closed: the closed day's signed-off totals are left untouched.</p>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                <select name="branch_id" class="rm-input" x-model="adjustment.branch_id" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Date</label>
                                <input type="date" name="adjustment_date" class="rm-input" x-model="adjustment.adjustment_date" required>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Reason</label>
                            <input type="text" name="reason" class="rm-input" x-model="adjustment.reason" required maxlength="200" placeholder="e.g. Reversed — order A-1042 recorded twice">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Amount to deduct <span class="rm-field-opt">(₱)</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="rm-input" x-model="adjustment.amount" required>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeAdjustment()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="adjustment.mode === 'edit' ? 'Save changes' : 'Save adjustment'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- GCash record drawer --}}
        <template x-if="recordOpen">
            <div class="rm-overlay" @click.self="closeRecord()">
                <form method="POST"
                      :action="record.mode === 'edit' ? record.action : '{{ route('gcash-report.records.store') }}'"
                      class="rm-drawer"
                      @submit="submitting = true">
                    @csrf
                    <template x-if="record.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="record.mode === 'edit' ? 'Edit GCash Record' : 'Add GCash Record'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeRecord()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <p class="rh-gcash-hint">Records GCash money received outside the POS. It is saved as a completed GCash sale, so it also counts on the Sales page and toward the day's GCash total.</p>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                <select name="branch_id" class="rm-input" x-model="record.branch_id" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Date</label>
                                <input type="date" name="sale_date" class="rm-input" x-model="record.sale_date" required>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Description</label>
                            <input type="text" name="description" class="rm-input" x-model="record.description" required maxlength="2000" placeholder="e.g. GCash transfer from catering order">
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Amount <span class="rm-field-opt">(₱)</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="rm-input" x-model="record.amount" required>
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeRecord()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="record.mode === 'edit' ? 'Save changes' : 'Save record'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- GCash expense drawer. Posts to the existing expenses routes so there is only one
             way to create an expense; payment method is fixed to GCash. --}}
        <template x-if="expenseOpen">
            <div class="rm-overlay" @click.self="closeExpense()">
                <form method="POST"
                      :action="expense.mode === 'edit' ? expense.action : '{{ route('expenses.store') }}'"
                      class="rm-drawer"
                      @submit="submitting = true">
                    @csrf
                    <template x-if="expense.mode === 'edit'">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <input type="hidden" name="payment_method" value="gcash">
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="expense.mode === 'edit' ? 'Edit GCash Expense' : 'Add GCash Expense'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeExpense()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <p class="rh-gcash-hint">Saved as a GCash expense, so it also appears on the Expenses page.</p>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                <select name="branch_id" class="rm-input" x-model="expense.branch_id" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Date</label>
                                <input type="date" name="expense_date" class="rm-input" x-model="expense.expense_date" required>
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Description</label>
                            <input type="text" name="description" class="rm-input" x-model="expense.description" required maxlength="200" placeholder="e.g. Supplier payment via GCash">
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Amount <span class="rm-field-opt">(₱)</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="rm-input" x-model="expense.amount" required>
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Category <span class="rm-field-opt">(optional)</span></label>
                                <select name="expense_category_id" class="rm-input" x-model="expense.expense_category_id">
                                    <option value="">No category</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        {{-- These round-trip every field ExpenseController::update writes. It sets any
                             field the request omits back to null, so leaving them out would wipe an
                             expense's category, vendor, reference and notes on a simple amount edit. --}}
                        {{-- Disabled, not merely hidden: x-show only sets display:none and the
                             input would still submit. ExpenseController gives new_category_name
                             precedence over expense_category_id, so a stale value left here would
                             silently file the expense under a newly created category instead of
                             the one the user picked. A disabled input is not submitted. --}}
                        <div class="rm-field" x-show="!expense.expense_category_id">
                            <label class="rm-field-label">New category <span class="rm-field-opt">(optional)</span></label>
                            <input type="text" name="new_category_name" class="rm-input" x-model="expense.new_category_name" maxlength="100" placeholder="Creates a category if it doesn't exist" :disabled="!! expense.expense_category_id">
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Vendor <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="vendor_name" class="rm-input" x-model="expense.vendor_name" maxlength="140">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Reference # <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="reference_no" class="rm-input" x-model="expense.reference_no" maxlength="60" placeholder="GCash ref no.">
                            </div>
                        </div>
                        <div class="rm-field">
                            <label class="rm-field-label">Notes <span class="rm-field-opt">(optional)</span></label>
                            <input type="text" name="notes" class="rm-input" x-model="expense.notes" maxlength="2000">
                        </div>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeExpense()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="expense.mode === 'edit' ? 'Save changes' : 'Save expense'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- Deletes post a real form so they carry CSRF and the method spoof. --}}
        <form method="POST" x-ref="deleteForm" class="hidden" style="display:none;">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
        </form>
    </div>

    <script>
        function gcashReportPage(config) {
            const blankRecord = () => ({
                mode: 'create',
                action: '',
                branch_id: config.defaultBranchId,
                sale_date: config.defaultDate,
                amount: '',
                description: '',
            });
            const blankExpense = () => ({
                mode: 'create',
                action: '',
                branch_id: config.defaultBranchId,
                expense_date: config.defaultDate,
                amount: '',
                description: '',
                expense_category_id: '',
                new_category_name: '',
                vendor_name: '',
                reference_no: '',
                notes: '',
            });

            const blankAdjustment = () => ({
                mode: 'create',
                action: '',
                branch_id: config.defaultBranchId,
                adjustment_date: config.defaultDate,
                amount: '',
                reason: '',
            });

            return {
                submitting: false,
                recordOpen: false,
                expenseOpen: false,
                adjustmentOpen: false,
                record: blankRecord(),
                expense: blankExpense(),
                adjustment: blankAdjustment(),

                openAdjustmentCreate() {
                    this.adjustment = blankAdjustment();
                    this.adjustmentOpen = true;
                },
                openAdjustmentEdit(row) {
                    this.adjustment = {
                        mode: 'edit',
                        action: config.adjustmentUpdateTemplate.replace('__ID__', row.id),
                        branch_id: row.branch_id,
                        adjustment_date: row.adjustment_date,
                        amount: row.amount,
                        reason: row.reason,
                    };
                    this.adjustmentOpen = true;
                },
                closeAdjustment() {
                    this.adjustmentOpen = false;
                },
                deleteAdjustment(row) {
                    if (!window.confirm('Delete this adjustment? The deducted amount goes back into the GCash total.')) {
                        return;
                    }
                    this.postDelete(config.adjustmentDestroyTemplate.replace('__ID__', row.id));
                },

                openRecordCreate() {
                    this.record = blankRecord();
                    this.recordOpen = true;
                },
                openRecordEdit(row) {
                    this.record = {
                        mode: 'edit',
                        action: config.recordUpdateTemplate.replace('__ID__', row.id),
                        branch_id: row.branch_id,
                        sale_date: row.sale_date,
                        amount: row.amount,
                        description: row.description,
                    };
                    this.recordOpen = true;
                },
                closeRecord() {
                    this.recordOpen = false;
                },
                deleteRecord(row) {
                    if (!window.confirm('Delete this GCash record? This removes it from the Sales page and the day\'s GCash total too.')) {
                        return;
                    }
                    this.postDelete(config.recordDestroyTemplate.replace('__ID__', row.id));
                },

                openExpenseCreate() {
                    this.expense = blankExpense();
                    this.expenseOpen = true;
                },
                openExpenseEdit(row) {
                    this.expense = {
                        mode: 'edit',
                        action: config.expenseUpdateTemplate.replace('__ID__', row.id),
                        branch_id: row.branch_id,
                        expense_date: row.expense_date,
                        amount: row.amount,
                        description: row.description,
                        expense_category_id: row.expense_category_id ?? '',
                        new_category_name: '',
                        vendor_name: row.vendor_name ?? '',
                        reference_no: row.reference_no ?? '',
                        notes: row.notes ?? '',
                    };
                    this.expenseOpen = true;
                },
                closeExpense() {
                    this.expenseOpen = false;
                },
                deleteExpense(row) {
                    if (!window.confirm('Delete this GCash expense? It is removed from the Expenses page too.')) {
                        return;
                    }
                    this.postDelete(config.expenseDestroyTemplate.replace('__ID__', row.id));
                },

                postDelete(action) {
                    const form = this.$refs.deleteForm;
                    form.setAttribute('action', action);
                    form.submit();
                },
            };
        }
    </script>
</x-app-layout>
