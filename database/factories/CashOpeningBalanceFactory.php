<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashOpeningBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashOpeningBalance>
 */
class CashOpeningBalanceFactory extends Factory
{
    protected $model = CashOpeningBalance::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'opening_balance' => fake()->randomFloat(2, 0, 10000),
            // Far enough back that test closures fall on or after it by default.
            'opening_date' => now()->subYear()->toDateString(),
            'updated_by_user_id' => null,
        ];
    }
}
