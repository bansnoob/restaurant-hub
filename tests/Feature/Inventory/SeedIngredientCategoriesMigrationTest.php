<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Support\Inventory\DefaultIngredientCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration, exercised against a database shaped like production:
 * ONE branch, the 22 ingredients that were live the day categories shipped,
 * every one of them `pcs`.
 *
 * This is the highest-risk piece of the change — it is the only code in the
 * feature that writes to rows somebody is already counting stock with — so it
 * is asserted on membership, not just on totals. A migration that files eight
 * ingredients under "Toppings" is not correct if they are the wrong eight.
 *
 * The rollback path and the owner-edit idempotency live in
 * IngredientCategoryMigrationTest; this file is about the seed's arithmetic.
 */
class SeedIngredientCategoriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 22 live rows, verified against production the day this shipped.
     *
     * @var array<string, list<string>>
     */
    private const LIVE_INGREDIENTS = [
        'Sauces & Seasoning' => [
            'Chili Oil', 'Curry Sauce', 'Katsu Sauce', 'Kikoman',
            'Miso Paste', 'Sesame Oil', 'Shoyu', 'Tonkotsu / Soup',
        ],
        'Base & Mains' => ['Noodles', 'Gyoza', 'Tantanmen', 'Four Seasons'],
        'Toppings' => [
            'Black Garlic', 'Black Sesame', 'Chasu Pork', 'Kikurage',
            'Red Pepper', 'Seaweed Strips', 'Wakame', 'White Sesame',
        ],
        'Drinks' => ['Red Iced Tea'],
        'Packaging' => ['Take-out Bowls'],
    ];

    public function test_a_production_shaped_database_files_every_ingredient_on_the_right_shelf(): void
    {
        $branchId = $this->makeBranch('MAIN');
        $this->makeIngredients($branchId, $this->liveNames());

        $this->assertSame(22, DB::table('ingredients')->count(), 'the fixture must mirror production');

        $this->assertSame(1, DefaultIngredientCategories::seedAllBranches());

        // The counts the operator quoted: 8 + 4 + 8 + 1 + 1 = 22.
        $this->assertSame(
            ['Sauces & Seasoning' => 8, 'Base & Mains' => 4, 'Toppings' => 8, 'Drinks' => 1, 'Packaging' => 1],
            $this->countsByCategory($branchId)
        );

        // ...and the right eight, not merely eight of them.
        $this->assertSame($this->expectedMembership(), $this->membership($branchId));

        // The production deploy check must come back empty.
        $this->assertSame(
            [],
            DB::table('ingredients')->whereNull('ingredient_category_id')->pluck('name')->all()
        );
    }

    public function test_the_two_punctuated_names_land_correctly(): void
    {
        $branchId = $this->makeBranch('MAIN');
        // Both have survived a round trip through a slug-shaped mind before:
        // the slash and the hyphen are exactly what a naive normaliser eats.
        $this->makeIngredients($branchId, ['Tonkotsu / Soup', 'Take-out Bowls']);

        DefaultIngredientCategories::seedBranch($branchId);

        $filed = $this->membership($branchId);
        $this->assertSame(['Tonkotsu / Soup'], $filed['Sauces & Seasoning']);
        $this->assertSame(['Take-out Bowls'], $filed['Packaging']);
    }

    public function test_running_the_seed_twice_over_the_live_set_changes_nothing(): void
    {
        $branchId = $this->makeBranch('MAIN');
        $this->makeIngredients($branchId, $this->liveNames());
        DefaultIngredientCategories::seedAllBranches();

        $before = $this->fingerprint();

        $this->assertSame(0, DefaultIngredientCategories::seedAllBranches(), 'a second pass seeds no branch');
        $this->assertSame($before, $this->fingerprint(), 'not one row may change on a re-run');
        $this->assertSame(
            count(self::LIVE_INGREDIENTS),
            DB::table('ingredient_categories')->count(),
            'a re-run must not duplicate the walk'
        );
    }

    public function test_each_branch_gets_its_own_walk_and_the_slugs_do_not_collide(): void
    {
        $main = $this->makeBranch('MAIN');
        $north = $this->makeBranch('NORTH');
        $this->makeIngredients($main, ['Shoyu', 'Noodles']);
        $this->makeIngredients($north, ['Wakame', 'Red Iced Tea']);

        $this->assertSame(2, DefaultIngredientCategories::seedAllBranches());

        foreach ([$main, $north] as $branchId) {
            $this->assertSame(
                array_keys(DefaultIngredientCategories::CATEGORY_MAP),
                DB::table('ingredient_categories')->where('branch_id', $branchId)
                    ->orderBy('sort_order')->pluck('name')->all(),
                'every branch gets the whole walk, in walk order'
            );
        }

        // The unique key is (branch_id, slug): identical slugs across two
        // branches are REQUIRED to coexist, and the insert above proves they do.
        $this->assertSame(
            2,
            DB::table('ingredient_categories')->where('slug', 'toppings')->count()
        );

        // ...and nothing crossed over.
        $this->assertSame(['Shoyu', 'Noodles'], $this->namesOfIngredientsIn($main));
        $this->assertSame(['Wakame', 'Red Iced Tea'], $this->namesOfIngredientsIn($north));
    }

    public function test_a_branch_whose_names_are_all_unknown_is_seeded_but_files_nothing(): void
    {
        $branchId = $this->makeBranch('MAIN');
        $this->makeIngredients($branchId, ['Mystery Powder', 'Unlabelled Tub']);

        $this->assertTrue(DefaultIngredientCategories::seedBranch($branchId));

        $this->assertSame(
            count(DefaultIngredientCategories::CATEGORY_MAP),
            DB::table('ingredient_categories')->where('branch_id', $branchId)->count()
        );
        $this->assertSame(
            2,
            DB::table('ingredients')->whereNull('ingredient_category_id')->count(),
            'an unknown name is left in the Uncategorized tail, never guessed at'
        );
    }

    /** @return list<string> */
    private function liveNames(): array
    {
        return array_merge(...array_values(self::LIVE_INGREDIENTS));
    }

    /** @return array<string, int> */
    private function countsByCategory(int $branchId): array
    {
        $counts = [];

        foreach (array_keys(DefaultIngredientCategories::CATEGORY_MAP) as $name) {
            $counts[$name] = DB::table('ingredients as i')
                ->join('ingredient_categories as c', 'c.id', '=', 'i.ingredient_category_id')
                ->where('i.branch_id', $branchId)
                ->where('c.name', $name)
                ->count();
        }

        return $counts;
    }

    /**
     * category name => the ingredient names filed under it, sorted so the
     * comparison is about membership rather than insertion order.
     *
     * @return array<string, list<string>>
     */
    private function membership(int $branchId): array
    {
        $rows = DB::table('ingredients as i')
            ->join('ingredient_categories as c', 'c.id', '=', 'i.ingredient_category_id')
            ->where('i.branch_id', $branchId)
            // Explicit aliases: both tables have a `name`, and an unqualified
            // select would collide them into one value.
            ->select(['c.name as category_name', 'i.name as ingredient_name'])
            ->get();

        $membership = [];

        foreach ($rows as $row) {
            $membership[$row->category_name][] = $row->ingredient_name;
        }

        foreach ($membership as $category => $names) {
            sort($names);
            $membership[$category] = $names;
        }

        ksort($membership);

        return $membership;
    }

    /** @return array<string, list<string>> */
    private function expectedMembership(): array
    {
        $expected = [];

        foreach (self::LIVE_INGREDIENTS as $category => $names) {
            sort($names);
            $expected[$category] = $names;
        }

        ksort($expected);

        return $expected;
    }

    /** @return list<string> */
    private function namesOfIngredientsIn(int $branchId): array
    {
        return DB::table('ingredients as i')
            ->join('ingredient_categories as c', 'c.id', '=', 'i.ingredient_category_id')
            ->where('c.branch_id', $branchId)
            ->orderBy('i.id')
            ->pluck('i.name')
            ->all();
    }

    private function makeBranch(string $code): int
    {
        return (int) DB::table('branches')->insertGetId([
            'code' => $code, 'name' => ucfirst(strtolower($code)), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $names */
    private function makeIngredients(int $branchId, array $names): void
    {
        foreach ($names as $name) {
            DB::table('ingredients')->insert([
                'branch_id' => $branchId, 'name' => $name, 'sku' => null,
                // Every live row is `pcs`; the fixture keeps that true.
                'unit' => 'pcs', 'current_stock' => 10, 'reorder_level' => 2,
                'cost_per_unit' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
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
