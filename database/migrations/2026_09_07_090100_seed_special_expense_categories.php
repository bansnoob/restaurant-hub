<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the overhead types an owner should not have to invent on first use.
 *
 * Data lives in its own migration, separate from the schema, so a schema rollback
 * does not silently discard rows and the seed can be re-reasoned about on its own.
 *
 * These are starting points, not a closed set — SpecialExpenseController::store()
 * creates a new category on the fly from `new_category_name`, the same way
 * ExpenseController does for daily categories.
 *
 * updateOrInsert keyed on slug so re-running is harmless and an owner who has
 * already renamed one does not get a duplicate.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        'Rent',
        'Electricity',
        'Water',
        'Internet',
        'Gas / LPG',
        'Business Permit',
        'Insurance',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::CATEGORIES as $name) {
            DB::table('special_expense_categories')->updateOrInsert(
                ['slug' => \Illuminate\Support\Str::slug($name)],
                [
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('special_expense_categories')
            ->whereIn('slug', array_map(
                static fn (string $name): string => \Illuminate\Support\Str::slug($name),
                self::CATEGORIES
            ))
            ->delete();
    }
};
