{{-- Special-expense detail + add/edit drawers.

     Shared by the Special Expenses page and the Cash Report's owner-only section, so the
     two cannot drift. Requires an enclosing x-data="specialExpensesPage({...})" and the
     variables $branches, $categories and $monthOptions. --}}
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
