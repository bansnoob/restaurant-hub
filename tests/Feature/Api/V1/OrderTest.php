<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create();
        $this->user->assignRole('cashier');
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_cashier_can_create_order(): void
    {
        $menuItem = MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'base_price' => 100.00,
            'tax_rate' => 12.00,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'order_type' => 'dine_in',
            'table_label' => 'T1',
            'items' => [
                ['menu_item_id' => $menuItem->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id', 'order_number', 'order_type', 'status',
                    'grand_total',
                    'items',
                ],
            ]);

        $this->assertDatabaseHas('sales', [
            'branch_id' => $this->branch->id,
            'status' => 'open',
            'order_type' => 'dine_in',
        ]);
    }

    public function test_order_requires_at_least_one_item(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'order_type' => 'dine_in',
            'items' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_cashier_can_view_order(): void
    {
        $sale = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/orders/'.$sale->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $sale->id);
    }

    public function test_cashier_can_pay_order(): void
    {
        $sale = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'open',
            'grand_total' => 224.00,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders/'.$sale->id.'/pay', [
            'payment_method' => 'cash',
            'paid_total' => 250.00,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method', 'cash');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'completed',
        ]);
    }

    public function test_cannot_pay_already_completed_order(): void
    {
        $sale = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders/'.$sale->id.'/pay', [
            'payment_method' => 'cash',
            'paid_total' => 100.00,
        ]);

        $response->assertServerError();
    }

    public function test_cashier_can_void_order(): void
    {
        $sale = Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders/'.$sale->id.'/void', [
            'reason' => 'Customer changed mind',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }

    public function test_cashier_cannot_access_other_branch_orders(): void
    {
        $otherBranch = Branch::factory()->create();
        $sale = Sale::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/orders/'.$sale->id);

        $response->assertForbidden();
    }

    public function test_owner_can_access_any_branch_order(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $otherBranch = Branch::factory()->create();
        $sale = Sale::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/orders/'.$sale->id);

        $response->assertOk();
    }

    public function test_index_returns_todays_orders(): void
    {
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'sale_datetime' => now(),
        ]);
        Sale::factory()->create([
            'branch_id' => $this->branch->id,
            'sale_datetime' => now()->subDays(5),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
