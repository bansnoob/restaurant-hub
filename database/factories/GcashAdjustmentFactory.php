<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GcashAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GcashAdjustment>
 */
class GcashAdjustmentFactory extends Factory
{
    protected $model = GcashAdjustment::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'adjustment_date' => now()->toDateString(),
            'amount' => -1 * fake()->randomFloat(2, 50, 2000),
            'reason' => fake()->sentence(4),
            'recorded_by_user_id' => null,
        ];
    }
}
