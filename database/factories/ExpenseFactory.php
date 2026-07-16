<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'expense_category_id' => null,
            'recorded_by_user_id' => null,
            'expense_date' => now()->toDateString(),
            'reference_no' => null,
            'vendor_name' => fake()->company(),
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'payment_method' => 'cash',
            'status' => 'approved',
            'notes' => null,
        ];
    }

    public function gcash(): static
    {
        return $this->state(fn () => ['payment_method' => 'gcash']);
    }
}
