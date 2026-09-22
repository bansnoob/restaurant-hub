<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settles: "NO day the report will ever surface for this class of defect."
 *
 * The class is "a cash expense entered against an already-closed day". The web
 * ExpenseController recalculates, so that door is silent. The API door does not.
 */
class ZzAuditBlindnessRefutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_expense_on_a_closed_day_drifts_the_closure_and_the_report_surfaces_it(): void
    {
        Role::findOrCreate('owner');
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('owner');
        $date = now()->subDay()->toDateString();

        // A day closed with no expenses at all: stored figures agree with its rows.
        $closure = DayClosure::create([
            'branch_id' => $branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $user->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0,
            'order_count' => 0, 'expense_count' => 0,
        ]);

        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('0 disagree with their rows')
            ->assertSuccessful();

        // Now enter a backdated cash expense through the API door.
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/expenses', [
            'branch_id' => $branch->id,
            'expense_date' => $date,
            'description' => 'Weekly salary, entered late',
            'amount' => 2520,
            'payment_method' => 'cash',
        ])->assertCreated();

        $fresh = $closure->fresh();
        // The closure was NOT refreshed: stored expenses still 0.
        $this->assertSame(0.0, round((float) $fresh->cash_expenses_total, 2));

        // ...and the audit DOES surface it.
        $this->artisan('closures:recalculate')
            ->expectsOutputToContain('1 disagree with their rows')
            ->assertSuccessful();
    }
}
