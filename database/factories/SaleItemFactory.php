<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 50, 500);
        $quantity = 1;
        $taxTotal = round($unitPrice * $quantity * 0.12, 2);
        $lineTotal = round(($unitPrice * $quantity) + $taxTotal, 2);

        return [
            'sale_id' => Sale::factory(),
            'menu_item_id' => MenuItem::factory(),
            'item_name' => fake()->words(2, true),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'discount_total' => 0,
            'tax_total' => $taxTotal,
            'line_total' => $lineTotal,
            'notes' => null,
        ];
    }
}
