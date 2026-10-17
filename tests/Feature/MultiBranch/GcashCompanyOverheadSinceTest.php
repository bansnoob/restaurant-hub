<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\GcashWallet;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each branch's GCash balance counts from its wallet's opening date, but the
 * company-wide GCash overhead (special expenses with no branch) was subtracted for
 * all time. Overhead paid before every wallet's opening balance was struck is
 * already inside those opening balances, so it was counted twice.
 *
 * It is now taken from the earliest opening date, when every branch in scope has a
 * wallet. If any branch has none, that branch counts from the beginning of time,
 * so all-time overhead is still the consistent figure.
 */
class GcashCompanyOverheadSinceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();

        SpecialExpense::factory()->companyWide()->create([
            'payment_method' => 'gcash', 'amount' => 1000,
            'paid_date' => '2026-08-15', 'period_month' => '2026-08-01',
        ]);
        SpecialExpense::factory()->companyWide()->create([
            'payment_method' => 'gcash', 'amount' => 300,
            'paid_date' => '2026-09-15', 'period_month' => '2026-09-01',
        ]);
    }

    private function wallet(Branch $branch, string $openingDate): void
    {
        GcashWallet::factory()->create(['branch_id' => $branch->id, 'opening_balance' => 0, 'opening_date' => $openingDate]);
    }

    private function companyOverhead(): float
    {
        return (float) $this->actingAs($this->owner)
            ->get('/gcash-report?date_from=2026-01-01&date_to=2026-12-31')
            ->assertOk()
            ->viewData('wallet')['company_wide_overhead'];
    }

    public function test_overhead_before_every_opening_balance_is_not_subtracted_again(): void
    {
        $this->wallet($this->main, '2026-09-01');
        $this->wallet($this->second, '2026-10-01');

        $this->assertEquals(300, $this->companyOverhead());
    }

    public function test_a_branch_without_a_wallet_keeps_all_time_overhead(): void
    {
        $this->wallet($this->main, '2026-09-01');

        $this->assertEquals(1300, $this->companyOverhead());
    }

    public function test_no_wallets_at_all_keeps_all_time_overhead(): void
    {
        $this->assertEquals(1300, $this->companyOverhead());
    }
}
