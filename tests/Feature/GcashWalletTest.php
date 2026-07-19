<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\GcashAdjustment;
use App\Models\GcashEntryStatus;
use App\Models\GcashWallet;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GcashWalletTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create(['code' => 'MAIN001', 'name' => 'Main']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function gcashSale(float $amount, ?string $date = null, ?Branch $branch = null): Sale
    {
        return Sale::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'payment_method' => 'gcash',
            'status' => 'completed',
            'sale_datetime' => $date ? $date.' 12:00:00' : now(),
            'grand_total' => $amount,
        ]);
    }

    private function gcashExpense(float $amount, ?string $date = null, ?Branch $branch = null): Expense
    {
        return Expense::factory()->gcash()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'expense_date' => $date ?? now()->toDateString(),
            'amount' => $amount,
        ]);
    }

    private function wallet(float $opening, ?string $since = null, ?Branch $branch = null): GcashWallet
    {
        return GcashWallet::create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'opening_balance' => $opening,
            'opening_date' => $since ?? now()->subYear()->toDateString(),
        ]);
    }

    private function setStatus(string $type, int $id, string $status): void
    {
        $this->actingAs($this->owner)
            ->put(route('gcash-report.entries.status', ['type' => $type, 'id' => $id]), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    private function balance(array $query = []): float
    {
        return $this->actingAs($this->owner)
            ->get(route('gcash-report.index', $query))
            ->viewData('wallet')['balance'];
    }

    public function test_balance_is_opening_plus_inflow_minus_outflow_plus_adjustments(): void
    {
        $this->wallet(1000.00);
        $this->gcashSale(500.00);
        $this->gcashExpense(200.00);
        GcashAdjustment::factory()->create([
            'branch_id' => $this->branch->id,
            'adjustment_date' => now()->toDateString(),
            'amount' => -50.00,
        ]);

        $this->assertSame(1250.00, $this->balance());
    }

    public function test_balance_without_a_configured_wallet_is_movement_only(): void
    {
        $this->gcashSale(500.00);

        $wallet = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('wallet');

        $this->assertSame(500.00, $wallet['balance']);
        $this->assertFalse($wallet['configured']);
    }

    /**
     * The balance is a running position, not a period figure — the report's date filter must
     * not move it, or it would stop describing what is actually in the account.
     */
    public function test_balance_ignores_the_reports_date_range(): void
    {
        $this->wallet(0.00, now()->subYear()->toDateString());
        $this->gcashSale(500.00, now()->subDays(90)->toDateString());
        $this->gcashSale(300.00, now()->toDateString());

        // A range that excludes the older sale still counts it in the balance.
        $narrow = $this->balance([
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertSame(800.00, $narrow);

        // ...while the range-scoped tile does move.
        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))->viewData('totals');

        $this->assertSame(300.00, $totals['gcash_sales_total']);
    }

    public function test_entries_before_the_opening_date_are_not_double_counted(): void
    {
        // Opening balance already includes everything up to that day.
        $this->wallet(1000.00, now()->subDays(10)->toDateString());
        $this->gcashSale(400.00, now()->subDays(30)->toDateString());
        $this->gcashSale(250.00, now()->toDateString());

        $this->assertSame(1250.00, $this->balance());
    }

    public function test_balance_follows_the_branch_filter_and_sums_across_branches(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB', 'name' => 'Second']);

        $this->wallet(100.00);
        $this->wallet(700.00, null, $other);
        $this->gcashSale(50.00);
        $this->gcashSale(20.00, null, $other);

        $this->assertSame(150.00, $this->balance(['branch_id' => $this->branch->id]));
        $this->assertSame(720.00, $this->balance(['branch_id' => $other->id]));
        $this->assertSame(870.00, $this->balance());
    }

    /**
     * Deactivating a branch is an admin flag, not a withdrawal — its GCash money still exists,
     * so the all-branches balance must keep counting it.
     */
    public function test_an_inactive_branch_still_counts_toward_the_balance(): void
    {
        $closed = Branch::factory()->create(['code' => 'OLD', 'name' => 'Closed', 'is_active' => false]);
        $this->wallet(200.00, null, $closed);
        $this->gcashSale(50.00, null, $closed);

        $this->wallet(100.00);

        $this->assertSame(350.00, $this->balance());
    }

    public function test_declining_a_sale_removes_it_from_the_balance_and_the_totals(): void
    {
        $this->wallet(0.00);
        $this->gcashSale(500.00);
        $missing = $this->gcashSale(300.00);

        $this->assertSame(800.00, $this->balance());

        $this->setStatus('sale', $missing->id, 'declined');

        $this->assertSame(500.00, $this->balance());
        $totals = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('totals');
        $this->assertSame(500.00, $totals['gcash_sales_total']);
        $this->assertSame(1, $totals['declined_count']);
    }

    public function test_declining_an_expense_puts_the_money_back_in_the_balance(): void
    {
        $this->wallet(1000.00);
        $expense = $this->gcashExpense(200.00);

        $this->assertSame(800.00, $this->balance());

        // It never actually left the wallet.
        $this->setStatus('expense', $expense->id, 'declined');

        $this->assertSame(1000.00, $this->balance());
    }

    public function test_accepting_an_entry_leaves_the_figures_unchanged(): void
    {
        $this->wallet(0.00);
        $sale = $this->gcashSale(500.00);

        $this->assertSame(500.00, $this->balance());

        // Pending already counts, so confirming is an audit trail, not a maths change.
        $this->setStatus('sale', $sale->id, 'accepted');

        $this->assertSame(500.00, $this->balance());
        $this->assertSame('accepted', GcashEntryStatus::firstOrFail()->status);
        $this->assertSame($this->owner->id, GcashEntryStatus::firstOrFail()->reviewed_by_user_id);
    }

    public function test_a_decline_can_be_undone(): void
    {
        $this->wallet(0.00);
        $sale = $this->gcashSale(500.00);

        $this->setStatus('sale', $sale->id, 'declined');
        $this->assertSame(0.0, $this->balance());

        $this->setStatus('sale', $sale->id, 'pending');

        $this->assertSame(500.00, $this->balance());
        // Pending is the absence of a verdict, not a third stored state.
        $this->assertDatabaseCount('gcash_entry_statuses', 0);
    }

    /**
     * A declined row must stay listed, or there would be no way to reverse a mistaken decline.
     */
    public function test_a_declined_entry_is_still_listed(): void
    {
        $sale = $this->gcashSale(500.00);
        $this->setStatus('sale', $sale->id, 'declined');

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $this->assertCount(1, $response->viewData('sales'));
        $this->assertSame('declined', $response->viewData('statuses')['sale:'.$sale->id]['status']);
    }

    public function test_declining_only_removes_the_gcash_slice_of_a_mixed_sale(): void
    {
        $this->wallet(0.00);
        $mixed = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_method' => 'mixed',
            'status' => 'completed',
            'sale_datetime' => now(),
            'grand_total' => 1000.00,
            'cash_amount' => 400.00,
            'gcash_amount' => 600.00,
        ]);

        $this->assertSame(600.00, $this->balance());

        $this->setStatus('sale', $mixed->id, 'declined');

        // The cash half is untouched — it was never in the wallet to begin with.
        $this->assertSame(0.0, $this->balance());
        $this->assertSame(1000.00, (float) $mixed->fresh()->grand_total);
    }

    public function test_reviewing_never_alters_the_underlying_sale_or_expense(): void
    {
        $sale = $this->gcashSale(500.00);
        $expense = $this->gcashExpense(200.00);

        $this->setStatus('sale', $sale->id, 'declined');
        $this->setStatus('expense', $expense->id, 'declined');

        $this->assertSame(500.00, (float) $sale->fresh()->grand_total);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(200.00, (float) $expense->fresh()->amount);
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_owner_can_set_and_move_the_opening_balance(): void
    {
        $this->gcashSale(100.00, now()->subDays(5)->toDateString());

        $this->actingAs($this->owner)->put(route('gcash-report.wallet.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => 5000.00,
            'opening_date' => now()->subDays(10)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(5100.00, $this->balance());

        // Moving the start past the sale folds it into the opening figure instead.
        $this->actingAs($this->owner)->put(route('gcash-report.wallet.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => 5000.00,
            'opening_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(5000.00, $this->balance());
        $this->assertDatabaseCount('gcash_wallets', 1);
    }

    /**
     * The drawer edits ONE branch's wallet, so it must be seeded per branch. It used to be
     * seeded from the summary, whose opening_balance is a cross-branch TOTAL and whose
     * opening_date is null on the all-branches view — so opening the drawer and pressing Save
     * without touching anything overwrote one branch's wallet with the sum of every branch's.
     */
    public function test_the_opening_balance_drawer_is_seeded_per_branch_not_from_the_total(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB', 'name' => 'Second']);
        $this->wallet(1000.00, '2026-01-01');
        $this->wallet(250.00, '2026-03-15', $other);

        $defaults = $this->actingAs($this->owner)
            ->get(route('gcash-report.index'))
            ->assertOk()
            ->viewData('walletDefaults');

        // Each branch carries its own figures — never 1250.00, the sum.
        $this->assertSame('1000.00', $defaults[(string) $this->branch->id]['opening_balance']);
        $this->assertSame('2026-01-01', $defaults[(string) $this->branch->id]['opening_date']);
        $this->assertSame('250.00', $defaults[(string) $other->id]['opening_balance']);
        $this->assertSame('2026-03-15', $defaults[(string) $other->id]['opening_date']);
    }

    public function test_saving_the_drawer_untouched_leaves_the_balance_unchanged(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB', 'name' => 'Second']);
        $this->wallet(1000.00, '2026-01-01');
        $this->wallet(250.00, '2026-03-15', $other);

        $before = $this->balance();

        // Exactly what the drawer now posts for the default branch, with nothing edited.
        $defaults = $this->actingAs($this->owner)->get(route('gcash-report.index'))->viewData('walletDefaults');
        $this->actingAs($this->owner)->put(route('gcash-report.wallet.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => $defaults[(string) $this->branch->id]['opening_balance'],
            'opening_date' => $defaults[(string) $this->branch->id]['opening_date'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->balance());
        $this->assertSame(1000.00, (float) GcashWallet::where('branch_id', $this->branch->id)->value('opening_balance'));
    }

    /**
     * The summary line used to be gated on opening_date as well, which is always null across
     * branches — so the all-branches view told the owner to set an opening balance that was
     * already set, and hid the breakdown.
     */
    public function test_the_all_branches_card_does_not_claim_the_opening_balance_is_unset(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB', 'name' => 'Second']);
        $this->wallet(1000.00, '2026-01-01');
        $this->wallet(250.00, '2026-03-15', $other);

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();

        $response->assertDontSee('Movement only');
        $this->assertTrue($response->viewData('wallet')['configured']);
        $this->assertSame(1250.00, $response->viewData('wallet')['opening_balance']);
    }

    public function test_a_partly_configured_estate_says_so_rather_than_claiming_nothing_is_set(): void
    {
        $other = Branch::factory()->create(['code' => 'BBB', 'name' => 'Second']);
        $this->wallet(1000.00);
        // $other deliberately has no wallet.

        $response = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk();
        $wallet = $response->viewData('wallet');

        $this->assertFalse($wallet['configured']);
        $this->assertSame(1, $wallet['unconfigured_count']);
        $this->assertSame(2, $wallet['scope_count']);
        $response->assertSee('Partly set', false);
        $this->assertSame($other->id, $other->id);
    }

    public function test_wallet_and_status_changes_are_owner_only(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $sale = $this->gcashSale(500.00);

        $this->actingAs($cashier)->put(route('gcash-report.wallet.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => 1.00,
            'opening_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($cashier)->put(
            route('gcash-report.entries.status', ['type' => 'sale', 'id' => $sale->id]),
            ['status' => 'declined']
        )->assertForbidden();

        $this->assertDatabaseCount('gcash_entry_statuses', 0);
        $this->assertDatabaseCount('gcash_wallets', 0);
    }

    public function test_status_endpoint_rejects_bad_input(): void
    {
        $sale = $this->gcashSale(500.00);

        $this->actingAs($this->owner)->put(
            route('gcash-report.entries.status', ['type' => 'sale', 'id' => $sale->id]),
            ['status' => 'maybe']
        )->assertSessionHasErrors('status');

        // A non-existent entry must not create an orphan verdict.
        $this->actingAs($this->owner)->put(
            route('gcash-report.entries.status', ['type' => 'sale', 'id' => 999999]),
            ['status' => 'declined']
        )->assertSessionHas('error');

        $this->assertDatabaseCount('gcash_entry_statuses', 0);
    }

    public function test_an_unknown_entry_type_is_not_routable(): void
    {
        $this->actingAs($this->owner)
            ->put('/gcash-report/entries/adjustment/1/status', ['status' => 'declined'])
            ->assertNotFound();
    }

    public function test_wallet_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->owner)->put(route('gcash-report.wallet.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => 100.999,
            'opening_date' => now()->toDateString(),
        ])->assertSessionHasErrors('opening_balance');

        $this->actingAs($this->owner)->put(route('gcash-report.wallet.update'), [
            'branch_id' => 999999,
            'opening_balance' => 10.00,
            'opening_date' => now()->toDateString(),
        ])->assertSessionHasErrors('branch_id');

        $this->assertDatabaseCount('gcash_wallets', 0);
    }

    public function test_re_reviewing_updates_the_verdict_in_place(): void
    {
        $sale = $this->gcashSale(500.00);

        $this->setStatus('sale', $sale->id, 'accepted');
        $this->setStatus('sale', $sale->id, 'declined');

        $this->assertDatabaseCount('gcash_entry_statuses', 1);
        $this->assertSame('declined', GcashEntryStatus::firstOrFail()->status);
    }

    /**
     * Ids are only unique within a type, so a sale and an expense sharing an id must not be
     * confused for one another.
     */
    public function test_a_sale_and_an_expense_with_the_same_id_are_reviewed_independently(): void
    {
        $this->wallet(1000.00);
        $sale = $this->gcashSale(500.00);
        $expense = $this->gcashExpense(200.00);

        $this->assertSame($sale->id, $expense->id, 'Precondition: both should be id 1.');

        $this->setStatus('sale', $sale->id, 'declined');

        // Only the sale came out; the expense still counts.
        $this->assertSame(800.00, $this->balance());
    }
}
