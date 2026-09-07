<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SpecialExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpecialExpense>
 */
class SpecialExpenseFactory extends Factory
{
    protected $model = SpecialExpense::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'special_expense_category_id' => null,
            'recorded_by_user_id' => null,
            'period_month' => now()->startOfMonth()->toDateString(),
            'paid_date' => null,
            'description' => fake()->sentence(3),
            'vendor_name' => fake()->company(),
            'reference_no' => null,
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'payment_method' => 'cash',
            'notes' => null,
        ];
    }

    /**
     * Company-wide rather than tied to a single location.
     */
    public function companyWide(): static
    {
        return $this->state(fn () => ['branch_id' => null]);
    }

    public function gcash(): static
    {
        return $this->state(fn () => ['payment_method' => 'gcash']);
    }

    public function forMonth(string $month): static
    {
        return $this->state(fn () => [
            'period_month' => \Illuminate\Support\Carbon::parse($month)->startOfMonth()->toDateString(),
        ]);
    }
}
