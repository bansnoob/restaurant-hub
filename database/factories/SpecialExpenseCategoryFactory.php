<?php

namespace Database\Factories;

use App\Models\SpecialExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SpecialExpenseCategory>
 */
class SpecialExpenseCategoryFactory extends Factory
{
    protected $model = SpecialExpenseCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'is_active' => true,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
