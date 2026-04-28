<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'order_number' => strtoupper(fake()->unique()->bothify('ORD-########')),
            'sale_datetime' => now(),
            'cashier_user_id' => User::factory(),
            'table_label' => null,
            'order_type' => fake()->randomElement(['dine_in', 'takeout', 'delivery']),
            'status' => 'open',
            'sub_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
            'change_total' => 0,
            'payment_method' => 'unpaid',
            'notes' => null,
            'closed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'payment_method' => 'cash',
            'closed_at' => now(),
        ]);
    }
}
