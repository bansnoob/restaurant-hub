<?php

declare(strict_types=1);

use App\Support\Inventory\DefaultIngredientCategories;
use Illuminate\Database\Migrations\Migration;

/**
 * DATA ONLY — installs the default category walk on every EXISTING branch and
 * pre-assigns the ingredients that were live when categories shipped.
 *
 * All of the logic (the map, the virgin-branch guard, the case-insensitive
 * trimmed name matching, the "leave an unknown name NULL" rule) lives in
 * App\Support\Inventory\DefaultIngredientCategories, so a branch created after
 * this deploy can be given the same shelves instead of inheriting an empty
 * list from a migration that has already run. Read that class's docblock for
 * the full safety contract.
 *
 * DEPLOY CHECK for this migration, on production:
 *     SELECT name FROM ingredients WHERE ingredient_category_id IS NULL;
 * It should return zero rows. Any row it does return is an ingredient renamed
 * between the mapping being written and the deploy landing — file it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        DefaultIngredientCategories::seedAllBranches();
    }

    /**
     * Removes ONLY the rows up() inserted, identified by `is_seeded` rather
     * than by slug — slugs are user-editable, so a rename would both hide a
     * seeded row from the rollback and expose a look-alike the owner created.
     * Ingredients are unlinked, never deleted.
     */
    public function down(): void
    {
        DefaultIngredientCategories::removeSeeded();
    }
};
