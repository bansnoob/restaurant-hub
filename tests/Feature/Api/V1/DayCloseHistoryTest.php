<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression cover for the mobile app's day-close endpoints, which had none. The
 * payload shape here is a published contract — the app reads `data`, and the keys
 * below are what it binds to.
 */
class DayCloseHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->assignRole('cashier');
    }

    private function closure(string $date, array $attributes = []): DayClosure
    {
        return DayClosure::create(array_merge([
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'closed_by_user_id' => $this->user->id,
            'closed_at' => $date.' 22:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 100,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 100,
            'counted_cash' => 100,
            'variance' => 0,
            'order_count' => 1,
            'expense_count' => 0,
        ], $attributes));
    }

    public function test_history_returns_closures_newest_first(): void
    {
        $this->closure(now()->subDays(3)->toDateString(), ['counted_cash' => 300]);
        $this->closure(now()->subDay()->toDateString(), ['counted_cash' => 100]);

        Sanctum::actingAs($this->user);

        $payload = $this->getJson('/api/v1/day-close/history?branch_id='.$this->branch->id)
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['data']);
        $this->assertSame(
            now()->subDay()->toDateString(),
            $payload['data'][0]['closed_at_date']
        );
        $this->assertSame(100.0, (float) $payload['data'][0]['counted_cash']);
    }

    public function test_history_is_scoped_to_the_requested_branch(): void
    {
        $other = Branch::factory()->create();
        $this->closure(now()->subDay()->toDateString());
        DayClosure::create([
            'branch_id' => $other->id,
            'closed_at_date' => now()->subDay()->toDateString(),
            'closed_by_user_id' => $this->user->id,
            'closed_at' => now()->subDay()->toDateString().' 22:00:00',
            'opening_float' => 0, 'cash_sales_total' => 9, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 0, 'expected_cash' => 9,
            'counted_cash' => 9, 'variance' => 0, 'order_count' => 1, 'expense_count' => 0,
        ]);

        Sanctum::actingAs($this->user);

        $payload = $this->getJson('/api/v1/day-close/history?branch_id='.$this->branch->id)
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['data']);
        // The other branch's 9.00 closure must not leak into this branch's history.
        $this->assertSame(100.0, (float) $payload['data'][0]['counted_cash']);
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/v1/day-close/history')->assertUnauthorized();
    }

    /** The mobile client can close a past day, same as the web drawer now can. */
    public function test_a_past_day_can_be_closed_through_the_api(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/day-close', [
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->subDays(3)->toDateString(),
            'counted_cash' => 250,
        ])->assertCreated();

        $this->assertSame(1, DayClosure::where('branch_id', $this->branch->id)->count());
    }

    /** A day that has not happened yet has no cash to count. */
    public function test_the_api_rejects_a_future_date(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/day-close', [
            'branch_id' => $this->branch->id,
            'closed_at_date' => now()->addDay()->toDateString(),
            'counted_cash' => 250,
        ])->assertStatus(422);

        $this->assertSame(0, DayClosure::count());
    }
}
