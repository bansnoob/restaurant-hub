<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dashboard adds up every branch's money, so it is the owner's page only.
 * A cashier works the POS; anyone else has no business seeing it.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');
        Branch::factory()->create();
    }

    public function test_the_owner_sees_the_dashboard(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_a_cashier_is_sent_to_the_pos(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get('/dashboard')->assertRedirect(url('/pos/'));
    }

    public function test_a_cashier_logging_in_lands_on_the_pos(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->post('/login', ['email' => $cashier->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->get('/dashboard')->assertRedirect(url('/pos/'));
    }

    public function test_an_account_without_a_role_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
