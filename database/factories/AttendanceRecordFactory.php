<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'branch_id' => fn (array $attrs) => Employee::find($attrs['employee_id'])->branch_id,
            'shift_id' => null,
            'work_date' => now()->toDateString(),
            'clock_in_at' => now()->setTime(9, 0),
            'clock_out_at' => null,
            'break_minutes' => 0,
            'status' => 'present',
            'notes' => null,
            'captured_by_user_id' => User::factory(),
        ];
    }

    public function clockedOut(): static
    {
        return $this->state(fn () => [
            'clock_out_at' => now()->setTime(17, 0),
        ]);
    }
}
