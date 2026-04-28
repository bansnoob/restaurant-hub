<?php

namespace Tests\Feature\Api\V1;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create();
        $this->user->assignRole('cashier');
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_cashier_can_clock_in_employee(): void
    {
        $targetEmployee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/clock-in', [
            'employee_id' => $targetEmployee->id,
            'work_date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'employee_id', 'work_date', 'clock_in_at', 'status'],
            ]);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $targetEmployee->id,
            'status' => 'present',
        ]);
    }

    public function test_clock_in_fails_for_already_clocked_in_employee(): void
    {
        $targetEmployee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $targetEmployee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'clock_in_at' => now()->setTime(9, 0),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/clock-in', [
            'employee_id' => $targetEmployee->id,
            'work_date' => now()->toDateString(),
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'This employee is already timed in for the selected date.']);
    }

    public function test_cashier_can_clock_out_employee(): void
    {
        $record = AttendanceRecord::factory()->create([
            'branch_id' => $this->branch->id,
            'clock_in_at' => now()->setTime(9, 0),
            'clock_out_at' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/clock-out', [
            'record_id' => $record->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $record->id);

        $this->assertNotNull($record->fresh()->clock_out_at);
    }

    public function test_clock_out_fails_without_clock_in(): void
    {
        $record = AttendanceRecord::factory()->create([
            'branch_id' => $this->branch->id,
            'clock_in_at' => null,
            'clock_out_at' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/clock-out', [
            'record_id' => $record->id,
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Cannot time out an employee without a clock-in record.']);
    }

    public function test_clock_out_fails_if_already_clocked_out(): void
    {
        $record = AttendanceRecord::factory()->clockedOut()->create([
            'branch_id' => $this->branch->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/clock-out', [
            'record_id' => $record->id,
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'This attendance entry is already timed out.']);
    }

    public function test_today_returns_branch_records(): void
    {
        AttendanceRecord::factory()->create([
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
        ]);

        // Record from another branch should not appear
        AttendanceRecord::factory()->create([
            'work_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/attendance/today');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/attendance/clock-in', [
            'employee_id' => 1,
            'work_date' => now()->toDateString(),
        ]);

        $response->assertUnauthorized();
    }
}
