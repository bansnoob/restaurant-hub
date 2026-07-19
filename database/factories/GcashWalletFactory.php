<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GcashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GcashWallet>
 */
class GcashWalletFactory extends Factory
{
    protected $model = GcashWallet::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'opening_balance' => fake()->randomFloat(2, 0, 10000),
            // Far enough back that test entries fall on or after it by default.
            'opening_date' => now()->subYear()->toDateString(),
            'updated_by_user_id' => null,
        ];
    }
}
