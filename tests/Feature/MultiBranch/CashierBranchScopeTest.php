<?php

namespace Tests\Feature\MultiBranch;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cashier works one branch: the one their employee record (or their own
 * users.branch_id) points at. Every write a cashier can make on the iPad API —
 * closing a day, logging an expense, clocking staff in or out — must land on that
 * branch. (The website no longer serves cashiers at all; see CashierPosOnlyTest.)
 * Owners still act on any branch, on the API and the website.
 */
class CashierBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $main;

    private Branch $second;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->main = Branch::factory()->create(['name' => 'Main Branch']);
        $this->second = Branch::factory()->create(['name' => 'Second Branch']);

        // The way a cashier login is made from an employee: no users.branch_id,
        // the branch comes from the linked employee.
        $this->cashier = User::factory()->create(['branch_id' => null]);
        $this->cashier->assignRole('cashier');
        Employee::factory()->create(['user_id' => $this->cashier->id, 'branch_id' => $this->second->id]);
    }

    private function closePayload(Branch $branch): array
    {
        return [
            'branch_id' => $branch->id,
            'closed_at_date' => now()->toDateString(),
            'counted_cash' => 0,
        ];
    }

    // ── Owner (website) ──────────────────────────────────────────────────

    public function test_the_owner_can_still_close_any_branch(): void
    {
        $owner = User::factory()->create(['branch_id' => $this->main->id]);
        $owner->assignRole('owner');

        $this->actingAs($owner)->postJson('/day-close', $this->closePayload($this->second))->assertSuccessful();
        $this->actingAs($owner)->getJson('/day-close/preview?branch_id='.$this->second->id)
            ->assertOk()
            ->assertJsonPath('branch.id', $this->second->id);
    }

    // ── API Day Close ────────────────────────────────────────────────────

    public function test_the_api_refuses_a_cashier_closing_another_branch(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/day-close', $this->closePayload($this->main))->assertForbidden();
        $this->getJson('/api/v1/day-close/preview?branch_id='.$this->main->id)->assertForbidden();
        $this->getJson('/api/v1/day-close/history?branch_id='.$this->main->id)->assertForbidden();

        $this->assertSame(0, DayClosure::count());
    }

    public function test_the_api_closes_the_cashiers_own_branch_by_default(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/day-close', [
            'closed_at_date' => now()->toDateString(),
            'counted_cash' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('day_closures', ['branch_id' => $this->second->id]);
    }

    // ── API expenses ─────────────────────────────────────────────────────

    public function test_the_api_refuses_a_cashier_expense_for_another_branch(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/expenses', [
            'branch_id' => $this->main->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Ice',
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);
    }

    // ── Attendance ───────────────────────────────────────────────────────

    public function test_a_cashier_cannot_clock_in_another_branchs_employee(): void
    {
        $elsewhere = Employee::factory()->create(['branch_id' => $this->main->id]);
        $payload = ['employee_id' => $elsewhere->id, 'work_date' => now()->toDateString()];

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/attendance/clock-in', $payload)->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_a_cashier_can_clock_in_their_own_branchs_employee(): void
    {
        $colleague = Employee::factory()->create(['branch_id' => $this->second->id]);

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/attendance/clock-in', ['employee_id' => $colleague->id, 'work_date' => now()->toDateString()])
            ->assertCreated();

        $this->assertDatabaseHas('attendance_records', ['employee_id' => $colleague->id, 'branch_id' => $this->second->id]);
    }

    public function test_a_cashier_cannot_clock_out_another_branchs_record(): void
    {
        $elsewhere = Employee::factory()->create(['branch_id' => $this->main->id]);
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $elsewhere->id,
            'branch_id' => $this->main->id,
            'work_date' => now()->toDateString(),
            'clock_in_at' => now()->subHours(2),
            'clock_out_at' => null,
        ]);

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/attendance/clock-out', ['record_id' => $record->id])->assertForbidden();

        $this->assertNull($record->fresh()->clock_out_at);
    }

    public function test_the_owner_can_clock_in_staff_at_any_branch(): void
    {
        $owner = User::factory()->create(['branch_id' => $this->main->id]);
        $owner->assignRole('owner');
        $elsewhere = Employee::factory()->create(['branch_id' => $this->second->id]);

        $this->actingAs($owner)
            ->post('/attendance/clock-in', ['employee_id' => $elsewhere->id, 'work_date' => now()->toDateString()])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance_records', ['employee_id' => $elsewhere->id]);
    }
}
