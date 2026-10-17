<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The iPad's Close Day kept its own copy of the day's arithmetic and counted a cash
 * expense paid from OUTSIDE the drawer as drawer cash. Every day closed on the iPad
 * stored an expected cash that was short by the outside-paid amount, and it then
 * changed silently the next time any expense on that day was edited (the shared
 * recalculator only counts drawer-paid cash).
 */
class DayCloseTotalsParityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->today = now()->toDateString();

        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'grand_total' => 1000,
            'sale_datetime' => $this->today.' 12:00:00',
        ]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'amount' => 100, 'expense_date' => $this->today]);
        Expense::factory()->outside()->create(['branch_id' => $this->branch->id, 'amount' => 400, 'expense_date' => $this->today]);
    }

    public function test_the_ipad_preview_leaves_out_outside_paid_cash(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/day-close/preview')
            ->assertOk()
            ->assertJsonPath('totals.cash_expenses_total', 100)
            ->assertJsonPath('totals.expected_cash', 900);
    }

    public function test_the_ipad_and_the_website_agree_on_the_day(): void
    {
        Sanctum::actingAs($this->owner);
        $api = $this->getJson('/api/v1/day-close/preview')->json('totals');

        $web = $this->actingAs($this->owner)->getJson('/day-close/preview')->json('totals');

        $this->assertSame($web, $api);
    }

    public function test_an_ipad_closure_does_not_move_when_an_expense_is_edited(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/day-close', ['closed_at_date' => $this->today, 'counted_cash' => 900])->assertCreated();

        $closure = DayClosure::firstOrFail();
        $this->assertEquals(900, (float) $closure->expected_cash);
        $this->assertEquals(0, (float) $closure->variance);

        // Editing an unrelated field triggers the shared recalculator.
        $expense = Expense::where('paid_from', 'drawer')->firstOrFail();
        $this->putJson("/api/v1/expenses/{$expense->id}", ['description' => 'Ice, corrected'])->assertOk();

        $this->assertEquals(900, (float) $closure->fresh()->expected_cash);
        $this->assertEquals(0, (float) $closure->fresh()->variance);
    }
}
