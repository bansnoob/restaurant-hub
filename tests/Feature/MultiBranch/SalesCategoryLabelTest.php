<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Under "All branches" the Sales category filter lists every branch's categories,
 * and two branches' "Rice Meals" looked identical, though picking one shows only
 * that branch's slice. Each is now labelled with its branch while no branch is
 * picked; with a branch picked the list is that branch's alone and needs no label.
 */
class SalesCategoryLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->main = Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);
        MenuCategory::factory()->create(['branch_id' => $this->main->id, 'name' => 'Rice Meals']);
        MenuCategory::factory()->create(['branch_id' => $this->second->id, 'name' => 'Rice Meals']);
    }

    public function test_all_branches_labels_each_category_with_its_branch(): void
    {
        $this->actingAs($this->owner)->get('/sales')->assertOk()
            ->assertSee('Rice Meals · Main Branch')
            ->assertSee('Rice Meals · Second Branch');
    }

    public function test_one_branch_picked_needs_no_label(): void
    {
        $this->actingAs($this->owner)->get('/sales?branch_id='.$this->second->id)->assertOk()
            ->assertDontSee('Rice Meals · Second Branch')
            ->assertDontSee('Rice Meals · Main Branch');
    }
}
