<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuTest extends TestCase
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
        $this->user = User::factory()->create();
        $this->user->assignRole('cashier');
        Employee::factory()->create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_categories_returns_branch_scoped_active_categories(): void
    {
        $category = MenuCategory::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        // Category from another branch should not appear
        MenuCategory::factory()->create(['is_active' => true]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/menu/categories');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'sort_order', 'is_active', 'items']],
            ]);
    }

    public function test_categories_excludes_inactive(): void
    {
        MenuCategory::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/menu/categories');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_items_returns_branch_scoped_active_items(): void
    {
        MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/menu/items');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'sku', 'name', 'base_price', 'tax_rate', 'is_active']],
            ]);
    }

    public function test_items_filterable_by_category(): void
    {
        $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
        MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);
        MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => null,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/menu/items?category_id='.$category->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/menu/categories');

        $response->assertUnauthorized();
    }
}
