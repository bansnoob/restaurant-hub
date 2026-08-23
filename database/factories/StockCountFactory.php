<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'counted_at' => now()->toDateString(),
            'recorded_by_user_id' => User::factory(),
            'notes' => null,
            'total_value' => 0,
        ];
    }
}
