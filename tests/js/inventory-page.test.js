/**
 * The inventory page's count modal, tested directly.
 *
 * The component used to live inline in the blade, where nothing could run it:
 * reverting its branch scoping (fetching the session bare, i.e. every branch's
 * ingredients under one branch's count) left the whole PHP suite green. These
 * tests exercise the real module — run by `npm run test:js`, and by
 * `php artisan test` through Tests\Feature\InventoryPageScriptTest.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    inventoryPage,
    groupCountRows,
    moveCategory,
    resequence,
    COUNT_BRANCH_PROMPT_MESSAGE,
    COUNT_BRANCH_EMPTY_MESSAGE,
    COUNT_SESSION_ERROR_MESSAGE,
    SORT_ORDER_STEP,
    UNCATEGORIZED_LABEL,
    CATEGORY_ORDER_DISCARD_WARNING,
} from '../../resources/js/inventory/inventory-page.js';

const TODAY = '2026-02-01';

const sessionRow = (overrides = {}) => ({
    ingredient_id: 1,
    name: 'Tomato',
    sku: 'TOM',
    unit: 'kg',
    branch_id: 7,
    branch_name: 'Main',
    previous_quantity: 100,
    restocked_quantity: 0,
    pending_restock: 0,
    expected_quantity: 100,
    counted_quantity: 100,
    // Every session row carries its shelf, null when the ingredient has none.
    ingredient_category_id: null,
    category_name: null,
    category_sort_order: null,
    ...overrides,
});

/** A component wired to a recording fake of fetch(). */
function makePage(config = {}, respond = () => ({ today: TODAY, ingredients: [sessionRow()] })) {
    const calls = [];
    const page = inventoryPage({
        startCountUrl: 'https://app.test/inventory/counts/start',
        storeCountUrl: 'https://app.test/inventory/counts',
        countShowUrlTemplate: 'https://app.test/inventory/counts/__COUNT__',
        countDestroyUrlTemplate: 'https://app.test/inventory/counts/__COUNT__',
        ingredientShowUrlTemplate: 'https://app.test/inventory/__INGREDIENT__',
        updateUrlTemplate: 'https://app.test/inventory/__INGREDIENT__',
        csrfToken: 'token',
        storeCategoryUrl: 'https://app.test/inventory/categories',
        reorderCategoryUrl: 'https://app.test/inventory/categories/reorder',
        categoryUpdateUrlTemplate: 'https://app.test/inventory/categories/__CATEGORY__',
        categoryDestroyUrlTemplate: 'https://app.test/inventory/categories/__CATEGORY__',
        categories: [],
        manageBranchId: '7',
        allIngredients: [],
        branches: [{ id: 7, name: 'Main' }],
        filterBranchId: '',
        today: TODAY,
        ...config,
    });

    // window.confirm is only consulted for a DIRTY draft; the tests that care
    // about the prompt override this.
    global.window = { confirm: () => true };
    global.fetch = async (url, options) => {
        calls.push(url);
        const body = respond(url, options);
        if (body instanceof Error) throw body;

        return { ok: true, json: async () => body };
    };

    return { page, calls };
}

test('opening the count modal never fetches the session bare', async () => {
    const { page, calls } = makePage();

    await page.openStartCount();

    assert.equal(calls.length, 1);
    // The whole A1 defect in one assertion: a bare fetch returns EVERY branch's
    // ingredients, and every one of them posts under the picked branch.
    assert.ok(
        calls[0].includes('branch_id=7'),
        `the session was fetched without a branch scope: ${calls[0]}`
    );
    assert.equal(page.countDraft.branch_id, '7');
});

test('a multi-branch page opens branchless, fetches nothing, and still shows todays date', async () => {
    const { page, calls } = makePage({
        branches: [
            { id: 7, name: 'Main' },
            { id: 8, name: 'Annex' },
        ],
    });

    await page.openStartCount();

    assert.deepEqual(calls, []);
    assert.deepEqual(page.countDraft.ingredients, []);
    // The date input is `required`; it used to render empty until a branch was
    // picked, because only the session fetch seeded it.
    assert.equal(page.countDraft.counted_at, TODAY);
    assert.equal(page.countEmptyStateMessage(), COUNT_BRANCH_PROMPT_MESSAGE);
});

test('the page filter picks the default branch when there is more than one', async () => {
    const { page, calls } = makePage({
        branches: [
            { id: 7, name: 'Main' },
            { id: 8, name: 'Annex' },
        ],
        filterBranchId: '8',
    });

    await page.openStartCount();

    assert.equal(calls.length, 1);
    assert.ok(calls[0].includes('branch_id=8'));
});

test('switching branch refetches and replaces the other branchs rows', async () => {
    const { page, calls } = makePage(
        {
            branches: [
                { id: 7, name: 'Main' },
                { id: 8, name: 'Annex' },
            ],
        },
        (url) => ({
            today: TODAY,
            ingredients: [
                sessionRow({
                    ingredient_id: url.includes('branch_id=8') ? 99 : 1,
                    branch_id: url.includes('branch_id=8') ? 8 : 7,
                }),
            ],
        })
    );

    await page.loadCountSession('7');
    assert.equal(page.countDraft.ingredients[0].ingredient_id, 1);

    const select = { value: '8' };
    await page.onCountBranchChange({ target: select });

    assert.equal(calls.length, 2);
    assert.ok(calls[1].includes('branch_id=8'));
    // Not appended, REPLACED: a leftover branch-7 row would post under branch 8.
    assert.equal(page.countDraft.ingredients.length, 1);
    assert.equal(page.countDraft.ingredients[0].branch_id, 8);
    assert.equal(page.countDraft.branch_id, '8');
    assert.equal(select.value, '8');
});

test('a dirty draft asks before a branch switch and the picker snaps back on refusal', async () => {
    const { page, calls } = makePage({
        branches: [
            { id: 7, name: 'Main' },
            { id: 8, name: 'Annex' },
        ],
    });
    global.window = { confirm: () => false };

    await page.loadCountSession('7');
    page.countDraft.ingredients[0].counted_quantity = 42;
    assert.equal(page.countDraftIsDirty(), true);

    const select = { value: '8' };
    await page.onCountBranchChange({ target: select });

    assert.equal(calls.length, 1, 'the refused switch must not refetch');
    assert.equal(select.value, '7');
    assert.equal(page.countDraft.branch_id, '7');
    assert.equal(page.countDraft.ingredients[0].counted_quantity, 42);
});

test('a failed load clears the rows rather than leaving them under another branch', async () => {
    const { page } = makePage({}, () => new Error('offline'));
    const logged = console.error;
    console.error = () => {}; // the component logs the failure on purpose

    await page.loadCountSession('7');
    console.error = logged;

    assert.deepEqual(page.countDraft.ingredients, []);
    assert.equal(page.countDraft.branch_id, '');
    assert.equal(page.countError, COUNT_SESSION_ERROR_MESSAGE);
    assert.equal(page.countLoading, false);
});

test('an empty branch is distinguished from no branch picked', async () => {
    const { page } = makePage({}, () => ({ today: TODAY, ingredients: [] }));

    await page.loadCountSession('7');

    assert.equal(page.countEmptyStateMessage(), COUNT_BRANCH_EMPTY_MESSAGE);
});

test('submitting is blocked while branchless, empty or mid-refetch', async () => {
    const { page } = makePage();
    const event = () => {
        let prevented = false;

        return { preventDefault: () => { prevented = true; }, get prevented() { return prevented; } };
    };

    let e = event();
    page.onCountSubmit(e);
    assert.equal(e.prevented, true, 'a branchless draft must not post');

    await page.openStartCount();
    e = event();
    page.onCountSubmit(e);
    assert.equal(e.prevented, false);
    assert.equal(page.submitting, true);

    page.countLoading = true;
    e = event();
    page.onCountSubmit(e);
    assert.equal(e.prevented, true, 'a draft mid-refetch must not post');
});

test('the consumed column folds the derived restock in exactly as the server does', () => {
    const { page } = makePage();
    const row = sessionRow({
        previous_quantity: 100,
        pending_restock: 30,
        expected_quantity: 130,
        restocked_quantity: 5,
        counted_quantity: 120,
    });

    // 130 + 5 - 120. Reading previous_quantity alone rendered a phantom gain.
    assert.equal(page.rowConsumed(row), 15);
    assert.equal(page.rowConsumeLabel(row), '15');
    assert.equal(page.rowConsumed({ ...row, counted_quantity: 140 }), -5);
    assert.equal(page.rowConsumeLabel({ ...row, counted_quantity: 140 }), '+5');
});

/* ── Category walk ─────────────────────────────────────────────────────────
 * The count modal walks the shelves, not the alphabet. The server hands back
 * the rows already in walk order (Ingredient::scopeOrderedForWalk); the
 * component only cuts that run into sections, and the two invariants below are
 * what keep the posted count identical to the one that was typed.
 */

const categorizedRow = (id, name, categoryId, categoryName, sortOrder) =>
    sessionRow({
        ingredient_id: id,
        name,
        ingredient_category_id: categoryId,
        category_name: categoryName,
        category_sort_order: sortOrder,
    });

/** Two shelves plus a loose end, in the order the server emits them. */
const walkRows = () => [
    categorizedRow(1, 'Shoyu', 3, 'Sauces & Seasoning', 10),
    categorizedRow(2, 'Miso Paste', 3, 'Sauces & Seasoning', 10),
    categorizedRow(3, 'Noodles', 4, 'Base & Mains', 20),
    categorizedRow(4, 'Mystery Box', null, null, null),
];

test('the count modal groups rows by category and keeps the flat entry indexes', async () => {
    const { page } = makePage({}, () => ({ today: TODAY, ingredients: walkRows() }));

    await page.loadCountSession('7');
    const groups = page.countGroups();

    assert.deepEqual(
        groups.map((group) => group.title),
        ['Sauces & Seasoning', 'Base & Mains', UNCATEGORIZED_LABEL]
    );
    // The loose end is a trailing "anything else?" section, never the opener.
    assert.equal(groups[groups.length - 1].key, 'uncategorized');
    assert.deepEqual(groups.map((group) => group.rows.length), [2, 1, 1]);

    // entries[group.startIndex + i] must cover 0..n-1 exactly once, or the POST
    // silently collapses two rows onto one index.
    const posted = groups.flatMap((group) => group.rows.map((_, i) => group.startIndex + i));
    assert.deepEqual(posted, [0, 1, 2, 3]);
    assert.equal(new Set(posted).size, page.countDraft.ingredients.length);
});

test('an uncategorized row that arrives first is still walked last', () => {
    const rows = [
        categorizedRow(9, 'Mystery Box', null, null, null),
        categorizedRow(1, 'Shoyu', 3, 'Sauces & Seasoning', 10),
    ];

    assert.deepEqual(
        groupCountRows(rows).map((group) => group.title),
        ['Sauces & Seasoning', UNCATEGORIZED_LABEL]
    );
});

test('a row carrying a category id but no name lands in the labelled tail', () => {
    const rows = [categorizedRow(1, 'Orphan', 12, null, null)];
    const groups = groupCountRows(rows);

    // Never a header with no title: the id survived a category the client could
    // not resolve, and the row still has to be counted.
    assert.equal(groups.length, 1);
    assert.equal(groups[0].title, UNCATEGORIZED_LABEL);
    assert.equal(groups[0].key, 'uncategorized');
});

test('grouping hands back the same row objects the draft holds', async () => {
    const { page } = makePage({}, () => ({ today: TODAY, ingredients: walkRows() }));

    await page.loadCountSession('7');
    const groups = page.countGroups();

    // x-model edits groups[…].rows[…]; rowConsumed()/totalConsumptionLabel()
    // read countDraft.ingredients. Spread copies would split the two apart.
    assert.ok(Object.is(groups[0].rows[0], page.countDraft.ingredients[0]));
    groups[0].rows[0].counted_quantity = 42;
    assert.equal(page.countDraft.ingredients[0].counted_quantity, 42);
});

test('a session with no category data at all still renders one honest group', () => {
    const groups = groupCountRows([sessionRow()]);

    assert.equal(groups.length, 1);
    assert.equal(groups[0].title, UNCATEGORIZED_LABEL);
    assert.equal(groups[0].startIndex, 0);
});

/* ── Category manager ──────────────────────────────────────────────────── */

const cat = (id, name, sortOrder, overrides = {}) => ({
    id,
    branch_id: 7,
    name,
    slug: name.toLowerCase(),
    sort_order: sortOrder,
    is_active: true,
    ingredient_count: 0,
    ...overrides,
});

const walkCategories = () => [cat(1, 'Dry store', 10), cat(2, 'Walk-in', 20), cat(3, 'Packaging', 30)];

test('moveCategory returns a new array and never mutates its argument', () => {
    const before = walkCategories();
    const snapshot = JSON.parse(JSON.stringify(before));

    const after = moveCategory(before, 3, 'up');

    assert.notEqual(after, before);
    assert.deepEqual(before, snapshot, 'the staged list was mutated in place');
    assert.deepEqual(after.map((c) => c.name), ['Dry store', 'Packaging', 'Walk-in']);
    assert.deepEqual(after.map((c) => c.sort_order), [10, 20, 30]);
});

test('moving the first category up, or the last down, returns the same array', () => {
    const list = walkCategories();

    // Same REFERENCE, which is how the component skips staging a no-op.
    assert.equal(moveCategory(list, 1, 'up'), list);
    assert.equal(moveCategory(list, 3, 'down'), list);
});

test('a category whose id is absent is a no-op', () => {
    const list = walkCategories();

    assert.equal(moveCategory(list, 999, 'up'), list);
});

test('resequence produces 10, 20, 30 regardless of the spacing it came in with', () => {
    const gappy = [cat(1, 'Dry store', 3), cat(2, 'Walk-in', 4000), cat(3, 'Packaging', 4001)];

    const after = resequence(gappy);

    assert.deepEqual(after.map((c) => c.sort_order), [SORT_ORDER_STEP, 20, 30]);
    assert.deepEqual(gappy.map((c) => c.sort_order), [3, 4000, 4001], 'resequence mutated its input');
});

test('the manager stages a copy, so an unsaved reorder changes nothing', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });

    page.openCategories();
    page.moveCategoryRow(3, 'up');

    assert.equal(page.categoryOrderDirty, true);
    assert.deepEqual(page.categoryOrder.map((c) => c.name), ['Dry store', 'Packaging', 'Walk-in']);
    // The page's own list is untouched until the reorder POST comes back.
    assert.deepEqual(page.categories.map((c) => c.name), ['Dry store', 'Walk-in', 'Packaging']);

    page.moveCategoryRow(1, 'up');
    assert.deepEqual(page.categoryOrder.map((c) => c.name), ['Dry store', 'Packaging', 'Walk-in']);
});

test('shared categories are never offered as editable rows', () => {
    const shared = cat(9, 'Chemicals', 40, { branch_id: null });
    const otherBranch = cat(10, 'Someone elses shelf', 10, { branch_id: 8 });
    const { page } = makePage({ categories: [...walkCategories(), shared, otherBranch], manageBranchId: '7' });

    page.openCategories();

    // The API 403s a rename/reorder/delete of a shared row, and another
    // branch's row must not even be visible here.
    assert.deepEqual(page.categoryOrder.map((c) => c.id), [1, 2, 3]);
    assert.equal(page.isEditableCategory(shared), false);
    assert.deepEqual(page.sharedCategories().map((c) => c.id), [9]);
});

test('the ingredient form offers this branchs active categories and the one being edited', () => {
    const inactive = cat(5, 'Retired shelf', 50, { is_active: false });
    const shared = cat(9, 'Chemicals', 40, { branch_id: null });
    const otherBranch = cat(10, 'Someone elses shelf', 10, { branch_id: 8 });
    const { page } = makePage({
        categories: [...walkCategories(), inactive, shared, otherBranch],
        manageBranchId: '7',
    });

    page.openEdit({ id: 1, branch_id: 7, name: 'Shoyu', unit: 'pcs', ingredient_category_id: 5 });

    assert.equal(page.form.ingredient_category_id, '5');
    // 5 is deactivated but IS this ingredient's category: dropping it would let
    // a save silently clear an assignment the owner never touched.
    assert.deepEqual(page.formCategories().map((c) => c.id), [1, 2, 3, 5, 9]);

    page.openCreate();
    page.form = { ...page.form, branch_id: '7' };
    assert.deepEqual(page.formCategories().map((c) => c.id), [1, 2, 3, 9]);
});

test('the new-category box and the picker are mutually exclusive', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });

    page.openCreate();
    page.form = { ...page.form, branch_id: '7', ingredient_category_id: '2' };

    page.toggleNewCategory();
    assert.equal(page.showNewCategory, true);
    assert.equal(page.form.ingredient_category_id, '');

    page.form = { ...page.form, new_category_name: 'Chiller' };
    page.toggleNewCategory();
    assert.equal(page.showNewCategory, false);
    assert.equal(page.form.new_category_name, '');
});

test('escape closes the category drawer with everything else', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });

    page.openCategories();
    page.closeAll();

    assert.equal(page.categoriesOpen, false);
});

/** A submit event stub: only preventDefault matters to these handlers. */
const submitEvent = () => {
    const event = { prevented: false };
    event.preventDefault = () => {
        event.prevented = true;
    };

    return event;
};

test('a drawer write asks before it discards a staged reorder', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });
    const prompts = [];
    global.window = {
        confirm: (message) => {
            prompts.push(message);

            return false;
        },
    };

    page.openCategories();
    page.moveCategoryRow(3, 'up');

    // Rename / Add / Active are full-page POSTs that redirect back and re-stage
    // the walk from the server, so the pending order would be gone with no
    // message at all.
    const event = submitEvent();
    assert.equal(page.confirmDiscardStagedOrder(event), false);
    assert.equal(event.prevented, true);
    assert.deepEqual(prompts, [CATEGORY_ORDER_DISCARD_WARNING]);
});

test('a clean drawer never prompts, and an accepted prompt lets the write through', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });
    let prompted = 0;
    global.window = {
        confirm: () => {
            prompted += 1;

            return true;
        },
    };

    page.openCategories();

    const clean = submitEvent();
    assert.equal(page.confirmDiscardStagedOrder(clean), true);
    assert.equal(clean.prevented, false);
    assert.equal(prompted, 0, 'nothing is pending, so nothing may be asked');

    page.moveCategoryRow(3, 'up');
    const accepted = submitEvent();
    assert.equal(page.confirmDiscardStagedOrder(accepted), true);
    assert.equal(accepted.prevented, false);
    assert.equal(prompted, 1);
});

test('a refused order prompt cancels the delete before it is even described', () => {
    const { page } = makePage({ categories: walkCategories(), manageBranchId: '7' });
    const prompts = [];
    global.window = {
        confirm: (message) => {
            prompts.push(message);

            return false;
        },
    };

    page.openCategories();
    page.moveCategoryRow(3, 'up');

    const event = submitEvent();
    page.confirmCategoryDelete(event, { id: 2, name: 'Walk-in', ingredient_count: 4 });

    assert.equal(event.prevented, true);
    // ONE prompt: refusing the order question must not then ask about the delete.
    assert.deepEqual(prompts, [CATEGORY_ORDER_DISCARD_WARNING]);
});
