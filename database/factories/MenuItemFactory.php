<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $price = fake()->randomFloat(2, 3, 18);

        return [
            'branch_id' => Branch::factory(),
            'category_id' => null,
            'sku' => strtoupper(fake()->unique()->bothify('MI####')),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(3),
            'description' => fake()->sentence(),
            'item_type' => fake()->randomElement(['food', 'beverage', 'other']),
            'base_price' => $price,
            'cost_price' => round($price * 0.35, 2),
            'tax_rate' => 12.00,
            'is_active' => true,
        ];
    }
}
