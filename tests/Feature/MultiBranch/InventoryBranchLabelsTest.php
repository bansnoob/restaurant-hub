<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two single-branch assumptions on the Inventory tab:
 *
 *  - A saved count's "days since previous" compared against the last count at ANY
 *    branch, so the new branch's first counts read as a few days after the old
 *    branch's.
 *  - Under All branches, each branch's own categories are separate sections with
 *    identical headers ("Dry Goods", "Dry Goods"). They now carry the branch name;
 *    shared categories and a single-branch view are unchanged.
 */
class InventoryBranchLabelsTest extends TestCase
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
    }

    private function countAt(Branch $branch, string $date): StockCount
    {
        return StockCount::factory()->create([
            'branch_id' => $branch->id,
            'counted_at' => $date,
            'recorded_by_user_id' => $this->owner->id,
        ]);
    }

    public function test_days_since_previous_counts_only_the_same_branch(): void
    {
        $this->countAt($this->main, '2026-10-01');
        $this->countAt($this->second, '2026-10-05');
        $latest = $this->countAt($this->main, '2026-10-10');

        $this->actingAs($this->owner)->getJson("/inventory/counts/{$latest->id}/details")
            ->assertOk()
            ->assertJsonPath('count.days_since_previous', 9);
    }

    public function test_a_branchs_first_count_has_no_previous(): void
    {
        $this->countAt($this->main, '2026-10-01');
        $first = $this->countAt($this->second, '2026-10-05');

        $this->actingAs($this->owner)->getJson("/inventory/counts/{$first->id}/details")
            ->assertJsonPath('count.days_since_previous', null);
    }

    private function dryGoodsAt(Branch $branch): void
    {
        $category = IngredientCategory::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Dry Goods',
            'slug' => 'dry-goods',
        ]);
        Ingredient::factory()->create(['branch_id' => $branch->id, 'ingredient_category_id' => $category->id]);
    }

    /** @return list<string> */
    private function groupNames(string $url): array
    {
        return array_column($this->actingAs($this->owner)->get($url)->assertOk()->viewData('groups'), 'name');
    }

    public function test_all_branches_names_the_branch_on_each_branchs_own_section(): void
    {
        $this->dryGoodsAt($this->main);
        $this->dryGoodsAt($this->second);

        $names = $this->groupNames('/inventory');

        $this->assertContains('Dry Goods · Main Branch', $names);
        $this->assertContains('Dry Goods · Second Branch', $names);
    }

    public function test_one_branch_picked_keeps_plain_section_names(): void
    {
        $this->dryGoodsAt($this->main);
        $this->dryGoodsAt($this->second);

        $this->assertSame(['Dry Goods'], $this->groupNames('/inventory?branch_id='.$this->second->id));
    }

    public function test_a_shared_category_is_not_labelled_with_one_branch(): void
    {
        $shared = IngredientCategory::factory()->create(['branch_id' => null, 'name' => 'Cleaning', 'slug' => 'cleaning']);
        Ingredient::factory()->create(['branch_id' => $this->main->id, 'ingredient_category_id' => $shared->id]);
        Ingredient::factory()->create(['branch_id' => $this->second->id, 'ingredient_category_id' => $shared->id]);

        $this->assertContains('Cleaning', $this->groupNames('/inventory'));
    }
}
