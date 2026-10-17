<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An order is booked to the caller's branch, but the iPad sends whatever menu it
 * last cached. Without a branch check, a stale menu rang the old branch's items
 * and prices into the new branch's sales.
 */
class OrderBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
    }

    private function cashierAt(Branch $branch): User
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        Employee::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);

        return $user;
    }

    private function itemAt(Branch $branch): MenuItem
    {
        return MenuItem::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
    }

    public function test_an_order_with_another_branchs_item_is_refused(): void
    {
        Sanctum::actingAs($this->cashierAt($this->second));

        $this->postJson('/api/v1/orders', [
            'items' => [['menu_item_id' => $this->itemAt($this->main)->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.menu_item_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_an_order_with_its_own_branchs_items_is_booked(): void
    {
        Sanctum::actingAs($this->cashierAt($this->second));

        $this->postJson('/api/v1/orders', [
            'items' => [['menu_item_id' => $this->itemAt($this->second)->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('sales', ['branch_id' => $this->second->id]);
    }

    public function test_the_owner_ordering_for_a_branch_must_use_that_branchs_menu(): void
    {
        $owner = User::factory()->create(['branch_id' => $this->main->id]);
        $owner->assignRole('owner');
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/orders', [
            'branch_id' => $this->second->id,
            'items' => [['menu_item_id' => $this->itemAt($this->main)->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/orders', [
            'branch_id' => $this->second->id,
            'items' => [['menu_item_id' => $this->itemAt($this->second)->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_a_cashier_cannot_book_an_order_to_another_branch(): void
    {
        Sanctum::actingAs($this->cashierAt($this->second));

        $this->postJson('/api/v1/orders', [
            'branch_id' => $this->main->id,
            'items' => [['menu_item_id' => $this->itemAt($this->main)->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
    }
}
