<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Payroll Rules drawer opened on whichever branch sorts first by name, so an
 * owner setting up the new branch's deductions could save them over the existing
 * branch's rules without noticing. It now opens on the branch being filtered, and
 * with no filter it asks (unless there is only one branch to ask about).
 */
class PayrollRulesDrawerBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function page(string $query = '')
    {
        return $this->actingAs($this->owner)->get('/payroll'.$query)->assertOk();
    }

    public function test_with_two_branches_and_no_filter_the_drawer_asks_for_a_branch(): void
    {
        Branch::factory()->create(['name' => 'Alpha Branch']);
        Branch::factory()->create(['name' => 'Beta Branch']);

        $this->page()->assertSee("initialBranchId: ''", false);
    }

    public function test_the_drawer_opens_on_the_filtered_branch(): void
    {
        Branch::factory()->create(['name' => 'Alpha Branch']);
        $beta = Branch::factory()->create(['name' => 'Beta Branch']);

        $this->page('?branch_id='.$beta->id)->assertSee("initialBranchId: '{$beta->id}'", false);
    }

    public function test_a_single_branch_is_preselected(): void
    {
        $only = Branch::factory()->create();

        $this->page()->assertSee("initialBranchId: '{$only->id}'", false);
    }
}
