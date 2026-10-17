<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The owner gives an employee a cashier login from the Employees page. The login
 * carries no branch of its own: it follows the employee, so moving the employee to
 * another branch moves the login with them.
 */
class EmployeeLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->main->id]);
        $this->owner->assignRole('owner');
    }

    private function createLogin(Employee $employee, array $overrides = [])
    {
        return $this->actingAs($this->owner)->post("/employees/{$employee->id}/login", array_merge([
            'email' => 'ana@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ], $overrides));
    }

    public function test_the_owner_creates_a_cashier_login_for_an_employee(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->second->id,
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
        ]);

        $this->createLogin($employee)->assertRedirect()->assertSessionHas('success');

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertFalse($user->hasRole('owner'));
        $this->assertSame('Ana Reyes', $user->name);
        $this->assertNull($user->branch_id, 'the login must follow the employee, not carry its own branch');
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame($this->second->id, $user->fresh()->resolveBranchId());
    }

    public function test_moving_the_employee_moves_the_login(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);
        $this->createLogin($employee);

        $employee->update(['branch_id' => $this->main->id]);

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame($this->main->id, $user->resolveBranchId());
    }

    public function test_the_new_login_can_sign_in_to_the_pos_api(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);
        $this->createLogin($employee);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'secret-pass-123',
            'device_name' => 'ipad',
        ])->assertOk();
    }

    public function test_an_employee_with_a_login_cannot_get_a_second_one(): void
    {
        $existing = User::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $this->second->id, 'user_id' => $existing->id]);

        $this->createLogin($employee)->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
        $this->assertSame($existing->id, $employee->fresh()->user_id);
    }

    public function test_the_email_must_be_unused(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);

        $this->createLogin($employee)->assertSessionHasErrors('email');
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);

        $this->createLogin($employee, ['password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_a_cashier_cannot_create_logins(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);

        $this->actingAs($cashier)->post("/employees/{$employee->id}/login", [
            'email' => 'ana@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_the_details_show_the_owner_whether_a_login_exists(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->second->id]);

        $this->actingAs($this->owner)->getJson("/employees/{$employee->id}/details")
            ->assertJsonPath('login', null);

        $this->createLogin($employee);

        $this->actingAs($this->owner)->getJson("/employees/{$employee->id}/details")
            ->assertJsonPath('login.email', 'ana@example.com');
    }
}
