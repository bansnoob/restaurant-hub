<?php

namespace Tests\Unit\Services;

use App\Exceptions\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceService;
    }

    public function test_clock_in_creates_attendance_record(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $capturedBy = User::factory()->create();
        $workDate = now()->toDateString();

        $record = $this->service->clockIn($employee, $workDate, $capturedBy, 'Test note');

        $this->assertInstanceOf(AttendanceRecord::class, $record);
        $this->assertEquals($employee->id, $record->employee_id);
        $this->assertEquals('present', $record->status);
        $this->assertNotNull($record->clock_in_at);
        $this->assertEquals('Test note', $record->notes);
    }

    public function test_clock_in_throws_if_already_clocked_in(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $capturedBy = User::factory()->create();
        $workDate = now()->toDateString();

        $this->service->clockIn($employee, $workDate, $capturedBy);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('already timed in');

        $this->service->clockIn($employee, $workDate, $capturedBy);
    }

    public function test_clock_out_sets_clock_out_time(): void
    {
        $record = AttendanceRecord::factory()->create([
            'clock_in_at' => now()->setTime(9, 0),
            'clock_out_at' => null,
        ]);

        $result = $this->service->clockOut($record);

        $this->assertNotNull($result->clock_out_at);
    }

    public function test_clock_out_throws_without_clock_in(): void
    {
        $record = AttendanceRecord::factory()->create([
            'clock_in_at' => null,
            'clock_out_at' => null,
        ]);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('without a clock-in record');

        $this->service->clockOut($record);
    }

    public function test_clock_out_throws_if_already_clocked_out(): void
    {
        $record = AttendanceRecord::factory()->clockedOut()->create();

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('already timed out');

        $this->service->clockOut($record);
    }
}
