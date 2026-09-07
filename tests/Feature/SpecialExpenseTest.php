<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\GcashWallet;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Special expenses are monthly overhead (rent, electricity) held in their own
 * table so they can never reach the daily figures.
 *
 * The isolation tests at the bottom are the load-bearing ones: they are the only
 * thing standing between a month's rent and the drawer variance a cashier is
 * held to at close. `expenses` is aggregated by hand-copied predicates in four
 * controllers, so "we remembered to filter" is not a guarantee anyone can make —
 * the guarantee is that these rows live somewhere those queries do not look.
 */
class SpecialExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'description' => 'Monthly rent',
            'amount' => 40000,
            'payment_method' => 'bank_transfer',
        ], $overrides);
    }

    // ── Access ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('special-expenses.index'))->assertRedirect(route('login'));
    }

    public function test_owner_can_view_the_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('special-expenses.index'))
            ->assertOk()
            ->assertSee('Special Expenses');
    }

    /**
     * Owner-only, like the daily expenses module. A cashier closing the drawer
     * must not be able to file a cost that never reaches the drawer.
     */
    public function test_cashier_cannot_view_the_page(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)
            ->get(route('special-expenses.index'))
            ->assertForbidden();
    }

    public function test_cashier_cannot_record_a_special_expense(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)
            ->post(route('special-expenses.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, SpecialExpense::count());
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function test_owner_can_record_a_special_expense(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload())
            ->assertRedirect();

        $record = SpecialExpense::firstOrFail();
        $this->assertSame(40000.00, (float) $record->amount);
        $this->assertSame('Monthly rent', $record->description);
        $this->assertSame($this->owner->id, $record->recorded_by_user_id);
    }

    /**
     * period_month is stored as the 1st no matter which day of the month is
     * submitted. scopeForMonth compares against a month start, so a mid-month
     * value would be filed into a month that every later query misses.
     */
    public function test_the_period_month_is_normalised_to_the_first_of_the_month(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload([
                'period_month' => '2026-09-23',
            ]))
            ->assertRedirect();

        $this->assertSame('2026-09-01', SpecialExpense::firstOrFail()->period_month->toDateString());
    }

    /**
     * A blank branch means "the whole business". This is the case that cannot be
     * expressed in the `expenses` table at all, where branch_id is NOT NULL.
     */
    public function test_a_special_expense_can_be_company_wide(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload([
                'branch_id' => null,
                'description' => 'Business permit',
            ]))
            ->assertRedirect();

        $this->assertNull(SpecialExpense::firstOrFail()->branch_id);
    }

    public function test_a_new_category_is_created_from_the_typed_name(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload([
                'new_category_name' => 'Garbage Collection',
            ]))
            ->assertRedirect();

        $category = SpecialExpenseCategory::where('slug', 'garbage-collection')->firstOrFail();
        $this->assertSame($category->id, SpecialExpense::firstOrFail()->special_expense_category_id);
    }

    /**
     * The typed name wins over the picker. The form disables the free-text input
     * when the picker has a value, so only one can arrive from the UI — this
     * pins the precedence the controller applies if both do.
     */
    public function test_the_typed_category_name_wins_over_the_picker(): void
    {
        // Rent is created by the seed migration; reuse it rather than colliding
        // with its unique slug.
        $picked = SpecialExpenseCategory::where('slug', 'rent')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload([
                'special_expense_category_id' => $picked->id,
                'new_category_name' => 'Association Dues',
            ]))
            ->assertRedirect();

        $created = SpecialExpenseCategory::where('slug', 'association-dues')->firstOrFail();
        $this->assertSame($created->id, SpecialExpense::firstOrFail()->special_expense_category_id);
    }

    public function test_owner_can_update_a_special_expense(): void
    {
        $record = SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'amount' => 100,
        ]);

        $this->actingAs($this->owner)
            ->put(route('special-expenses.update', $record), $this->payload(['amount' => 42500]))
            ->assertRedirect();

        $this->assertSame(42500.00, (float) $record->fresh()->amount);
    }

    /**
     * store() and update() share one rule set precisely so this cannot drift:
     * update writes every optional field as `?? null`, so a field validated in
     * one and not the other is wiped on save.
     */
    public function test_updating_preserves_the_optional_fields_the_form_submits(): void
    {
        $record = SpecialExpense::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->owner)
            ->put(route('special-expenses.update', $record), $this->payload([
                'vendor_name' => 'Meralco',
                'reference_no' => 'INV-77',
                'paid_date' => now()->toDateString(),
                'notes' => 'August reading',
            ]))
            ->assertRedirect();

        $fresh = $record->fresh();
        $this->assertSame('Meralco', $fresh->vendor_name);
        $this->assertSame('INV-77', $fresh->reference_no);
        $this->assertSame('August reading', $fresh->notes);
        $this->assertNotNull($fresh->paid_date);
    }

    public function test_owner_can_delete_a_special_expense(): void
    {
        $record = SpecialExpense::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->owner)
            ->delete(route('special-expenses.destroy', $record))
            ->assertRedirect();

        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_the_amount_must_be_positive(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload(['amount' => 0]))
            ->assertSessionHasErrors('amount');
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload(['payment_method' => 'crypto']))
            ->assertSessionHasErrors('payment_method');
    }

    // ── Listing ───────────────────────────────────────────────────────────

    public function test_the_page_shows_only_the_selected_month(): void
    {
        SpecialExpense::factory()->forMonth('2026-09-01')->create([
            'branch_id' => $this->branch->id,
            'amount' => 40000,
        ]);
        SpecialExpense::factory()->forMonth('2026-08-01')->create([
            'branch_id' => $this->branch->id,
            'amount' => 999,
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('special-expenses.index', ['month' => '2026-09-01']))
            ->assertOk();

        $this->assertSame(40000.00, $response->viewData('summary')['total']);
        $this->assertSame(1, $response->viewData('summary')['count']);
        $this->assertSame(999.00, $response->viewData('summary')['previous_total']);
    }

    /**
     * A malformed ?month= must fall back to the current month rather than 500 —
     * the value reaches the page from a bookmark or a hand-edited URL.
     */
    public function test_an_unparseable_month_falls_back_to_the_current_month(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('special-expenses.index', ['month' => 'not-a-date']))
            ->assertOk();

        $this->assertSame(now()->startOfMonth()->toDateString(), $response->viewData('filters')['month']);
    }

    // ── Robustness of the month picker and the option lists ───────────────

    /**
     * `?month[]=x` hands the controller an array. The (string) cast used to sit
     * outside resolveMonth()'s try/catch, so a mistyped link 500'd.
     */
    public function test_an_array_month_parameter_does_not_error(): void
    {
        $this->actingAs($this->owner)
            ->get(route('special-expenses.index').'?month[]=2026-01')
            ->assertOk();
    }

    /**
     * A <select> whose bound value matches no option falls back to its FIRST
     * option. If the picker only ever offered a fixed recent window, opening an
     * older row and saving any field would re-file it into the current month.
     * The option list must therefore reach back to the oldest row that exists.
     */
    public function test_the_month_picker_covers_months_older_than_the_default_window(): void
    {
        $old = now()->startOfMonth()->subMonths(30);

        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => $old->toDateString(),
            'amount' => 1234,
        ]);

        $options = $this->actingAs($this->owner)
            ->get(route('special-expenses.index', ['month' => $old->toDateString()]))
            ->assertOk()
            ->viewData('monthOptions');

        $this->assertContains(
            $old->toDateString(),
            array_column($options, 'value'),
            'a month holding data must be selectable, or editing its rows rewrites their month'
        );
    }

    /**
     * The month <select> must carry its options as real server-rendered <option>
     * tags. Building them with Alpine's x-for looks equivalent but is not: x-model
     * applies its value by selecting a matching option, and with x-for none exist
     * yet at that moment, so the browser falls back to the first option and x-model
     * writes that back into the state. Opening a March 2024 row then submitted the
     * current month and silently moved that row's rent into it — confirmed in a
     * real browser before this was reverted to server-rendered options.
     */
    public function test_the_month_select_options_are_server_rendered_for_the_viewed_month(): void
    {
        $old = now()->startOfMonth()->subMonths(30);

        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => $old->toDateString(),
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('special-expenses.index', ['month' => $old->toDateString()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<option value="'.$old->toDateString().'"',
            $html,
            'the viewed month must be a real <option> in the markup, not built at runtime'
        );
        $this->assertStringNotContainsString(
            'x-for="option in',
            $html,
            'month options must not be rendered by x-for — x-model cannot bind to them'
        );
    }

    /**
     * Same failure mode on the branch select: a row pointing at a deactivated
     * branch must still find its own branch in the list, or saving an unrelated
     * field converts a per-location cost into a company-wide one.
     */
    public function test_a_deactivated_branch_is_still_offered_for_rows_that_use_it(): void
    {
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
        ]);
        $this->branch->update(['is_active' => false]);

        $branches = $this->actingAs($this->owner)
            ->get(route('special-expenses.index'))
            ->assertOk()
            ->viewData('branches');

        $this->assertTrue(
            $branches->contains('id', $this->branch->id),
            'a row\'s own branch must stay selectable even once deactivated'
        );
    }

    /**
     * Str::slug('###') is '', and firstOrCreate keyed on '' would file every
     * such name under one shared row.
     */
    public function test_category_names_with_no_sluggable_characters_stay_distinct(): void
    {
        foreach (['###', '***'] as $name) {
            $this->actingAs($this->owner)
                ->post(route('special-expenses.store'), $this->payload(['new_category_name' => $name]))
                ->assertRedirect();
        }

        $names = SpecialExpenseCategory::whereIn('name', ['###', '***'])->pluck('name')->all();
        sort($names);

        $this->assertSame(['###', '***'], $names);
        $this->assertSame(2, SpecialExpense::whereNotNull('special_expense_category_id')
            ->distinct()->count('special_expense_category_id'));
    }

    /**
     * The dashboard rows sit directly under the Overhead tile and must sum to it.
     */
    public function test_every_overhead_category_reaches_the_dashboard_breakdown(): void
    {
        foreach (['Rent', 'Electricity', 'Water', 'Internet', 'Gas / LPG', 'Business Permit', 'Insurance'] as $i => $name) {
            SpecialExpense::factory()->create([
                'branch_id' => $this->branch->id,
                'special_expense_category_id' => SpecialExpenseCategory::where('name', $name)->value('id'),
                'period_month' => now()->startOfMonth()->toDateString(),
                'amount' => 1000 * ($i + 1),
            ]);
        }

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
        $breakdown = $response->viewData('overheadBreakdown');

        $this->assertCount(7, $breakdown, 'a capped list would not sum to the Overhead tile');
        $this->assertSame(
            (float) $response->viewData('mtdOverhead'),
            (float) array_sum(array_column($breakdown, 'total'))
        );
    }

    /**
     * branch_id is restrictOnDelete. NULL means "the whole business", so a
     * nullOnDelete would not clear these rows — it would relabel one location's
     * entire rent history as company-wide, silently and irreversibly.
     */
    public function test_a_branch_with_overhead_cannot_be_deleted(): void
    {
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->delete(route('branches.destroy', $this->branch))
            ->assertRedirect();

        $this->assertDatabaseHas('branches', ['id' => $this->branch->id]);
        $this->assertSame($this->branch->id, SpecialExpense::firstOrFail()->branch_id);
    }

    // ── Isolation: the reason this table exists ───────────────────────────

    /**
     * The whole point. A month's rent dated today must not move a single one of
     * today's figures — not net income, not cash on hand, not the daily expense
     * total, and not month-to-date operating expenses.
     *
     * If this test ever fails, someone has merged special expenses back into the
     * `expenses` table, and every day-closure variance for that month is wrong.
     */
    public function test_a_special_expense_does_not_touch_any_daily_dashboard_figure(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => now(),
            'payment_method' => 'cash',
            'grand_total' => 5000,
        ]);
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 500,
            'status' => 'approved',
        ]);

        $before = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 40000,
        ]);

        $after = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        foreach (['todayExpenses', 'todayCashExpenses', 'todayNetIncome', 'todayCashOnHand', 'mtdExpenses'] as $key) {
            $this->assertSame(
                $before->viewData($key),
                $after->viewData($key),
                "Special expenses leaked into the daily figure [{$key}]."
            );
        }

        $this->assertSame(500.00, $after->viewData('todayExpenses'));
        $this->assertSame(4500.00, $after->viewData('todayNetIncome'));
    }

    /**
     * The counterpart: overhead must reach the MONTHLY figures, or the owner
     * records rent and never sees it anywhere.
     */
    public function test_a_special_expense_does_reach_the_monthly_overhead_figures(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => now(),
            'payment_method' => 'cash',
            'grand_total' => 100000,
        ]);
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'amount' => 10000,
            'status' => 'approved',
        ]);
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 40000,
        ]);

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(40000.00, $response->viewData('mtdOverhead'));
        // 100,000 revenue − 10,000 daily − 40,000 overhead
        $this->assertSame(50000.00, $response->viewData('mtdNetAfterOverhead'));
    }

    /**
     * Overhead from a different month must not bleed into this month's figure.
     */
    public function test_last_months_overhead_is_not_counted_this_month(): void
    {
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->subMonth()->toDateString(),
            'amount' => 40000,
        ]);

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(0.00, (float) $response->viewData('mtdOverhead'));
    }

    /**
     * The money-critical one. A ₱40,000 cash-marked special expense must leave
     * expected_cash, the variance and the expense count untouched — otherwise
     * the closing cashier is told the drawer is ₱40,000 short.
     */
    public function test_a_special_expense_does_not_move_the_drawer_reconciliation(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => now(),
            'payment_method' => 'cash',
            'grand_total' => 5000,
        ]);

        $params = ['branch_id' => $this->branch->id, 'date' => now()->toDateString()];

        $before = $this->actingAs($this->owner)
            ->getJson(route('day-close.preview', $params))
            ->assertOk()
            ->json('totals');

        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 40000,
        ]);

        $after = $this->actingAs($this->owner)
            ->getJson(route('day-close.preview', $params))
            ->assertOk()
            ->json('totals');

        $this->assertSame($before, $after, 'Special expenses leaked into the drawer reconciliation.');
        $this->assertSame(0.00, (float) $after['cash_expenses_total']);
        $this->assertSame(0, $after['expense_count']);
        $this->assertSame(5000.00, (float) $after['expected_cash']);
    }

    /**
     * Same guarantee on the mobile close-day path, which is a byte-identical
     * copy of computeTotals() in a second controller. Pinning only the web path
     * would leave the app that cashiers actually close from unprotected.
     */
    public function test_a_special_expense_does_not_move_the_mobile_drawer_reconciliation(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'sale_datetime' => now(),
            'payment_method' => 'cash',
            'grand_total' => 5000,
        ]);
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 40000,
        ]);

        $totals = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/day-close/preview?branch_id='.$this->branch->id.'&date='.now()->toDateString())
            ->assertOk()
            ->json('totals');

        $this->assertSame(0.00, (float) $totals['cash_expenses_total']);
        $this->assertSame(0, $totals['expense_count']);
        $this->assertSame(5000.00, (float) $totals['expected_cash']);
    }

    // ── The one place overhead SHOULD move an existing figure ─────────────

    /**
     * The mirror of the drawer test. The GCash wallet is not a daily figure — it is
     * a real-money position — so GCash-paid overhead genuinely left the account and
     * the balance must fall, or it never reconciles against the GCash app.
     *
     * Before special expenses existed the owner recorded rent as a daily gcash
     * expense and the wallet DID decrement, so leaving this out would have been a
     * regression, not merely a gap.
     */
    public function test_gcash_paid_overhead_reduces_the_gcash_wallet_balance(): void
    {
        GcashWallet::create([
            'branch_id' => $this->branch->id,
            'opening_balance' => 50000,
            'opening_date' => now()->startOfMonth()->toDateString(),
        ]);

        $before = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->viewData('wallet');

        SpecialExpense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 13000,
        ]);

        $after = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->viewData('wallet');

        $this->assertSame(50000.00, (float) $before['balance']);
        $this->assertSame(37000.00, (float) $after['balance']);
        $this->assertSame(13000.00, (float) $after['outflow']);
    }

    /**
     * ...but it must NOT move net_gcash, which measures trading performance. A
     * month's rent would drag that negative in a perfectly healthy month.
     */
    public function test_gcash_paid_overhead_does_not_move_net_gcash(): void
    {
        $before = $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->viewData('totals');

        SpecialExpense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 13000,
        ]);

        $after = $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->viewData('totals');

        $this->assertSame((float) $before['net_gcash'], (float) $after['net_gcash']);
        $this->assertSame((float) $before['gcash_expenses_total'], (float) $after['gcash_expenses_total']);
    }

    /**
     * Company-wide overhead belongs to no branch's wallet. Applied per-branch it
     * would be subtracted once for every branch that exists.
     */
    public function test_company_wide_gcash_overhead_is_deducted_exactly_once(): void
    {
        $second = Branch::factory()->create();
        foreach ([$this->branch, $second] as $branch) {
            GcashWallet::create([
                'branch_id' => $branch->id,
                'opening_balance' => 10000,
                'opening_date' => now()->startOfMonth()->toDateString(),
            ]);
        }

        SpecialExpense::factory()->gcash()->companyWide()->create([
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 5000,
        ]);

        $wallet = $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->viewData('wallet');

        // 10,000 + 10,000 − 5,000 (not − 10,000)
        $this->assertSame(15000.00, (float) $wallet['balance']);
        $this->assertSame(5000.00, (float) $wallet['company_wide_overhead']);
    }

    /**
     * A single-branch view is a statement about that branch's wallet, and
     * company-wide money belongs to no branch.
     */
    public function test_company_wide_overhead_is_absent_from_a_single_branch_wallet(): void
    {
        GcashWallet::create([
            'branch_id' => $this->branch->id,
            'opening_balance' => 10000,
            'opening_date' => now()->startOfMonth()->toDateString(),
        ]);

        SpecialExpense::factory()->gcash()->companyWide()->create([
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 5000,
        ]);

        $wallet = $this->actingAs($this->owner)
            ->get(route('gcash-report.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->viewData('wallet');

        $this->assertSame(10000.00, (float) $wallet['balance']);
        $this->assertSame(0.00, (float) $wallet['company_wide_overhead']);
    }

    /**
     * The daily Expenses tab must not list overhead either — it is a different
     * table, so this holds by construction, but the tab shares a page with the
     * special one and a future "unify the list" refactor should trip here.
     */
    public function test_the_daily_expenses_page_does_not_list_special_expenses(): void
    {
        SpecialExpense::factory()->create([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'description' => 'Monthly rent',
            'amount' => 40000,
        ]);

        $response = $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk();

        $this->assertSame(0.0, $response->viewData('summary')['total']);
        $this->assertCount(0, $response->viewData('expenses'));
    }
}
