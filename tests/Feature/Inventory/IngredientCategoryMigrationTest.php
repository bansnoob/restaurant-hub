<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Support\Inventory\DefaultIngredientCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The migrations' down() path, which RefreshDatabase never exercises.
 *
 * Rolling back is driver-specific: SQLite cannot drop a column named in a
 * FOREIGN KEY clause, and Laravel's SQLite grammar compiles dropForeign() to
 * nothing, so the schema migration rebuilds the table instead. Nothing else in
 * the suite would notice if that path broke, and it only breaks at the moment
 * somebody is trying to roll a bad deploy back.
 */
class IngredientCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_MIGRATION = '2026_08_24_090100_seed_ingredient_categories';

    private const SCHEMA_MIGRATION = '2026_08_24_090000_create_ingredient_categories_table';

    public function test_rolling_both_migrations_back_preserves_every_ingredient(): void
    {
        $branchId = $this->makeBranch();
        $this->makeIngredients($branchId, ['Shoyu', 'Noodles', 'Mystery Powder']);
        DefaultIngredientCategories::seedBranch($branchId);

        $this->assertSame(2, DB::table('ingredients')->whereNotNull('ingredient_category_id')->count());

        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

        $this->assertFalse(Schema::hasTable('ingredient_categories'));
        $this->assertFalse(Schema::hasColumn('ingredients', 'ingredient_category_id'));
        $this->assertSame(3, DB::table('ingredients')->count(), 'no stock row may be lost by a rollback');
        $this->assertSame(
            ['Mystery Powder', 'Noodles', 'Shoyu'],
            DB::table('ingredients')->orderBy('name')->pluck('name')->all()
        );

        // The rebuilt table must still be usable, with its original constraints.
        Artisan::call('migrate', ['--force' => true]);
        $this->assertTrue(Schema::hasColumn('ingredients', 'ingredient_category_id'));
    }

    public function test_the_seed_rollback_spares_owner_and_shared_categories(): void
    {
        $branchId = $this->makeBranch();
        $this->makeIngredients($branchId, ['Shoyu']);
        DefaultIngredientCategories::seedBranch($branchId);

        // The owner renames a seeded shelf, adds one of their own, and a shared
        // category exists across every branch.
        DB::table('ingredient_categories')->where('slug', 'toppings')
            ->update(['name' => 'Topping Station', 'slug' => 'topping-station']);
        DB::table('ingredient_categories')->insert([
            'branch_id' => $branchId, 'name' => 'Chillers', 'slug' => 'chillers',
            'sort_order' => 60, 'is_active' => true, 'is_seeded' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ingredient_categories')->insert([
            'branch_id' => null, 'name' => 'Drinks', 'slug' => 'drinks',
            'sort_order' => 5, 'is_active' => true, 'is_seeded' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DefaultIngredientCategories::removeSeeded();

        $survivors = DB::table('ingredient_categories')->orderBy('id')->get();
        $this->assertSame(['Chillers', 'Drinks'], $survivors->pluck('name')->sort()->values()->all());
        $this->assertTrue(
            $survivors->contains(fn ($c) => $c->branch_id === null),
            'a per-branch seeder must never delete a shared category'
        );
        $this->assertSame(1, DB::table('ingredients')->count());
    }

    public function test_seeding_is_idempotent_after_the_owner_has_edited_the_walk(): void
    {
        $branchId = $this->makeBranch();
        $this->makeIngredients($branchId, ['Shoyu', 'Noodles']);

        $this->assertTrue(DefaultIngredientCategories::seedBranch($branchId));

        DB::table('ingredient_categories')->where('slug', 'toppings')
            ->update(['name' => 'Topping Station', 'slug' => 'topping-station', 'sort_order' => 5]);
        $before = $this->fingerprint();

        $this->assertFalse(DefaultIngredientCategories::seedBranch($branchId), 're-seeding a managed branch is a no-op');
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_branch_with_no_ingredients_and_an_empty_install_migrate_cleanly(): void
    {
        $this->assertSame(0, DefaultIngredientCategories::seedAllBranches());

        $branchId = $this->makeBranch();
        $this->assertTrue(DefaultIngredientCategories::seedBranch($branchId));
        $this->assertSame(
            count(DefaultIngredientCategories::CATEGORY_MAP),
            DB::table('ingredient_categories')->count()
        );
        $this->assertSame(0, DB::table('ingredients')->count());
    }

    public function test_names_match_case_insensitively_and_ignore_surrounding_whitespace(): void
    {
        $branchId = $this->makeBranch();
        $this->makeIngredients($branchId, ['  chili   oil ', 'NOODLES', 'Not On Any Shelf']);
        DefaultIngredientCategories::seedBranch($branchId);

        // Explicit aliases: both columns are called `name`, and an unqualified
        // pluck() would collide them into one key.
        $filed = DB::table('ingredients as i')
            ->join('ingredient_categories as c', 'c.id', '=', 'i.ingredient_category_id')
            ->pluck('c.name as category_name', 'i.name as ingredient_name');

        $this->assertSame('Sauces & Seasoning', $filed['  chili   oil ']);
        $this->assertSame('Base & Mains', $filed['NOODLES']);
        $this->assertNull(
            DB::table('ingredients')->where('name', 'Not On Any Shelf')->value('ingredient_category_id'),
            'an unmapped name is left NULL, never guessed at'
        );
    }

    public function test_a_branch_created_after_the_deploy_gets_the_default_walk(): void
    {
        $owner = User::factory()->create();
        Role::findOrCreate('owner');
        $owner->assignRole('owner');

        $this->actingAs($owner)->post('/branches', [
            'code' => 'NORTH',
            'name' => 'North Branch',
        ])->assertRedirect();

        $branchId = (int) DB::table('branches')->where('code', 'north')->value('id');

        $this->assertSame(
            array_keys(DefaultIngredientCategories::CATEGORY_MAP),
            DB::table('ingredient_categories')->where('branch_id', $branchId)
                ->orderBy('sort_order')->pluck('name')->all(),
            'a branch created after the seed migration ran must still get the default walk'
        );
    }

    private function makeBranch(): int
    {
        return (int) DB::table('branches')->insertGetId([
            'code' => 'MAIN', 'name' => 'Main', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $names */
    private function makeIngredients(int $branchId, array $names): void
    {
        foreach ($names as $name) {
            DB::table('ingredients')->insert([
                'branch_id' => $branchId, 'name' => $name, 'sku' => null, 'unit' => 'pcs',
                'current_stock' => 1, 'reorder_level' => 1, 'cost_per_unit' => 0,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function fingerprint(): string
    {
        return json_encode([
            DB::table('ingredient_categories')->orderBy('id')->get()->toArray(),
            DB::table('ingredients')->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR);
    }
}
