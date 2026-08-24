<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'direction' => InventoryService::DIRECTION_IN,
            'movement_type' => InventoryService::TYPE_PURCHASE,
            'quantity' => fake()->randomFloat(3, 1, 50),
            'unit_cost' => null,
            // NULL reference_type is the definition of "unclaimed".
            'reference_type' => null,
            'reference_id' => null,
            'notes' => null,
            'moved_at' => now(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
