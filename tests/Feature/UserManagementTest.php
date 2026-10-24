<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Users page: the super admin manages every account. Branch admins, cashiers
 * and other super admins can be created, edited, given a new password and
 * deactivated. Deactivating blocks sign-in on the website and the iPad, and signs
 * the account out wherever it already is, while its history stays.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }
        $this->main = Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);
        $this->superAdmin = User::factory()->create(['name' => 'Bans', 'branch_id' => $this->main->id]);
        $this->superAdmin->assignRole('owner');
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->superAdmin)->post('/users', array_merge([
            'name' => 'Ana Admin',
            'email' => 'ana@example.com',
            'role' => 'admin',
            'branch_id' => $this->second->id,
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ], $overrides));
    }

    // ── Who can open it ──────────────────────────────────────────────────

    public function test_the_super_admin_sees_every_user_with_their_role_and_branch(): void
    {
        $this->userWithRole('admin', ['name' => 'Ana Admin', 'branch_id' => $this->second->id]);
        $cashier = $this->userWithRole('cashier', ['name' => 'Carl Cashier', 'branch_id' => null]);
        Employee::factory()->create(['user_id' => $cashier->id, 'branch_id' => $this->main->id]);

        $this->actingAs($this->superAdmin)->get('/users')->assertOk()
            ->assertSee('Bans')->assertSee('Super Admin')
            ->assertSee('Ana Admin')->assertSee('Admin')->assertSee('Second Branch')
            ->assertSee('Carl Cashier')->assertSee('Cashier');
    }

    public function test_nobody_else_can_open_it(): void
    {
        $admin = $this->userWithRole('admin', ['branch_id' => $this->main->id]);

        $this->actingAs($admin)->get('/users')->assertForbidden();
        $this->actingAs($admin)->post('/users', ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->userWithRole('cashier', ['branch_id' => $this->main->id]))->get('/users')->assertRedirect(url('/pos/'));
    }

    // ── Creating ─────────────────────────────────────────────────────────

    public function test_the_super_admin_creates_a_branch_admin(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame($this->second->id, $user->branch_id);
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
        $this->assertTrue($user->is_active);
    }

    public function test_a_branch_admin_or_cashier_needs_a_branch(): void
    {
        $this->create(['branch_id' => ''])->assertSessionHasErrors('branch_id');
        $this->create(['role' => 'cashier', 'branch_id' => ''])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_a_super_admin_needs_no_branch(): void
    {
        $this->create(['role' => 'owner', 'branch_id' => ''])->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'ana@example.com')->firstOrFail()->isSuperAdmin());
    }

    public function test_the_email_must_be_unused_and_the_role_known(): void
    {
        $this->create(['email' => $this->superAdmin->email])->assertSessionHasErrors('email');
        $this->create(['role' => 'emperor'])->assertSessionHasErrors('role');
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_the_super_admin_changes_a_users_details_role_and_branch(): void
    {
        $user = $this->userWithRole('cashier', ['branch_id' => $this->main->id]);

        $this->actingAs($this->superAdmin)->put("/users/{$user->id}", [
            'name' => 'Promoted', 'email' => 'promoted@example.com', 'role' => 'admin', 'branch_id' => $this->second->id, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Promoted', $user->name);
        $this->assertSame('promoted@example.com', $user->email);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('cashier'));
        $this->assertSame($this->second->id, $user->branch_id);
    }

    public function test_the_super_admin_sets_a_new_password(): void
    {
        $user = $this->userWithRole('admin', ['branch_id' => $this->main->id]);

        $this->actingAs($this->superAdmin)->put("/users/{$user->id}/password", [
            'password' => 'new-secret-456', 'password_confirmation' => 'new-secret-456',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-456', $user->fresh()->password));
    }

    // ── Deactivating ─────────────────────────────────────────────────────

    private function deactivate(User $user)
    {
        return $this->actingAs($this->superAdmin)->put("/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'branch_id' => $this->main->id, 'is_active' => '0',
        ]);
    }

    public function test_a_deactivated_account_cannot_sign_in_on_the_website(): void
    {
        $user = $this->userWithRole('admin', ['branch_id' => $this->main->id]);
        $this->deactivate($user)->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account has been deactivated.']);
        $this->assertGuest();
    }

    public function test_a_deactivated_account_is_signed_out_where_it_already_is(): void
    {
        $user = $this->userWithRole('admin', ['branch_id' => $this->main->id]);
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/sales')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_account_loses_the_ipad(): void
    {
        $user = $this->userWithRole('cashier', ['branch_id' => $this->main->id]);
        $user->createToken('ipad');
        $this->deactivate($user);

        $this->assertSame(0, $user->tokens()->count(), 'its iPad sign-in was not revoked');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'ipad'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reactivating_lets_them_back_in(): void
    {
        $user = $this->userWithRole('admin', ['branch_id' => $this->main->id, 'is_active' => false]);

        $this->actingAs($this->superAdmin)->put("/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'branch_id' => $this->main->id, 'is_active' => '1',
        ]);
        auth()->logout();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    // ── Guards ───────────────────────────────────────────────────────────

    public function test_you_cannot_deactivate_or_demote_yourself(): void
    {
        $this->actingAs($this->superAdmin)->put("/users/{$this->superAdmin->id}", [
            'name' => 'Bans', 'email' => $this->superAdmin->email, 'role' => 'owner', 'branch_id' => '', 'is_active' => '0',
        ])->assertSessionHas('error');
        $this->actingAs($this->superAdmin)->put("/users/{$this->superAdmin->id}", [
            'name' => 'Bans', 'email' => $this->superAdmin->email, 'role' => 'admin', 'branch_id' => $this->main->id, 'is_active' => '1',
        ])->assertSessionHas('error');

        $this->assertTrue($this->superAdmin->fresh()->isSuperAdmin());
        $this->assertTrue($this->superAdmin->fresh()->is_active);
    }

    public function test_another_super_admin_can_be_demoted_or_deactivated(): void
    {
        // Always leaves at least one active super admin: the one making the change,
        // who cannot demote or deactivate themselves.
        $other = $this->userWithRole('owner');

        $this->actingAs($this->superAdmin)->put("/users/{$other->id}", [
            'name' => $other->name, 'email' => $other->email, 'role' => 'admin', 'branch_id' => $this->main->id, 'is_active' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($other->fresh()->isSuperAdmin());
        $this->assertFalse($other->fresh()->is_active);
    }
}
