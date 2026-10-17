<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cashier works the POS (the iPad app) and nothing else. The website's
 * Employees, Attendance and Cash Report pages used to admit cashiers and showed
 * them every branch: staff with daily rates and payroll history, the whole
 * roster, and the other branch's cash. Any website page now sends a cashier to
 * the POS; the iPad API they actually use is unchanged.
 */
class CashierPosOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        Employee::factory()->create(['user_id' => $this->cashier->id, 'branch_id' => $this->branch->id]);

        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    /** @return array<string, array{string}> */
    public static function websitePages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'employees' => ['/employees'],
            'attendance' => ['/attendance'],
            'cash report' => ['/cash-report'],
            'day close preview' => ['/day-close/preview'],
            'sales' => ['/sales'],
            'profile' => ['/profile'],
        ];
    }

    #[DataProvider('websitePages')]
    public function test_a_cashier_is_sent_to_the_pos_from_every_website_page(string $url): void
    {
        $this->actingAs($this->cashier)->get($url)->assertRedirect(url('/pos/'));
    }

    #[DataProvider('websitePages')]
    public function test_the_owner_still_opens_every_website_page(string $url): void
    {
        $this->actingAs($this->owner)->get($url)->assertOk();
    }

    public function test_a_cashier_cannot_write_through_the_website(): void
    {
        $colleague = Employee::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->cashier)
            ->post('/attendance/clock-in', ['employee_id' => $colleague->id, 'work_date' => now()->toDateString()])
            ->assertRedirect(url('/pos/'));
        $this->actingAs($this->cashier)
            ->post('/day-close', ['branch_id' => $this->branch->id, 'closed_at_date' => now()->toDateString(), 'counted_cash' => 0])
            ->assertRedirect(url('/pos/'));
        $this->actingAs($this->cashier)
            ->post('/employees', ['branch_id' => $this->branch->id, 'first_name' => 'A', 'last_name' => 'B', 'hire_date' => now()->toDateString(), 'employment_type' => 'full_time'])
            ->assertRedirect(url('/pos/'));

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('day_closures', 0);
        $this->assertSame(2, Employee::count());
    }

    public function test_a_cashier_can_still_sign_out(): void
    {
        $this->actingAs($this->cashier)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_the_ipad_api_still_serves_the_cashier(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->getJson('/api/v1/employees')->assertOk();
        $this->getJson('/api/v1/day-close/preview')->assertOk();
    }
}
