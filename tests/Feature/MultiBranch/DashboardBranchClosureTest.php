<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Today is closed" is a per-branch fact. The dashboard used to take the newest
 * closure from any branch, so the first branch to close hid the Close Day button
 * for every other branch.
 */
class DashboardBranchClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday: before 5am the dashboard reports last night, and these tests close today.
        $this->travelTo('2026-10-14 12:00:00');
        Role::findOrCreate('owner');

        $this->main = Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function close(Branch $branch, float $variance = 0): DayClosure
    {
        $today = now()->toDateString();

        return DayClosure::create([
            'branch_id' => $branch->id,
            'closed_at_date' => $today,
            'closed_by_user_id' => $this->owner->id,
            'closed_at' => $today.' 21:00:00',
            'opening_float' => 0,
            'cash_sales_total' => 100,
            'mixed_cash_total' => 0,
            'gcash_sales_total' => 0,
            'cash_expenses_total' => 0,
            'expected_cash' => 100,
            'counted_cash' => 100 + $variance,
            'variance' => $variance,
            'order_count' => 1,
            'expense_count' => 0,
        ]);
    }

    /** @return array<int, array{branch_id: int, closure: ?DayClosure}> */
    private function statusRows(): array
    {
        $rows = $this->actingAs($this->owner)->get('/dashboard')->assertOk()->viewData('branchClosures');

        return collect($rows)->keyBy('branch_id')->all();
    }

    public function test_one_branch_closing_leaves_the_other_open(): void
    {
        $this->close($this->main);

        $rows = $this->statusRows();

        $this->assertNotNull($rows[$this->main->id]['closure']);
        $this->assertNull($rows[$this->second->id]['closure'], 'the second branch was reported closed');

        $this->actingAs($this->owner)->get('/dashboard')
            ->assertSee('Close Day Now')
            ->assertSee('Day closed at');
    }

    public function test_the_open_branch_button_opens_that_branch(): void
    {
        $this->close($this->main);

        $this->actingAs($this->owner)->get('/dashboard')
            ->assertSee('openDrawer({ branchId: '.$this->second->id.' })', false)
            ->assertDontSee('openDrawer({ branchId: '.$this->main->id.' })', false);
    }

    public function test_every_branch_closed_hides_close_day(): void
    {
        $this->close($this->main);
        $this->close($this->second, -20);

        $this->actingAs($this->owner)->get('/dashboard')
            ->assertDontSee('Close Day Now')
            ->assertSee('Closed · Short ₱20');
    }

    public function test_a_single_branch_keeps_its_badge(): void
    {
        $this->second->update(['is_active' => false]);
        $this->close($this->main);

        $this->actingAs($this->owner)->get('/dashboard')
            ->assertSee('Closed · Match')
            ->assertDontSee('Close Day Now');
    }

    public function test_an_inactive_branch_without_a_closure_is_not_listed(): void
    {
        $this->second->update(['is_active' => false]);

        $rows = $this->statusRows();

        $this->assertArrayHasKey($this->main->id, $rows);
        $this->assertArrayNotHasKey($this->second->id, $rows);
    }
}
