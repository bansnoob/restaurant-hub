<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashOpeningBalance;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cash on Hand: the cash the business should be holding now, counted forward from an
 * opening balance the owner enters.
 *
 * Net Cash Taken describes a date range and can never be a balance, because nothing
 * before the range is in it. This figure is the running position the owner can check
 * against the cash in their hands.
 */
class CashOnHandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }

        $this->branch = Branch::factory()->create(['name' => 'Ramen Naijiro']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function opening(float $balance, string $date, ?Branch $branch = null): CashOpeningBalance
    {
        return CashOpeningBalance::create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'opening_balance' => $balance,
            'opening_date' => $date,
        ]);
    }

    private function closedDay(string $date, float $counted, float $float = 0, ?Branch $branch = null): DayClosure
    {
        return DayClosure::create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 21:00:00',
            'opening_float' => $float,
            'cash_sales_total' => $counted - $float,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => $counted,
            'counted_cash' => $counted,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 0,
        ]);
    }

    private function overhead(float $amount, string $paidDate, array $attributes = []): SpecialExpense
    {
        return SpecialExpense::create(array_merge([
            'branch_id' => $this->branch->id,
            'period_month' => substr($paidDate, 0, 8).'01',
            'paid_date' => $paidDate,
            'description' => 'Rent',
            'amount' => $amount,
            'payment_method' => 'cash',
        ], $attributes));
    }

    private function expense(float $amount, string $date, string $paidFrom, array $attributes = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => $date,
            'description' => 'Oil',
            'amount' => $amount,
            'payment_method' => 'cash',
            'paid_from' => $paidFrom,
            'status' => 'approved',
        ], $attributes));
    }

    private function cashOnHand(array $query = []): array
    {
        return $this->actingAs($this->owner)
            ->get(route('day-closures.index', $query))
            ->assertOk()
            ->viewData('cashBalance');
    }

    public function test_balance_is_opening_plus_cash_taken_minus_cash_paid_outside_since_the_opening_date(): void
    {
        $this->opening(3710, '2026-11-01');

        $this->closedDay('2026-10-31', 4750);          // before the opening: already in it
        $this->closedDay('2026-11-01', 0);
        $this->closedDay('2026-11-02', 7480, 500);     // the float stays in the till: +6,980
        $this->overhead(1000, '2026-11-03');
        $this->overhead(2000, '2026-11-03', ['payment_method' => 'gcash']);
        $this->overhead(18000, '2026-10-30');           // paid before the opening
        $this->expense(300, '2026-11-04', 'outside');
        $this->expense(200, '2026-11-04', 'drawer');    // already out of the counted cash
        $this->expense(999, '2026-11-04', 'outside', ['status' => 'voided']);

        $cash = $this->cashOnHand(['branch_id' => $this->branch->id]);

        $this->assertTrue($cash['configured']);
        $this->assertSame('2026-11-01', $cash['opening_date']);
        $this->assertEqualsWithDelta(3710, $cash['opening_balance'], 0.001);
        $this->assertEqualsWithDelta(6980, $cash['taken'], 0.001);
        $this->assertEqualsWithDelta(1300, $cash['paid_outside'], 0.001);
        $this->assertEqualsWithDelta(3710 + 6980 - 1300, $cash['balance'], 0.001);
    }

    public function test_a_day_counts_only_once_it_is_closed(): void
    {
        $this->opening(3710, '2026-11-01');
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => '2026-11-06 11:00:00',
            'grand_total' => 5000,
        ]);

        $this->assertEqualsWithDelta(3710, $this->cashOnHand(['branch_id' => $this->branch->id])['balance'], 0.001);
    }

    public function test_balance_ignores_the_reports_date_range(): void
    {
        $this->opening(3710, '2026-11-01');
        $this->closedDay('2026-11-02', 7480);

        $cash = $this->cashOnHand([
            'branch_id' => $this->branch->id,
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]);

        $this->assertEqualsWithDelta(3710 + 7480, $cash['balance'], 0.001);
    }

    public function test_without_an_opening_balance_it_counts_every_closed_day(): void
    {
        $this->closedDay('2026-10-31', 4750);
        $this->closedDay('2026-11-02', 7480);

        $cash = $this->cashOnHand(['branch_id' => $this->branch->id]);

        $this->assertFalse($cash['configured']);
        $this->assertEqualsWithDelta(4750 + 7480, $cash['balance'], 0.001);
    }

    public function test_all_branches_adds_each_branch_and_takes_company_wide_overhead_once(): void
    {
        $second = Branch::factory()->create(['name' => 'Samgyupsal']);
        $this->opening(3710, '2026-11-01');
        $this->opening(1000, '2026-11-03', $second);
        $this->closedDay('2026-11-02', 7480);
        $this->closedDay('2026-11-02', 900, 0, $second);   // before Samgyupsal's opening
        $this->closedDay('2026-11-04', 2500, 0, $second);
        $this->overhead(600, '2026-11-02', ['branch_id' => null]);
        $this->overhead(5000, '2026-10-15', ['branch_id' => null]); // before every opening

        $cash = $this->cashOnHand();

        $this->assertTrue($cash['configured']);
        $this->assertEqualsWithDelta(600, $cash['company_wide_overhead'], 0.001);
        $this->assertEqualsWithDelta((3710 + 7480) + (1000 + 2500) - 600, $cash['balance'], 0.001);
        $this->assertSame(
            [['name' => 'Ramen Naijiro', 'balance' => 11190.0], ['name' => 'Samgyupsal', 'balance' => 3500.0]],
            array_map(fn ($row) => ['name' => $row['name'], 'balance' => $row['balance']], $cash['per_branch'])
        );

        // A single branch's view leaves company-wide overhead out: it belongs to no branch.
        $this->assertEqualsWithDelta(3710 + 7480, $this->cashOnHand(['branch_id' => $this->branch->id])['balance'], 0.001);
    }

    public function test_the_cash_report_shows_the_balance_and_where_it_counts_from(): void
    {
        $this->opening(3710, '2026-11-01');
        $this->closedDay('2026-11-02', 7480);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('Cash on Hand')
            ->assertSee('₱11,190.00')
            ->assertSeeInOrder(['Opening ₱3,710.00', 'on Nov 1, 2026', 'taken ₱7,480.00']);
    }

    public function test_owner_can_set_and_move_the_opening_balance(): void
    {
        $this->actingAs($this->owner)->put(route('day-closures.opening-balance.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => '3710',
            'opening_date' => '2026-11-01',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->actingAs($this->owner)->put(route('day-closures.opening-balance.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => '4000.50',
            'opening_date' => '2026-11-02',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CashOpeningBalance::count());
        $row = CashOpeningBalance::firstOrFail();
        $this->assertSame('4000.50', $row->opening_balance);
        $this->assertSame('2026-11-02', $row->opening_date->toDateString());
        $this->assertSame($this->owner->id, $row->updated_by_user_id);
    }

    public function test_opening_balance_validation_rejects_bad_input(): void
    {
        foreach ([
            ['opening_balance' => '-1', 'opening_date' => '2026-11-01'],
            ['opening_balance' => 'abc', 'opening_date' => '2026-11-01'],
            ['opening_balance' => '3710', 'opening_date' => '2026-11-07'],   // not yet happened
            ['opening_balance' => '3710', 'opening_date' => ''],
        ] as $payload) {
            $this->actingAs($this->owner)
                ->put(route('day-closures.opening-balance.update'), ['branch_id' => $this->branch->id] + $payload)
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, CashOpeningBalance::count());
    }

    public function test_a_branch_admin_can_only_set_their_own_branch(): void
    {
        $other = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('day-closures.opening-balance.update'), [
            'branch_id' => $other->id,
            'opening_balance' => '3710',
            'opening_date' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->branch->id], CashOpeningBalance::pluck('branch_id')->all());
    }

    public function test_a_cashier_cannot_set_an_opening_balance(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->put(route('day-closures.opening-balance.update'), [
            'branch_id' => $this->branch->id,
            'opening_balance' => '3710',
            'opening_date' => '2026-11-01',
        ])->assertRedirect(url('/pos/'));

        $this->assertSame(0, CashOpeningBalance::count());
    }
}
