/**
 * The owner-facing inventory page (Alpine component).
 *
 * This lives in a module — NOT inline in resources/views/modules/inventory/index.blade.php —
 * so the branch-scoping rules the count modal depends on are reachable by a test
 * runner. See tests/js/inventory-page.test.js.
 *
 * A stock count belongs to exactly ONE branch and the server rejects an entry
 * for an ingredient outside it, so the session is always fetched branch-scoped.
 */

/** Mirrors InventoryService::QUANTITY_SCALE (decimal(14,3)). */
export const QUANTITY_ROUNDING_FACTOR = 1000;

export const COUNT_BRANCH_SWITCH_WARNING =
    "Switching branch loads that branch's ingredients. The quantities you have already typed will be discarded. Continue?";
export const COUNT_SESSION_ERROR_MESSAGE =
    "Could not load this branch's ingredients. Check your connection and pick the branch again.";
export const COUNT_BRANCH_PROMPT_MESSAGE = 'Pick a branch to load its ingredients.';
export const COUNT_BRANCH_EMPTY_MESSAGE = 'No ingredients in this branch.';

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

const emptyForm = () => ({
    mode: 'create',
    action: '',
    branch_id: '',
    name: '',
    sku: '',
    unit: 'pcs',
    current_stock: '0',
    reorder_level: '0',
    is_active: true,
});

export function inventoryPage(config) {
    return {
        startCountUrl: config.startCountUrl,
        storeCountUrl: config.storeCountUrl,
        countShowUrlTemplate: config.countShowUrlTemplate,
        countDestroyUrlTemplate: config.countDestroyUrlTemplate,
        ingredientShowUrlTemplate: config.ingredientShowUrlTemplate,
        updateUrlTemplate: config.updateUrlTemplate,
        csrfToken: config.csrfToken,
        allIngredients: config.allIngredients,
        branches: config.branches,
        filterBranchId: config.filterBranchId,
        // Server-rendered so the Count Date is filled in even when the modal
        // opens without a branch and therefore without a session fetch.
        today: config.today,
        countOpen: false,
        countLoading: false,
        countError: '',
        countController: null,
        countDraft: { branch_id: '', counted_at: '', notes: '', ingredients: [] },
        countDetailOpen: false,
        countDetail: null,
        ingredientOpen: false,
        ingredientData: null,
        ingredientController: null,
        formOpen: false,
        submitting: false,
        form: emptyForm(),
        /**
         * Opening the modal never fetches bare: fetching without a branch would
         * render (and post) every branch's rows under whichever branch happened
         * to be picked.
         */
        async openStartCount() {
            this.countOpen = true;
            this.submitting = false;
            this.countError = '';
            this.countDraft = { branch_id: '', counted_at: this.today, notes: '', ingredients: [] };
            await this.loadCountSession(this.defaultCountBranchId());
        },
        /** The branch the page is filtered by, or the only one there is. */
        defaultCountBranchId() {
            const filtered = String(this.filterBranchId || '');
            if (filtered !== '' && this.branches.some((b) => String(b.id) === filtered)) {
                return filtered;
            }
            return this.branches.length === 1 ? String(this.branches[0].id) : '';
        },
        countSessionUrl(branchId) {
            const separator = this.startCountUrl.includes('?') ? '&' : '?';
            return this.startCountUrl + separator + 'branch_id=' + encodeURIComponent(branchId);
        },
        /**
         * Rebuild the draft's rows from one branch's session. counted_at and
         * notes are the operator's own input rather than branch data, so they
         * survive a switch; the rows cannot.
         */
        async loadCountSession(branchId) {
            const next = String(branchId || '');
            if (this.countController) this.countController.abort();
            this.countError = '';

            if (next === '') {
                this.countDraft = { ...this.countDraft, branch_id: '', ingredients: [] };
                this.countLoading = false;
                return;
            }

            const controller = new AbortController();
            this.countController = controller;
            this.countLoading = true;
            try {
                const res = await fetch(this.countSessionUrl(next), {
                    headers: JSON_HEADERS,
                    signal: controller.signal,
                });
                if (!res.ok) throw new Error('Failed to load');
                const data = await res.json();
                this.countDraft = {
                    branch_id: next,
                    counted_at: this.countDraft.counted_at || data.today,
                    notes: this.countDraft.notes || '',
                    ingredients: data.ingredients,
                };
            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error(err);
                // Never leave the previous branch's rows on screen under a
                // different branch: they would post as that branch's entries,
                // and the server would (rightly) reject the lot.
                this.countDraft = { ...this.countDraft, branch_id: '', ingredients: [] };
                this.countError = COUNT_SESSION_ERROR_MESSAGE;
            } finally {
                // Only the newest request owns the spinner.
                if (this.countController === controller) this.countLoading = false;
            }
        },
        async onCountBranchChange(event) {
            const select = event.target;
            const next = String(select.value || '');
            if (next === this.countDraft.branch_id) return;

            if (this.countDraftIsDirty() && !window.confirm(COUNT_BRANCH_SWITCH_WARNING)) {
                select.value = this.countDraft.branch_id;
                return;
            }

            await this.loadCountSession(next);
            // A failed load resets the branch to '' — keep the picker in step
            // with the state the form will actually post.
            select.value = this.countDraft.branch_id;
        },
        /**
         * The session arrives with counted_quantity SEEDED to the expected
         * figure, so "the owner typed something" means a row that no longer
         * matches its seed.
         */
        countDraftIsDirty() {
            return this.countDraft.ingredients.some((row) => {
                const seeded = Number(row.expected_quantity ?? row.previous_quantity ?? 0);
                return (
                    Number(row.counted_quantity ?? 0) !== seeded ||
                    Number(row.restocked_quantity || 0) !== 0
                );
            });
        },
        /** The empty-table line: no branch picked yet vs. a branch with no ingredients. */
        countEmptyStateMessage() {
            return this.countDraft.branch_id ? COUNT_BRANCH_EMPTY_MESSAGE : COUNT_BRANCH_PROMPT_MESSAGE;
        },
        onCountSubmit(event) {
            // Belt and braces for the disabled Save button: a draft that is
            // mid-refetch, branchless or empty must never post.
            if (this.countLoading || !this.countDraft.branch_id || this.countDraft.ingredients.length === 0) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
        },
        closeCount() {
            if (this.countController) this.countController.abort();
            this.countOpen = false;
            this.countLoading = false;
            this.countError = '';
            this.submitting = false;
        },
        /**
         * The baseline a count is measured against is
         * `previous_quantity + pending_restock`, which the server emits
         * pre-computed as `expected_quantity`. The Restocked input is the
         * operator's own figure and is ADDED on top of it, exactly as
         * InventoryService::insertCountEntries does — reading only
         * previous_quantity here would render a phantom gain on every
         * ingredient with a logged delivery.
         */
        rowConsumed(row) {
            const baseline =
                row.expected_quantity === undefined || row.expected_quantity === null
                    ? Number(row.previous_quantity || 0) + Number(row.pending_restock || 0)
                    : Number(row.expected_quantity);
            const consumed = baseline + Number(row.restocked_quantity || 0) - Number(row.counted_quantity || 0);
            // Rounded to the schema's decimal(14,3) so float noise never
            // renders as a 1e-14 "gain".
            return Math.round(consumed * QUANTITY_ROUNDING_FACTOR) / QUANTITY_ROUNDING_FACTOR;
        },
        rowConsumeLabel(row) {
            const consumed = this.rowConsumed(row);
            if (consumed === 0) return '0';
            if (consumed < 0) return '+' + this.formatStock(Math.abs(consumed));
            return this.formatStock(consumed);
        },
        rowConsumeClass(row) {
            const consumed = this.rowConsumed(row);
            if (consumed === 0) return 'rh-inv-count-consume--zero';
            if (consumed < 0) return 'rh-inv-count-consume--neg';
            return '';
        },
        totalConsumptionLabel() {
            let count = 0;
            for (const row of this.countDraft.ingredients) {
                if (this.rowConsumed(row) > 0) count++;
            }
            if (count === 0) return 'none';
            return count + ' item' + (count === 1 ? '' : 's');
        },
        async openCountDetail(countId) {
            this.countDetail = null;
            this.countDetailOpen = true;
            try {
                const url = this.countShowUrlTemplate.replace('__COUNT__', countId);
                const res = await fetch(url, { headers: JSON_HEADERS });
                if (!res.ok) throw new Error('Failed to load');
                this.countDetail = await res.json();
            } catch (err) {
                this.countDetailOpen = false;
                console.error(err);
            }
        },
        closeCountDetail() {
            this.countDetailOpen = false;
            this.countDetail = null;
        },
        async openIngredient(ingredientId) {
            if (this.ingredientController) this.ingredientController.abort();
            this.ingredientData = null;
            this.ingredientOpen = true;
            this.ingredientController = new AbortController();
            try {
                const url = this.ingredientShowUrlTemplate.replace('__INGREDIENT__', ingredientId);
                const res = await fetch(url, {
                    headers: JSON_HEADERS,
                    signal: this.ingredientController.signal,
                });
                if (!res.ok) throw new Error('Failed to load');
                this.ingredientData = await res.json();
            } catch (err) {
                if (err.name !== 'AbortError') {
                    this.ingredientOpen = false;
                    console.error(err);
                }
            }
        },
        closeIngredient() {
            this.ingredientOpen = false;
            this.ingredientData = null;
            if (this.ingredientController) this.ingredientController.abort();
        },
        openCreate() {
            this.form = emptyForm();
            this.formOpen = true;
            this.submitting = false;
        },
        openEdit(item) {
            this.form = {
                mode: 'edit',
                action: this.updateUrlTemplate.replace('__INGREDIENT__', item.id),
                branch_id: String(item.branch_id ?? ''),
                name: item.name ?? '',
                sku: item.sku ?? '',
                unit: item.unit ?? 'pcs',
                current_stock: String(item.current_stock ?? '0'),
                reorder_level: String(item.reorder_level ?? '0'),
                is_active: Boolean(item.is_active),
            };
            this.formOpen = true;
            this.ingredientOpen = false;
            this.submitting = false;
        },
        editFromDetail() {
            if (!this.ingredientData) return;
            this.openEdit(this.ingredientData.ingredient);
        },
        closeForm() {
            this.formOpen = false;
            this.submitting = false;
        },
        closeAll() {
            this.countOpen = false;
            this.countDetailOpen = false;
            this.ingredientOpen = false;
            this.formOpen = false;
        },
        formatStock(n) {
            if (n === null || n === undefined) return '0';
            const num = Number(n);
            const s = num.toFixed(3);
            return s.replace(/\.?0+$/, '');
        },
        stockBarPct(ing) {
            const max = Math.max((ing.reorder_level || 0) * 2, 1);
            return Math.max(2, Math.min(100, (ing.current_stock / max) * 100));
        },
    };
}

export default inventoryPage;
