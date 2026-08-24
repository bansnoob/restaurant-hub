<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\IngredientCategory;
use App\Support\Inventory\CategorySlug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientCategory>
 */
class IngredientCategoryFactory extends Factory
{
    protected $model = IngredientCategory::class;

    /**
     * Sort orders step by 10, matching IngredientCategory::SORT_ORDER_STEP, so
     * a factory-built walk behaves like a seeded one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->words(2, true));

        return [
            'branch_id' => Branch::factory(),
            'name' => $name,
            'slug' => CategorySlug::for($name),
            'sort_order' => fake()->numberBetween(1, 20) * IngredientCategory::SORT_ORDER_STEP,
            'is_active' => true,
        ];
    }

    /** A SHARED category: visible to every branch, editable from none. */
    public function shared(): static
    {
        return $this->state(fn (): array => ['branch_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function atPosition(int $sortOrder): static
    {
        return $this->state(fn (): array => ['sort_order' => $sortOrder]);
    }
}
