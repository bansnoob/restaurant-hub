<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Backdating an expense into a day that is already closed is legitimate — a forgotten
 * receipt is a real correction, and the recalculator keeps the closure in step. What was
 * wrong is that it happened silently: the form had no way to ask whether the date it was
 * pointed at had been signed off, and the page said "Expense recorded successfully" while
 * a day someone had already counted and locked quietly changed underneath them.
 *
 * So: still allowed, never silent.
 */
class ExpenseClosedDayWarningTest extends TestCase
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

    private function closure(string $date): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $date.' 21:30:00',
            'opening_float' => 1000,
            'cash_sales_total' => 0,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 1000,
            'counted_cash' => 1000,
            'variance' => 0,
            'order_count' => 0,
            'expense_count' => 0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-10',
            'description' => 'Cooking oil',
            'amount' => 220,
            'payment_method' => 'cash',
            'paid_from' => 'drawer',
        ], $overrides);
    }

    // ---- the form can ask ---------------------------------------------------

    public function test_day_status_reports_a_closed_day(): void
    {
        $this->closure('2026-09-10');

        $response = $this->actingAs($this->owner)
            ->getJson(route('expenses.day-status', [
                'branch_id' => $this->branch->id,
                'date' => '2026-09-10',
            ]))
            ->assertOk()
            ->assertJsonPath('closed', true);

        $this->assertEqualsWithDelta(1000.0, $response->json('counted_cash'), 0.001);
    }

    public function test_day_status_reports_an_open_day(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('expenses.day-status', [
                'branch_id' => $this->branch->id,
                'date' => '2026-09-10',
            ]))
            ->assertOk()
            ->assertJsonPath('closed', false);
    }

    public function test_day_status_is_scoped_to_the_branch(): void
    {
        $this->closure('2026-09-10');
        $other = Branch::factory()->create();

        $this->actingAs($this->owner)
            ->getJson(route('expenses.day-status', ['branch_id' => $other->id, 'date' => '2026-09-10']))
            ->assertOk()
            ->assertJsonPath('closed', false);
    }

    public function test_day_status_validates_its_input(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('expenses.day-status', ['branch_id' => $this->branch->id, 'date' => 'not-a-date']))
            ->assertStatus(422);
    }

    public function test_day_status_is_owner_only(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)
            ->getJson(route('expenses.day-status', ['branch_id' => $this->branch->id, 'date' => '2026-09-10']))
            ->assertForbidden();
    }

    // ---- the write still goes through, and says so --------------------------

    public function test_storing_into_a_closed_day_still_works_but_warns(): void
    {
        $closure = $this->closure('2026-09-10');

        $this->actingAs($this->owner)
            ->from(route('expenses.index'))
            ->post(route('expenses.store'), $this->payload())
            ->assertRedirect(route('expenses.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('expenses', 1);
        $this->assertEquals(780.0, (float) $closure->fresh()->expected_cash);
    }

    public function test_storing_into_an_open_day_does_not_warn(): void
    {
        $this->actingAs($this->owner)
            ->from(route('expenses.index'))
            ->post(route('expenses.store'), $this->payload())
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');
    }

    public function test_editing_an_expense_off_a_closed_day_warns_about_both_days(): void
    {
        $this->closure('2026-09-10');
        $this->closure('2026-09-11');

        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());
        $expense = Expense::latest('id')->first();

        $this->actingAs($this->owner)
            ->from(route('expenses.index'))
            ->put(route('expenses.update', $expense), $this->payload(['expense_date' => '2026-09-11']))
            ->assertSessionHas('warning');

        $warning = session('warning');
        $this->assertStringContainsString('2026-09-10', $warning);
        $this->assertStringContainsString('2026-09-11', $warning);
    }

    public function test_deleting_from_a_closed_day_warns(): void
    {
        $this->closure('2026-09-10');

        $this->actingAs($this->owner)->post(route('expenses.store'), $this->payload());
        $expense = Expense::latest('id')->first();

        $this->actingAs($this->owner)
            ->from(route('expenses.index'))
            ->delete(route('expenses.destroy', $expense))
            ->assertSessionHas('warning');
    }

    // ---- the page renders the warning ---------------------------------------

    public function test_the_page_renders_a_closed_day_notice_bound_inside_the_form(): void
    {
        $html = $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk()->getContent();

        $this->assertStringContainsString('closedDay', $html, 'the form has no closed-day state');
        $this->assertStringContainsString('day-status', $html, 'the form cannot ask the server');
        $this->assertStringContainsString('refreshClosedDay', $html, 'nothing refreshes the closed-day state');
    }
}
