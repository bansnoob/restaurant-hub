<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two ways a menu edit went wrong once there were two branches:
 *
 *  - An item's category was not checked against the item's branch. A Menu tab left
 *    open across an "Assign to me" switch could save a new-branch item under an
 *    old-branch category, and the POS (which lists items by category) then showed
 *    it on the old branch's iPad.
 *  - Names are unique per branch in the database (by slug), but the form did not
 *    say so, so re-typing a menu with a repeated name was a 500.
 */
class MenuBranchIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->second->id]);
        $this->owner->assignRole('owner');
    }

    private function categoryAt(Branch $branch, string $name = 'Rice Meals'): MenuCategory
    {
        return MenuCategory::factory()->create([
            'branch_id' => $branch->id,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    private function itemPayload(MenuCategory $category, string $name = 'Adobo'): array
    {
        return ['category_id' => $category->id, 'name' => $name, 'description' => null, 'base_price' => 120];
    }

    // ── Category must be on the item's branch ────────────────────────────

    public function test_a_new_item_cannot_go_under_another_branchs_category(): void
    {
        $this->actingAs($this->owner)
            ->post('/menu/items', $this->itemPayload($this->categoryAt($this->main)))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, MenuItem::count());
    }

    public function test_an_item_cannot_be_moved_under_another_branchs_category(): void
    {
        $item = MenuItem::factory()->create(['branch_id' => $this->second->id, 'category_id' => $this->categoryAt($this->second)->id]);
        $foreign = $this->categoryAt($this->main, 'Drinks');

        $this->actingAs($this->owner)
            ->put("/menu/items/{$item->id}", $this->itemPayload($foreign, $item->name) + ['is_active' => 1])
            ->assertSessionHasErrors('category_id');

        $this->assertNotSame($foreign->id, $item->fresh()->category_id);
    }

    public function test_an_item_under_its_own_branchs_category_saves(): void
    {
        $this->actingAs($this->owner)
            ->post('/menu/items', $this->itemPayload($this->categoryAt($this->second)))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('menu_items', ['branch_id' => $this->second->id, 'name' => 'Adobo']);
    }

    // ── Duplicate names are a message, not a 500 ─────────────────────────

    public function test_a_duplicate_category_name_is_refused_politely(): void
    {
        $this->categoryAt($this->second, 'Rice Meals');

        $this->actingAs($this->owner)->post('/menu/categories', ['name' => 'Rice meals'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, MenuCategory::where('branch_id', $this->second->id)->count());
    }

    public function test_the_refusal_is_shown_on_the_menu_page(): void
    {
        $this->categoryAt($this->second, 'Rice Meals');

        $this->actingAs($this->owner)->from('/menu')->followingRedirects()
            ->post('/menu/categories', ['name' => 'Rice Meals'])
            ->assertSee("This branch's menu already has a category called");
    }

    public function test_the_same_category_name_at_another_branch_is_fine(): void
    {
        $this->categoryAt($this->main, 'Rice Meals');

        $this->actingAs($this->owner)->post('/menu/categories', ['name' => 'Rice Meals'])
            ->assertSessionHasNoErrors();
    }

    public function test_renaming_a_category_onto_another_name_is_refused(): void
    {
        $this->categoryAt($this->second, 'Rice Meals');
        $drinks = $this->categoryAt($this->second, 'Drinks');

        $this->actingAs($this->owner)
            ->put("/menu/categories/{$drinks->id}", ['name' => 'Rice Meals', 'sort_order' => 1, 'is_active' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_saving_a_category_under_its_own_name_is_fine(): void
    {
        $drinks = $this->categoryAt($this->second, 'Drinks');

        $this->actingAs($this->owner)
            ->put("/menu/categories/{$drinks->id}", ['name' => 'Drinks', 'sort_order' => 3, 'is_active' => 1])
            ->assertSessionHasNoErrors();
    }

    public function test_a_duplicate_item_name_is_refused_politely(): void
    {
        $category = $this->categoryAt($this->second);
        MenuItem::factory()->create(['branch_id' => $this->second->id, 'category_id' => $category->id, 'name' => 'Adobo', 'slug' => 'adobo']);

        $this->actingAs($this->owner)->post('/menu/items', $this->itemPayload($category, 'adobo'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, MenuItem::count());
    }

    public function test_renaming_an_item_onto_another_name_is_refused(): void
    {
        $category = $this->categoryAt($this->second);
        MenuItem::factory()->create(['branch_id' => $this->second->id, 'category_id' => $category->id, 'name' => 'Adobo', 'slug' => 'adobo']);
        $sinigang = MenuItem::factory()->create(['branch_id' => $this->second->id, 'category_id' => $category->id, 'name' => 'Sinigang', 'slug' => 'sinigang']);

        $this->actingAs($this->owner)
            ->put("/menu/items/{$sinigang->id}", $this->itemPayload($category, 'Adobo') + ['is_active' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_an_owner_with_no_branch_cannot_add_to_a_menu(): void
    {
        $this->owner->update(['branch_id' => null]);

        $this->actingAs($this->owner)->post('/menu/categories', ['name' => 'Rice Meals'])->assertForbidden();
    }
}
