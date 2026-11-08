<?php

namespace Database\Factories;

use App\Models\PayrollDeduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollDeduction>
 */
class PayrollDeductionFactory extends Factory
{
    protected $model = PayrollDeduction::class;

    public function definition(): array
    {
        return [
            'amount' => fake()->randomFloat(2, 50, 500),
            'reason' => fake()->sentence(3),
            'recorded_by_user_id' => null,
        ];
    }
}
