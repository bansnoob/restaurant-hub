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
 * The Menu tab used to edit only the owner's OWN branch's menu. Editing another
 * branch's meant Branches → "Use this branch", which also moved the owner's POS
 * sales, sidebar and Close Day default until they switched back.
 *
 * The tab now has a branch picker like every other tab. It defaults to the owner's
 * own branch, and choosing another never changes the owner's own branch.
 */
class MenuBranchPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->main = Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);
        $this->owner = User::factory()->create(['branch_id' => $this->main->id]);
        $this->owner->assignRole('owner');
    }

    private function categoryAt(Branch $branch, string $name): MenuCategory
    {
        return MenuCategory::factory()->create(['branch_id' => $branch->id, 'name' => $name, 'slug' => Str::slug($name)]);
    }

    // ── Viewing ──────────────────────────────────────────────────────────

    public function test_it_opens_on_the_owners_own_branch(): void
    {
        $this->categoryAt($this->main, 'Burgers');
        $this->categoryAt($this->second, 'Ramen');

        $response = $this->actingAs($this->owner)->get('/menu')->assertOk();

        $this->assertSame($this->main->id, $response->viewData('branchId'));
        $response->assertSee('Burgers')->assertDontSee('Ramen');
    }

    public function test_picking_a_branch_shows_that_branchs_menu(): void
    {
        $this->categoryAt($this->main, 'Burgers');
        $this->categoryAt($this->second, 'Ramen');

        $this->actingAs($this->owner)->get('/menu?branch_id='.$this->second->id)
            ->assertOk()
            ->assertSee('Second Branch menu')
            ->assertSee('Ramen')
            ->assertDontSee('Burgers');
    }

    public function test_picking_a_branch_never_changes_the_owners_own_branch(): void
    {
        $this->actingAs($this->owner)->get('/menu?branch_id='.$this->second->id)->assertOk();

        $this->assertSame($this->main->id, $this->owner->fresh()->branch_id);
    }

    public function test_the_picker_lists_the_active_branches_with_the_current_one_selected(): void
    {
        $this->actingAs($this->owner)->get('/menu?branch_id='.$this->second->id)
            ->assertOk()
            ->assertSee('<option value="'.$this->main->id.'" >', false)
            ->assertSee('<option value="'.$this->second->id.'" selected>', false);
    }

    public function test_an_owner_with_no_branch_of_their_own_can_still_use_the_menu(): void
    {
        $this->owner->update(['branch_id' => null]);

        $response = $this->actingAs($this->owner)->get('/menu')->assertOk();

        // First active branch by name.
        $this->assertSame($this->main->id, $response->viewData('branchId'));
    }

    public function test_an_unknown_branch_is_a_404(): void
    {
        $this->actingAs($this->owner)->get('/menu?branch_id=999')->assertNotFound();
    }

    public function test_category_links_keep_the_picked_branch(): void
    {
        $ramen = $this->categoryAt($this->second, 'Ramen');

        $this->actingAs($this->owner)->get('/menu?branch_id='.$this->second->id)
            ->assertSee(route('menu.index', ['branch_id' => $this->second->id, 'category' => $ramen->id]));
    }

    // ── Adding to the picked branch ──────────────────────────────────────

    public function test_a_new_category_goes_to_the_picked_branch_and_stays_there(): void
    {
        $this->actingAs($this->owner)
            ->post('/menu/categories', ['branch_id' => $this->second->id, 'name' => 'Ramen'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id]));

        $this->assertDatabaseHas('menu_categories', ['branch_id' => $this->second->id, 'name' => 'Ramen']);
        $this->assertDatabaseMissing('menu_categories', ['branch_id' => $this->main->id, 'name' => 'Ramen']);
    }

    public function test_a_new_item_goes_to_the_picked_branch(): void
    {
        $ramen = $this->categoryAt($this->second, 'Ramen');

        $this->actingAs($this->owner)
            ->post('/menu/items', ['branch_id' => $this->second->id, 'category_id' => $ramen->id, 'name' => 'Shoyu', 'base_price' => 250])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id, 'category' => $ramen->id]));

        $this->assertDatabaseHas('menu_items', ['branch_id' => $this->second->id, 'name' => 'Shoyu']);
    }

    public function test_a_new_item_must_use_the_picked_branchs_category(): void
    {
        $burgers = $this->categoryAt($this->main, 'Burgers');

        $this->actingAs($this->owner)
            ->post('/menu/items', ['branch_id' => $this->second->id, 'category_id' => $burgers->id, 'name' => 'Shoyu', 'base_price' => 250])
            ->assertSessionHasErrors('category_id');
    }

    public function test_a_page_without_the_picker_still_adds_to_the_owners_own_branch(): void
    {
        // A tab left open from before the picker shipped posts no branch_id.
        $this->actingAs($this->owner)->post('/menu/categories', ['name' => 'Desserts'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('menu_categories', ['branch_id' => $this->main->id, 'name' => 'Desserts']);
    }

    // ── Editing stays on the record's branch ─────────────────────────────

    public function test_editing_and_deleting_return_to_the_records_branch(): void
    {
        $ramen = $this->categoryAt($this->second, 'Ramen');
        $item = MenuItem::factory()->create(['branch_id' => $this->second->id, 'category_id' => $ramen->id, 'name' => 'Shoyu', 'slug' => 'shoyu']);

        $this->actingAs($this->owner)
            ->put("/menu/categories/{$ramen->id}", ['name' => 'Ramen Bowls', 'sort_order' => 1, 'is_active' => 1])
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id, 'category' => $ramen->id]));

        $this->actingAs($this->owner)
            ->put("/menu/items/{$item->id}", ['category_id' => $ramen->id, 'name' => 'Shoyu', 'base_price' => 260, 'is_active' => 1])
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id, 'category' => $ramen->id]));

        $this->actingAs($this->owner)
            ->delete("/menu/items/{$item->id}")
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id, 'category' => $ramen->id]));

        $this->actingAs($this->owner)
            ->delete("/menu/categories/{$ramen->id}")
            ->assertRedirect(route('menu.index', ['branch_id' => $this->second->id]));
    }
}
