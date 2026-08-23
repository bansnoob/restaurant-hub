<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCountEntry>
 */
class StockCountEntryFactory extends Factory
{
    protected $model = StockCountEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previous = fake()->randomFloat(3, 50, 200);
        $restocked = 0.0;
        $counted = fake()->randomFloat(3, 10, $previous);

        return [
            'stock_count_id' => StockCount::factory(),
            'ingredient_id' => Ingredient::factory(),
            'previous_quantity' => $previous,
            'restocked_quantity' => $restocked,
            'counted_quantity' => $counted,
            'consumption' => max(0, $previous + $restocked - $counted),
            'unit_cost' => 0,
            'line_value' => 0,
        ];
    }
}
