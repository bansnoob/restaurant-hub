<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cash Report</h2>
    </x-slot>

    @php
        $isOwner = (bool) auth()->user()?->hasRole('owner');
        $hasFilters = ! empty($filters['branch_id']);
        $rangeLabel = \Carbon\Carbon::parse($filters['date_from'])->format('M j').' – '.\Carbon\Carbon::parse($filters['date_to'])->format('M j, Y');
    @endphp

    <div class="rh-cash-page"
         x-data="cashReportPage({
            editUrlTemplate: @js(route('day-close.edit', ['dayClosure' => '__ID__'])),
            updateUrlTemplate: @js(route('day-close.update', ['dayClosure' => '__ID__'])),
            csrfToken: @js(csrf_token()),
         })"
         @keydown.escape.window="closeEdit()">
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
                <p class="rh-pay-sub">
                    {{ strtoupper($rangeLabel) }} · {{ $totals['days_closed'] }} {{ \Illuminate\Support\Str::plural('day', $totals['days_closed']) }} closed
                    @if (($totals['days_unclosed'] ?? 0) > 0)
                        · <span class="rh-cash-gap-count">{{ $totals['days_unclosed'] }} not closed</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Stats strip. Expected and Variance are deliberately not here: summed across a
             date range they answer nothing — a month of expected cash is not a figure
             anyone acts on, and daily overs and shorts cancel out, so a range total of
             ~0 reads as "balanced" whether every day matched or every day was wild.
             Both stay per-row in the table below, which is where they mean something.

             Cash on Hand is net of Cash Overhead, which sits beside it so the figure
             never moves without the reason being on screen. Overhead is a range-level
             position only — see CashReportService::cashOverhead() for why it must not
             reach a day's expected_cash. --}}
        <div class="rh-pay-stats rh-cash-stats">
            @php $cashOnHand = (float) $totals['cash_on_hand']; @endphp
            <div class="rh-pay-stat" style="--i:1;">
                <p class="rh-pay-stat-label">Cash on Hand</p>
                {{-- Colour follows the sign. A window whose overhead payments exceed the
                     tills counted in it nets out negative, and that figure must not be
                     painted with the healthy colour. --}}
                <p class="rh-pay-stat-value {{ $cashOnHand < 0 ? 'rh-pay-stat-value--warn' : 'rh-pay-stat-value--success' }}">
                    {{ $cashOnHand < 0 ? '−₱'.number_format(abs($cashOnHand), 2) : '₱'.number_format($cashOnHand, 2) }}
                </p>
            </div>
            <div class="rh-pay-stat" style="--i:2;">
                <p class="rh-pay-stat-label">Cash Overhead</p>
                <p class="rh-pay-stat-value rh-pay-stat-value--warn">₱{{ number_format($totals['cash_overhead_total'], 2) }}</p>
            </div>
            <div class="rh-pay-stat" style="--i:3;">
                <p class="rh-pay-stat-label">Cash Expenses</p>
                <p class="rh-pay-stat-value">₱{{ number_format($totals['cash_expenses_total'], 2) }}</p>
            </div>
        </div>

        {{-- Toolbar --}}
        {{-- Native onchange rather than Alpine: this page's only x-data lives on the toasts,
             so directives here sit outside any component scope and would never bind. --}}
        <form method="GET" action="{{ route('day-closures.index') }}" class="rh-pay-toolbar">
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
                @if ($hasFilters || $filters['date_from'] !== now()->subDays(29)->toDateString() || $filters['date_to'] !== now()->toDateString())
                    <a href="{{ route('day-closures.index') }}" class="rh-emp-clear">Reset</a>
                @endif
            </div>
        </form>

        {{-- Day rows: closures plus any day that moved the till and was never closed --}}
        @if ($closures->isEmpty())
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">Nothing in this range</p>
                    <p style="font-size: 0.82rem;">Days with cash sales or cash expenses appear here, closed or not.</p>
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
                        @foreach ($closures as $row)
                            @php
                                $isClosed = (bool) $row['closed'];
                                $variance = (float) ($row['variance'] ?? 0);
                                if (! $isClosed) {
                                    $varClass = 'rh-cash-variance-pill--open';
                                    $varLabel = 'Not closed';
                                } elseif (abs($variance) < 0.01) {
                                    $varClass = 'rh-cash-variance-pill--match';
                                    $varLabel = 'Match';
                                } elseif ($variance < 0) {
                                    $varClass = 'rh-cash-variance-pill--short';
                                    $varLabel = '−₱'.number_format(abs($variance), 2);
                                } else {
                                    $varClass = 'rh-cash-variance-pill--over';
                                    $varLabel = '+₱'.number_format($variance, 2);
                                }
                                $rowDate = \Carbon\Carbon::parse($row['date']);
                            @endphp
                            <tr class="{{ $isClosed ? '' : 'rh-cash-row--open' }}">
                                <td>
                                    <strong>{{ $rowDate->format('M j') }}</strong>
                                    <span style="display: block; font-family: var(--rh-font-mono); font-size: 0.6rem; color: var(--rh-text-muted); margin-top: 0.15rem;">{{ $rowDate->format('Y') }}</span>
                                </td>
                                <td>{{ $row['branch_name'] ?? '—' }}</td>
                                <td style="font-family: var(--rh-font-mono); font-size: 0.7rem; color: var(--rh-text-muted);">{{ $row['closed_by'] ?? '—' }}</td>
                                <td class="num num--success">₱{{ number_format((float) $row['cash_sales_total'], 2) }}</td>
                                <td class="num num--warn">₱{{ number_format((float) $row['cash_expenses_total'], 2) }}</td>
                                <td class="num">₱{{ number_format((float) $row['expected_cash'], 2) }}</td>
                                <td class="num {{ $isClosed ? 'num--accent' : '' }}">
                                    @if ($isClosed)
                                        ₱{{ number_format((float) $row['counted_cash'], 2) }}
                                    @else
                                        <span style="color: var(--rh-text-muted);">—</span>
                                    @endif
                                </td>
                                <td class="center"><span class="rh-cash-variance-pill {{ $varClass }}">{{ $varLabel }}</span></td>
                                @if ($isOwner)
                                    <td>
                                        @if ($isClosed)
                                            {{-- A closed day is corrected, never reopened. Deleting the closure
                                                 was the old fix and it threw away the record of the day having
                                                 been closed at all. --}}
                                            <button type="button" class="rh-cash-row-action rh-cash-row-action--edit"
                                                    @click="openEdit({{ $row['closure']->id }})">Edit Day</button>
                                        @else
                                            {{-- Sends the dashboard straight into the Close Day drawer for this
                                                 past date, which is the only way to fill a gap after the fact. --}}
                                            <a href="{{ route('dashboard', ['close_date' => $row['date'], 'close_branch_id' => $row['branch_id']]) }}"
                                               class="rh-cash-row-action rh-cash-row-action--close">Close</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rh-pay-pagination">{{ $closures->links() }}</div>
        @endif
        {{-- Edit Day drawer --}}
        <template x-if="editOpen">
            <div class="rm-overlay" @click.self="closeEdit()">
                <form method="POST" :action="updateUrl" class="rm-drawer rm-drawer--wide" @submit="saving = true">
                    <input type="hidden" name="_token" :value="csrfToken">
                    <input type="hidden" name="_method" value="PUT">

                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title">Edit Day</h2>
                            <p class="rh-pay-sub" x-show="day" x-text="day ? (day.date_label + ' · ' + day.branch.name) : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeEdit()">&times;</button>
                    </div>

                    <div class="rm-drawer-body">
                        <template x-if="!day">
                            <div class="rh-emp-detail-loading">Loading&hellip;</div>
                        </template>

                        <template x-if="day">
                            <div>
                                <div class="rh-close-breakdown">
                                    <div class="rh-close-line">
                                        {{-- Sales are read-only: they come from the POS and are the record this
                                             count is checked against. --}}
                                        <span>+ Cash sales <span style="opacity:0.6;" x-text="'(' + day.order_count + ' orders)'"></span></span>
                                        <span x-text="peso(day.cash_sales_total)"></span>
                                    </div>
                                </div>

                                <p class="rh-pay-section-title" style="margin-top:1rem;">Cash Expenses</p>
                                <table class="rh-cash-edit-table">
                                    <tbody>
                                        <template x-for="(row, i) in expenses" :key="i">
                                            <tr>
                                                <td>
                                                    <input type="text" class="rm-input" placeholder="Description"
                                                           :name="'expenses[' + i + '][description]'" x-model="row.description">
                                                    <input type="hidden" :name="'expenses[' + i + '][id]'" :value="row.id || ''">
                                                </td>
                                                <td style="width:9rem;">
                                                    <input type="number" step="0.01" min="0" class="rm-input"
                                                           :name="'expenses[' + i + '][amount]'" x-model.number="row.amount">
                                                </td>
                                                <td style="width:2.5rem;">
                                                    <button type="button" class="rh-cash-row-action rh-cash-row-action--reopen"
                                                            @click="removeExpense(i)">&times;</button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <template x-for="id in deletedExpenseIds" :key="'d' + id">
                                    <input type="hidden" name="deleted_expense_ids[]" :value="id">
                                </template>
                                <button type="button" class="rh-cash-row-action rh-cash-row-action--close"
                                        @click="addExpense()">+ Add expense</button>

                                <div class="rh-close-breakdown" style="margin-top:1rem;">
                                    <div class="rh-close-line">
                                        <span>&minus; Cash expenses</span>
                                        <span x-text="peso(expensesTotal)"></span>
                                    </div>
                                    <div class="rh-close-line">
                                        <strong>Expected in drawer</strong>
                                        <strong x-text="peso(expectedCash)"></strong>
                                    </div>
                                </div>

                                <div class="rm-field" style="margin-top:1rem;">
                                    <label class="rm-field-label">Counted cash</label>
                                    <input type="number" step="0.01" min="0" name="counted_cash" class="rm-input"
                                           x-model.number="countedCash">
                                </div>

                                <div class="rh-close-line">
                                    <span x-text="varianceLabel()"></span>
                                    <strong :class="varianceClass()" x-text="peso(Math.abs(variance))"></strong>
                                </div>

                                <div class="rm-field" style="margin-top:1rem;">
                                    <label class="rm-field-label">Notes (optional)</label>
                                    <textarea name="notes" class="rm-input" rows="2" x-model="notes"></textarea>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="rm-drawer-foot">
                        <button type="button" class="rh-cash-row-action rh-cash-row-action--reopen" @click="closeEdit()">Cancel</button>
                        <button type="submit" class="rh-cash-row-action rh-cash-row-action--close"
                                :disabled="!day || saving" x-text="saving ? 'Saving…' : 'Save Day'"></button>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function cashReportPage(config) {
            return {
                editOpen: false,
                saving: false,
                day: null,
                editingId: null,
                expenses: [],
                deletedExpenseIds: [],
                countedCash: 0,
                notes: '',
                csrfToken: config.csrfToken,
                get updateUrl() {
                    return config.updateUrlTemplate.replace('__ID__', this.editingId);
                },
                get expensesTotal() {
                    return this.expenses.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
                },
                get expectedCash() {
                    if (!this.day) return 0;
                    return Number(this.day.opening_float) + Number(this.day.cash_sales_total) - this.expensesTotal;
                },
                get variance() {
                    return (Number(this.countedCash) || 0) - this.expectedCash;
                },
                peso(value) {
                    return '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
                varianceLabel() {
                    if (Math.abs(this.variance) < 0.01) return '✓ Match';
                    return this.variance < 0 ? 'Short by' : 'Over by';
                },
                varianceClass() {
                    if (Math.abs(this.variance) < 0.01) return 'rh-close-variance--match';
                    return this.variance < 0 ? 'rh-close-variance--short' : 'rh-close-variance--over';
                },
                addExpense() {
                    this.expenses.push({ id: null, description: '', amount: 0 });
                },
                removeExpense(index) {
                    const row = this.expenses[index];
                    /* An existing row has to be named for deletion; a row that was never
                       saved just disappears. */
                    if (row && row.id) this.deletedExpenseIds.push(row.id);
                    this.expenses.splice(index, 1);
                },
                async openEdit(id) {
                    this.editOpen = true;
                    this.saving = false;
                    this.day = null;
                    this.editingId = id;
                    this.expenses = [];
                    this.deletedExpenseIds = [];
                    try {
                        const res = await fetch(config.editUrlTemplate.replace('__ID__', id), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        const data = await res.json();
                        this.day = data;
                        this.expenses = data.expenses.map(e => ({ id: e.id, description: e.description || '', amount: Number(e.amount) }));
                        this.countedCash = Number(data.counted_cash);
                        this.notes = data.notes || '';
                    } catch (err) {
                        this.editOpen = false;
                        console.error(err);
                    }
                },
                closeEdit() {
                    this.editOpen = false;
                    this.saving = false;
                },
            };
        }
    </script>
</x-app-layout>
