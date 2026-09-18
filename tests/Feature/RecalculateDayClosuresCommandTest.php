<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecalculateDayClosuresCommandTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
        $this->date = now()->subDay()->toDateString();
    }

    /** A closure whose stored expenses no longer match the rows behind it. */
    private function driftedClosure(): DayClosure
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'completed', 'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00', 'closed_at' => $this->date.' 12:00:00',
            'grand_total' => 1000,
        ]);
        Expense::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'approved', 'payment_method' => 'cash',
            'expense_date' => $this->date, 'amount' => 500,
        ]);

        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $this->date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 1000, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            // Stale: the rows say 500.
            'cash_expenses_total' => 100,
            'expected_cash' => 900, 'counted_cash' => 500, 'variance' => -400,
            'order_count' => 1, 'expense_count' => 1,
        ]);
    }

    public function test_a_dry_run_reports_drift_without_writing(): void
    {
        $closure = $this->driftedClosure();

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('1 disagree with their rows')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(100.0, round((float) $closure->fresh()->cash_expenses_total, 2));
    }

    public function test_apply_corrects_the_closure(): void
    {
        $closure = $this->driftedClosure();

        $this->artisan('closures:recalculate --apply')
            ->expectsOutputToContain('Corrected 1 closures')
            ->assertSuccessful();

        $fresh = $closure->fresh();
        $this->assertSame(500.0, round((float) $fresh->cash_expenses_total, 2));
        $this->assertSame(500.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(0.0, round((float) $fresh->variance, 2));
        // The human observation is preserved.
        $this->assertSame(500.0, round((float) $fresh->counted_cash, 2));
    }

    public function test_a_clean_closure_is_left_alone(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'completed', 'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00', 'closed_at' => $this->date.' 12:00:00',
            'grand_total' => 1000,
        ]);
        DayClosure::create([
            'branch_id' => $this->branch->id, 'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id, 'closed_at' => $this->date.' 22:00:00',
            'opening_float' => 0, 'cash_sales_total' => 1000, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 0, 'expected_cash' => 1000,
            'counted_cash' => 1000, 'variance' => 0, 'order_count' => 1, 'expense_count' => 0,
        ]);

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('0 disagree with their rows')
            ->assertSuccessful();
    }
}
