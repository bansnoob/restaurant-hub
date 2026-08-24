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
    COUNT_BRANCH_PROMPT_MESSAGE,
    COUNT_BRANCH_EMPTY_MESSAGE,
    COUNT_SESSION_ERROR_MESSAGE,
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
