<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * closures:recalculate compares a closure's four money columns against the rows that
 * exist now. If they agree it stops there — so a closure whose stored expected_cash or
 * variance disagrees with its OWN stored components is reported clean, because the
 * components themselves are fine.
 *
 * That is the gap a "the report says everything reconciles" run cannot close by itself.
 * These two identities have to hold on every row:
 *
 *   expected_cash = opening_float + cash_sales_total + mixed_cash_total - cash_expenses_total
 *   variance      = counted_cash - expected_cash
 */
class ClosureSelfConsistencyAuditTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function closure(array $overrides = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => '2026-09-10',
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => '2026-09-10 21:30:00',
            // No sales or expense rows exist, so every component is zero and the row
            // check has nothing to report. That isolates the self-consistency check.
            'opening_float' => 1000,
            'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 1000, 'counted_cash' => 1000, 'variance' => 0,
            'order_count' => 0, 'expense_count' => 0,
        ], $overrides));
    }

    public function test_a_consistent_closure_reports_clean(): void
    {
        $this->closure();

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('0 disagree with their rows. 0 disagree with themselves')
            ->assertSuccessful();
    }

    public function test_a_broken_expected_cash_is_reported(): void
    {
        $c = $this->closure();
        // No row changes — only the stored snapshot is wrong.
        DB::table('day_closures')->where('id', $c->id)->update(['expected_cash' => 9999]);

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('1 disagree with themselves')
            ->assertSuccessful();
    }

    public function test_a_broken_variance_is_reported(): void
    {
        $c = $this->closure();
        DB::table('day_closures')->where('id', $c->id)->update(['variance' => 250]);

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('1 disagree with themselves')
            ->assertSuccessful();
    }

    public function test_the_self_check_is_independent_of_the_row_check(): void
    {
        $c = $this->closure();
        DB::table('day_closures')->where('id', $c->id)->update(['variance' => 250]);

        // Rows still agree with the components, so the row check alone stays silent.
        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('0 disagree with their rows. 1 disagree with themselves')
            ->assertSuccessful();
    }

    public function test_apply_repairs_a_self_inconsistent_closure(): void
    {
        $c = $this->closure();
        DB::table('day_closures')->where('id', $c->id)->update(['expected_cash' => 9999, 'variance' => -8999]);

        $this->artisan('closures:recalculate --apply')->assertSuccessful();

        $fresh = $c->fresh();
        $this->assertEqualsWithDelta(1000.0, (float) $fresh->expected_cash, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->variance, 0.005);
    }
}
