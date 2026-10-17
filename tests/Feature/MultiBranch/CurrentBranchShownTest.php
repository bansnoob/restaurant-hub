<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The owner's own branch decides which menu the Menu tab edits and where their
 * POS sales go, and "Assign to me" changes it, but nothing on screen said which
 * branch that was. The sidebar now names it under the owner's name, and the Menu
 * tab names the branch whose menu it is editing.
 */
class CurrentBranchShownTest extends TestCase
{
    use RefreshDatabase;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);
    }

    private function ownerAt(?Branch $branch): User
    {
        $owner = User::factory()->create(['branch_id' => $branch?->id]);
        $owner->assignRole('owner');

        return $owner;
    }

    public function test_the_sidebar_names_the_owners_branch(): void
    {
        $this->actingAs($this->ownerAt($this->second))->get('/branches')
            ->assertOk()
            ->assertSee('Branch · Second Branch');
    }

    public function test_the_sidebar_says_when_the_owner_has_no_branch(): void
    {
        $this->actingAs($this->ownerAt(null))->get('/branches')
            ->assertOk()
            ->assertSee('No branch assigned');
    }

    public function test_the_menu_tab_names_the_branch_it_edits(): void
    {
        $this->actingAs($this->ownerAt($this->second))->get('/menu')
            ->assertOk()
            ->assertSee('Second Branch menu');
    }
}
