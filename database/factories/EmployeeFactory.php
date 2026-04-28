<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'branch_id' => Branch::factory(),
            'employee_code' => strtoupper(fake()->unique()->bothify('EMP####')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'hire_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'contract']),
            'hourly_rate' => null,
            'daily_rate' => fake()->randomFloat(2, 65, 220),
            'is_active' => true,
        ];
    }
}
