<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PayrollRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RestaurantDemoSeeder extends Seeder
{
    /**
     * Seed starter demo data for POS, workforce, and inventory.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $branch = Branch::updateOrCreate(
                ['code' => 'MAIN001'],
                [
                    'name' => 'Restaurant Hub Main Branch',
                    'phone' => '+1-555-0100',
                    'email' => 'main@restauranthub.local',
                    'address' => '123 Main Street, Sample City',
                    'is_active' => true,
                ]
            );

            $usersByRole = [
                'cashier' => User::updateOrCreate(
                    ['email' => 'cashier@restauranthub.local'],
                    [
                        'name' => 'POS Cashier',
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                        'branch_id' => $branch->id,
                    ]
                ),
            ];

            // Also set branch_id on the owner user if it exists
            $owner = User::where('email', 'owner@restauranthub.local')->first();
            if ($owner && ! $owner->branch_id) {
                $owner->update(['branch_id' => $branch->id]);
            }

            foreach ($usersByRole as $role => $user) {
                $user->syncRoles([$role]);
            }

            DB::table('shifts')->updateOrInsert(
                ['branch_id' => $branch->id, 'name' => 'Morning'],
                [
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'grace_minutes' => 10,
                    'break_minutes' => 60,
                    'is_night_shift' => false,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('shifts')->updateOrInsert(
                ['branch_id' => $branch->id, 'name' => 'Evening'],
                [
                    'start_time' => '17:00:00',
                    'end_time' => '01:00:00',
                    'grace_minutes' => 10,
                    'break_minutes' => 30,
                    'is_night_shift' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $categories = [
                ['name' => 'Burgers', 'slug' => 'burgers', 'sort_order' => 1],
                ['name' => 'Pasta', 'slug' => 'pasta', 'sort_order' => 2],
                ['name' => 'Beverages', 'slug' => 'beverages', 'sort_order' => 3],
            ];

            $categoryIds = [];
            foreach ($categories as $category) {
                $record = MenuCategory::updateOrCreate(
                    ['branch_id' => $branch->id, 'slug' => $category['slug']],
                    [
                        'name' => $category['name'],
                        'sort_order' => $category['sort_order'],
                        'is_active' => true,
                    ]
                );
                $categoryIds[$category['slug']] = $record->id;
            }

            foreach ([
                ['name' => 'Utilities', 'slug' => 'utilities'],
                ['name' => 'Supplies', 'slug' => 'supplies'],
                ['name' => 'Rent', 'slug' => 'rent'],
            ] as $expenseCategory) {
                ExpenseCategory::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'slug' => $expenseCategory['slug'],
                    ],
                    [
                        'name' => $expenseCategory['name'],
                        'is_active' => true,
                    ]
                );
            }

            PayrollRule::updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'grace_minutes' => 10,
                    'standard_daily_hours' => 8,
                    'overtime_threshold_minutes' => 30,
                    'overtime_multiplier' => 1.25,
                    'undertime_rounding_minutes' => 15,
                    'late_penalty_per_minute' => 0.0100,
                    'absent_penalty_days' => 1,
                    'holiday_paid' => true,
                    'leave_paid' => false,
                ]
            );

            $menuItems = [
                [
                    'sku' => 'MI1001',
                    'name' => 'Classic Burger',
                    'slug' => 'classic-burger',
                    'category_id' => $categoryIds['burgers'],
                    'base_price' => 8.90,
                    'tax_rate' => 0,
                ],
                [
                    'sku' => 'MI1002',
                    'name' => 'Creamy Carbonara',
                    'slug' => 'creamy-carbonara',
                    'category_id' => $categoryIds['pasta'],
                    'base_price' => 10.75,
                    'tax_rate' => 0,
                ],
                [
                    'sku' => 'MI2001',
                    'name' => 'Iced Tea',
                    'slug' => 'iced-tea',
                    'category_id' => $categoryIds['beverages'],
                    'base_price' => 2.50,
                    'tax_rate' => 0,
                ],
            ];

            $menuItemIds = [];
            foreach ($menuItems as $item) {
                $record = MenuItem::updateOrCreate(
                    ['branch_id' => $branch->id, 'sku' => $item['sku']],
                    $item + ['description' => null, 'is_active' => true]
                );
                $menuItemIds[$item['sku']] = $record->id;
            }

            $ingredients = [
                ['sku' => 'ING1001', 'name' => 'Burger Patty', 'unit' => 'pcs', 'stock' => 120, 'reorder' => 30, 'cost' => 1.2500],
                ['sku' => 'ING1002', 'name' => 'Burger Bun', 'unit' => 'pcs', 'stock' => 130, 'reorder' => 40, 'cost' => 0.5500],
                ['sku' => 'ING1003', 'name' => 'Pasta', 'unit' => 'kg', 'stock' => 20, 'reorder' => 5, 'cost' => 2.1000],
                ['sku' => 'ING1004', 'name' => 'Cream', 'unit' => 'l', 'stock' => 15, 'reorder' => 4, 'cost' => 3.2500],
                ['sku' => 'ING1005', 'name' => 'Tea Concentrate', 'unit' => 'l', 'stock' => 12, 'reorder' => 3, 'cost' => 2.8500],
            ];

            $ingredientIds = [];
            foreach ($ingredients as $ingredient) {
                $record = Ingredient::updateOrCreate(
                    ['branch_id' => $branch->id, 'sku' => $ingredient['sku']],
                    [
                        'name' => $ingredient['name'],
                        'unit' => $ingredient['unit'],
                        'current_stock' => $ingredient['stock'],
                        'reorder_level' => $ingredient['reorder'],
                        'cost_per_unit' => $ingredient['cost'],
                        'is_active' => true,
                    ]
                );
                $ingredientIds[$ingredient['sku']] = $record->id;
            }

            $recipeItems = [
                ['menu_sku' => 'MI1001', 'ingredient_sku' => 'ING1001', 'quantity' => 1.000],
                ['menu_sku' => 'MI1001', 'ingredient_sku' => 'ING1002', 'quantity' => 1.000],
                ['menu_sku' => 'MI1002', 'ingredient_sku' => 'ING1003', 'quantity' => 0.180],
                ['menu_sku' => 'MI1002', 'ingredient_sku' => 'ING1004', 'quantity' => 0.060],
                ['menu_sku' => 'MI2001', 'ingredient_sku' => 'ING1005', 'quantity' => 0.080],
            ];

            foreach ($recipeItems as $recipe) {
                DB::table('recipe_items')->updateOrInsert(
                    [
                        'menu_item_id' => $menuItemIds[$recipe['menu_sku']],
                        'ingredient_id' => $ingredientIds[$recipe['ingredient_sku']],
                    ],
                    [
                        'quantity' => $recipe['quantity'],
                        'waste_factor' => 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('inventory_movements')
                ->where('branch_id', $branch->id)
                ->where('reference_type', 'opening_balance')
                ->delete();

            foreach ($ingredients as $ingredient) {
                DB::table('inventory_movements')->insert([
                    'branch_id' => $branch->id,
                    'ingredient_id' => $ingredientIds[$ingredient['sku']],
                    'direction' => 'in',
                    'movement_type' => 'purchase',
                    'quantity' => $ingredient['stock'],
                    'unit_cost' => $ingredient['cost'],
                    'reference_type' => 'opening_balance',
                    'reference_id' => null,
                    'notes' => 'Opening stock from seeder',
                    'moved_at' => now(),
                    'created_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Extra sample records generated through factories for testing.
            MenuItem::factory()
                ->count(2)
                ->state(fn () => [
                    'branch_id' => $branch->id,
                    'category_id' => $categoryIds['burgers'],
                ])
                ->create();

            Ingredient::factory()
                ->count(2)
                ->state(fn () => ['branch_id' => $branch->id])
                ->create();
        });
    }
}
