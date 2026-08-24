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

/**
 * The ONE label for "no category", identical on the server (
 * InventoryController::UNCATEGORIZED_LABEL) and on the phone
 * (UNCATEGORIZED_LABEL in the mobile types), so the same shelf is never named
 * two different things on two screens.
 */
export const UNCATEGORIZED_LABEL = 'Uncategorized';

/** Group key for the uncategorized tail. A category id can never collide. */
export const UNCATEGORIZED_GROUP_KEY = 'uncategorized';

/** Steps of 10, matching IngredientCategory::SORT_ORDER_STEP. */
export const SORT_ORDER_STEP = 10;

/**
 * A row belongs to the uncategorized tail when it has no category id OR no
 * category name. Both are checked on purpose: a row carrying an id whose
 * category could not be resolved must still land in the labelled tail rather
 * than under a header with no title.
 */
function isUncategorizedRow(row) {
    const id = row.ingredient_category_id ?? null;
    const name = row.category_name ?? null;

    return id === null || name === null || name === '';
}

/**
 * Cut an ALREADY-ORDERED session row array into category groups.
 *
 * The server hands back InventoryService::buildCountSession()'s rows in the
 * physical walk order (category sort_order, then ingredient name), so this
 * NEVER sorts — it only cuts the run into sections, appending to an existing
 * group if the same category reappears, and forcing the uncategorized group
 * LAST exactly as Ingredient::scopeOrderedForWalk and the phone do.
 *
 * CRITICAL: the returned groups hold the ORIGINAL row objects, never spread
 * copies. Alpine's x-model binds to those objects and rowConsumed() /
 * totalConsumptionLabel() read countDraft.ingredients — copying would leave the
 * totals reading a stale array while the inputs edited a detached one.
 *
 * Flat POST indexes come from group.startIndex instead: the template names
 * inputs entries[group.startIndex + i][…], which stays unique across the whole
 * draft. Restarting i per group would silently collapse the count.
 *
 * @returns {{key: string, title: string, startIndex: number, rows: object[]}[]}
 */
export function groupCountRows(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const byKey = new Map();
    const ordered = [];

    for (const row of list) {
        const uncategorized = isUncategorizedRow(row);
        const key = uncategorized ? UNCATEGORIZED_GROUP_KEY : String(row.ingredient_category_id);
        let group = byKey.get(key);

        if (!group) {
            group = { key, title: uncategorized ? UNCATEGORIZED_LABEL : String(row.category_name), rows: [] };
            byKey.set(key, group);
            ordered.push(group);
        }

        group.rows.push(row);
    }

    const walk = [
        ...ordered.filter((group) => group.key !== UNCATEGORIZED_GROUP_KEY),
        ...ordered.filter((group) => group.key === UNCATEGORIZED_GROUP_KEY),
    ];

    let startIndex = 0;

    // A NEW group object per section (immutability), still pointing at the very
    // same rows array the draft holds.
    return walk.map((group) => {
        const withStart = { ...group, startIndex };
        startIndex += group.rows.length;

        return withStart;
    });
}

/**
 * Returns a NEW array whose sort_order is rewritten to (i + 1) * SORT_ORDER_STEP,
 * regardless of the spacing it came in with. Never mutates its argument.
 */
export function resequence(categories) {
    return categories.map((category, index) => ({
        ...category,
        sort_order: (index + 1) * SORT_ORDER_STEP,
    }));
}

/**
 * Returns a NEW array with `id` moved one place up or down and resequenced.
 *
 * Returns the SAME array reference when the move is impossible (already first,
 * already last, id not present), which is how the caller detects a no-op and
 * skips staging a pointless reorder.
 */
export function moveCategory(categories, id, direction) {
    const index = categories.findIndex((category) => String(category.id) === String(id));
    if (index === -1) return categories;

    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= categories.length) return categories;

    const next = [...categories];
    next[index] = categories[target];
    next[target] = categories[index];

    return resequence(next);
}

/**
 * Every other control in the Count Order drawer (Add, Rename, Active, Delete) is
 * a full-page POST that redirects back and RE-STAGES the walk from the server,
 * so a staged-but-unsaved reorder is silently thrown away by any of them. Same
 * contract as COUNT_BRANCH_SWITCH_WARNING: typed-but-unsaved work is never
 * discarded without asking.
 */
export const CATEGORY_ORDER_DISCARD_WARNING =
    'Your unsaved count order will be discarded. Save the order first, or continue and lose it?';

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
    // '' is the "— none —" option. The server normalises it back to null before
    // validation, so an empty string never reaches the integer FK column.
    ingredient_category_id: '',
    new_category_name: '',
});

export function inventoryPage(config) {
    return {
        startCountUrl: config.startCountUrl,
        storeCountUrl: config.storeCountUrl,
        countShowUrlTemplate: config.countShowUrlTemplate,
        countDestroyUrlTemplate: config.countDestroyUrlTemplate,
        ingredientShowUrlTemplate: config.ingredientShowUrlTemplate,
        updateUrlTemplate: config.updateUrlTemplate,
        storeCategoryUrl: config.storeCategoryUrl,
        reorderCategoryUrl: config.reorderCategoryUrl,
        categoryUpdateUrlTemplate: config.categoryUpdateUrlTemplate,
        categoryDestroyUrlTemplate: config.categoryDestroyUrlTemplate,
        csrfToken: config.csrfToken,
        allIngredients: config.allIngredients,
        branches: config.branches,
        filterBranchId: config.filterBranchId,
        /**
         * Every category the page may show, in walk order and INCLUDING the
         * inactive ones: the manager needs them and the pickers filter them out
         * client-side, so a filter never costs a round trip.
         */
        categories: config.categories || [],
        /**
         * The branch whose walk the manager edits. Categories are per branch, so
         * managing them across "All branches" would be meaningless; the drawer
         * asks for a branch instead.
         */
        manageBranchId: String(config.manageBranchId || ''),
        // Reopened by the controller's flash after a category write, so the
        // owner lands back where they were instead of at the top of the page.
        categoriesOpen: Boolean(config.categoriesOpen),
        categoryOrder: [],
        categoryOrderDirty: false,
        newCategoryName: '',
        showNewCategory: false,
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
        init() {
            // The drawer can boot open (a category write redirects back), and it
            // renders the STAGED order, so the staging has to exist by then.
            if (this.categoriesOpen) this.stageCategories();
        },
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
        /**
         * The count walk, cut into the sections the shelves are actually
         * arranged in. Presentation only: the draft's row objects, their order
         * and the flat entry indexes are untouched.
         */
        countGroups() {
            return groupCountRows(this.countDraft.ingredients);
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
        /**
         * A category is editable from this page only when it belongs to the
         * branch being managed. A SHARED category (branch_id null) spans every
         * branch and the server refuses to rename, reorder or delete it here, so
         * the drawer must not offer controls that can only fail.
         */
        isEditableCategory(category) {
            return (
                this.manageBranchId !== '' &&
                category.branch_id !== null &&
                String(category.branch_id) === this.manageBranchId
            );
        },
        editableCategories() {
            return this.categories.filter((category) => this.isEditableCategory(category));
        },
        sharedCategories() {
            return this.categories.filter((category) => category.branch_id === null);
        },
        /** Copies, so a staged reorder that is never saved changes nothing. */
        stageCategories() {
            this.categoryOrder = this.editableCategories().map((category) => ({ ...category }));
            this.categoryOrderDirty = false;
        },
        openCategories() {
            this.stageCategories();
            this.newCategoryName = '';
            this.categoriesOpen = true;
        },
        closeCategories() {
            this.categoriesOpen = false;
        },
        /**
         * Staged locally and committed by ONE post to the reorder endpoint: a
         * connection dropped between N separate saves would leave the walk half
         * renumbered.
         */
        moveCategoryRow(id, direction) {
            const next = moveCategory(this.categoryOrder, id, direction);
            // The same reference back means the move was impossible.
            if (next === this.categoryOrder) return;
            this.categoryOrder = next;
            this.categoryOrderDirty = true;
        },
        /**
         * Guard for the drawer's OTHER writes. Each one reloads the page and
         * re-stages the walk, so a pending ▲▼ reorder would vanish with no
         * message. Returns false when the operator refused, so a caller that
         * has its own confirmation can stop there.
         */
        confirmDiscardStagedOrder(event) {
            if (!this.categoryOrderDirty) return true;
            if (window.confirm(CATEGORY_ORDER_DISCARD_WARNING)) return true;

            event.preventDefault();

            return false;
        },
        /**
         * Deleting a category never deletes stock: the FK is nullOnDelete, so
         * its ingredients simply reappear in the uncategorized tail. The
         * confirmation says so, because "delete" next to an item count reads
         * like it takes the items with it.
         */
        confirmCategoryDelete(event, category) {
            // The order prompt first: a refused delete must not also discard a
            // reorder, and answering two prompts for one click is the price of
            // never losing either.
            if (!this.confirmDiscardStagedOrder(event)) return;

            const count = Number(category.ingredient_count || 0);
            const message =
                'Delete “' +
                category.name +
                '”?\n\n' +
                count +
                ' ingredient(s) will become ' +
                UNCATEGORIZED_LABEL.toLowerCase() +
                '. Nothing is deleted from stock.';

            if (!window.confirm(message)) event.preventDefault();
        },
        categoryUpdateUrl(id) {
            return this.categoryUpdateUrlTemplate.replace('__CATEGORY__', id);
        },
        categoryDestroyUrl(id) {
            return this.categoryDestroyUrlTemplate.replace('__CATEGORY__', id);
        },
        /**
         * The ingredient form's options: the categories that branch resolves,
         * active only — PLUS the one the edited ingredient already carries even
         * if it was deactivated, so editing an ingredient can never silently
         * clear a category the owner cannot see.
         */
        formCategories() {
            const branchId = String(this.form.branch_id || '');
            const selected = String(this.form.ingredient_category_id || '');

            return this.categories.filter((category) => {
                const resolvable = category.branch_id === null || String(category.branch_id) === branchId;

                return resolvable && (category.is_active || String(category.id) === selected);
            });
        },
        /**
         * The picker and the inline "new category" box are mutually exclusive in
         * the UI. The server's documented precedence (a non-blank name WINS) is
         * the safety net, not the interaction.
         */
        toggleNewCategory() {
            this.showNewCategory = !this.showNewCategory;
            if (this.showNewCategory) {
                this.form = { ...this.form, ingredient_category_id: '' };
            } else {
                this.form = { ...this.form, new_category_name: '' };
            }
        },
        openCreate() {
            this.form = emptyForm();
            this.showNewCategory = false;
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
                // Seeded from the row/detail payload; both carry it, so opening
                // Edit can never post a blank that clears the assignment.
                ingredient_category_id: String(item.ingredient_category_id ?? ''),
                new_category_name: '',
            };
            this.showNewCategory = false;
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
            this.categoriesOpen = false;
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
