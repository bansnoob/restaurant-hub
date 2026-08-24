<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            // Uncategorised by default: the vast majority of existing fixtures
            // predate categories, and an ingredient with no category is a
            // first-class state (it sorts into the trailing "Uncategorized"
            // section rather than vanishing).
            'ingredient_category_id' => null,
            'name' => fake()->unique()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('ING####')),
            'unit' => fake()->randomElement(['g', 'kg', 'ml', 'l', 'pcs']),
            'current_stock' => fake()->randomFloat(3, 20, 500),
            'reorder_level' => fake()->randomFloat(3, 5, 50),
            'cost_per_unit' => fake()->randomFloat(4, 0.01, 3.5),
            'is_active' => true,
        ];
    }

    public function inCategory(IngredientCategory|int|null $category): static
    {
        return $this->state(fn (): array => [
            'ingredient_category_id' => $category instanceof IngredientCategory
                ? $category->id
                : $category,
        ]);
    }
}
