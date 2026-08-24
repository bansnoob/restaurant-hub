<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The default inventory walk, and the one place that knows how to install it
 * on a branch.
 *
 * It lives here rather than inside the seed migration so that a branch created
 * AFTER this shipped can be given the same shelves — a one-shot migration
 * would leave every future branch with an empty category list and no way to
 * get the defaults back.
 *
 * Written with the query builder, not Eloquent: a migration calls it, and a
 * migration must keep working after the models above it have moved on.
 *
 * SAFETY CONTRACT (this runs against live ingredient rows):
 *  - VIRGIN-ONLY. A branch that already owns even one category is skipped
 *    entirely. That, not the slug, is what makes a re-run a true no-op —
 *    slugs are user-editable (a rename re-derives them), so keying the seed
 *    on them would resurrect a phantom "Toppings" beside the owner's renamed
 *    "Topping Station".
 *  - NON-DESTRUCTIVE. Nothing is deleted, and an ingredient is written only
 *    while its ingredient_category_id IS NULL — re-asserted in the UPDATE
 *    itself, not merely in the read that built the id list.
 *  - TOTAL. An ingredient whose name is not in the map is LEFT NULL. Guessing
 *    would file stock under a shelf it is not on, which is worse than an empty
 *    cell; it simply shows up in the "Uncategorized" tail for somebody to file.
 *  - EMPTY-SAFE. No branches, or no ingredients, and every loop runs zero
 *    times — fresh installs and CI migrate cleanly.
 *  - BRANCH-AGNOSTIC. No branch id is hardcoded anywhere.
 */
final class DefaultIngredientCategories
{
    /**
     * The physical walk of a ramen kitchen, in the order somebody actually
     * counts it, which is why sort_order exists at all:
     *
     *   10  Sauces & Seasoning — the dry-store shelf by the back door, first
     *       thing in from the delivery entrance.
     *   20  Base & Mains       — the chiller/freezer cases right beside it;
     *       counted early and fast because the door is open while you count.
     *   30  Toppings           — the topping station on the line, in service
     *       order along the pass.
     *   40  Drinks             — the front-of-house fridge, passed on the way
     *       back out.
     *   50  Packaging          — the bowl stack in the store room, last stop.
     *
     * Steps of 10 (never 1..5) so a new shelf can be slotted between two
     * existing ones by writing ONE row instead of renumbering all of them.
     *
     * @var array<string, array{sort_order: int, ingredients: list<string>}>
     */
    public const CATEGORY_MAP = [
        'Sauces & Seasoning' => [
            'sort_order' => 10,
            'ingredients' => [
                'Chili Oil', 'Curry Sauce', 'Katsu Sauce', 'Kikoman',
                'Miso Paste', 'Sesame Oil', 'Shoyu', 'Tonkotsu / Soup',
            ],
        ],
        'Base & Mains' => [
            'sort_order' => 20,
            'ingredients' => ['Noodles', 'Gyoza', 'Tantanmen', 'Four Seasons'],
        ],
        'Toppings' => [
            'sort_order' => 30,
            'ingredients' => [
                'Black Garlic', 'Black Sesame', 'Chasu Pork', 'Kikurage',
                'Red Pepper', 'Seaweed Strips', 'Wakame', 'White Sesame',
            ],
        ],
        'Drinks' => [
            'sort_order' => 40,
            'ingredients' => ['Red Iced Tea'],
        ],
        'Packaging' => [
            'sort_order' => 50,
            'ingredients' => ['Take-out Bowls'],
        ],
    ];

    /**
     * Install the default walk on ONE branch and file the ingredients it
     * already knows about. A no-op on a branch that owns any category.
     *
     * @return bool true when the defaults were installed by this call
     */
    public static function seedBranch(int $branchId): bool
    {
        return DB::transaction(function () use ($branchId): bool {
            $alreadyManaged = DB::table('ingredient_categories')
                ->where('branch_id', $branchId)
                ->exists();

            if ($alreadyManaged) {
                return false;
            }

            $now = now();
            $categoryIds = self::insertCategories($branchId, $now);
            self::assignIngredients($branchId, $categoryIds, $now);

            return true;
        });
    }

    /**
     * Install the defaults on every branch that has none. Returns the number
     * of branches actually seeded.
     */
    public static function seedAllBranches(): int
    {
        $seeded = 0;

        foreach (DB::table('branches')->orderBy('id')->pluck('id') as $branchId) {
            $seeded += self::seedBranch((int) $branchId) ? 1 : 0;
        }

        return $seeded;
    }

    /**
     * Remove ONLY the rows this seeder inserted, in every branch.
     *
     * `is_seeded` is the identity, not the slug: a renamed category is still
     * ours, and an owner-created "Drinks" never is. `whereNotNull('branch_id')`
     * is belt and braces — a per-branch seeder must never delete a SHARED
     * (branch_id NULL) category that spans every branch.
     *
     * Ingredients are unlinked, never deleted. An ingredient the owner later
     * moved INTO a seeded category is unlinked too: the seeder cannot tell
     * that apart from its own writes, and a dangling id would break the
     * foreign key the schema migration is about to drop.
     */
    public static function removeSeeded(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('ingredient_categories')
                ->where('is_seeded', true)
                ->whereNotNull('branch_id')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            DB::table('ingredients')
                ->whereIn('ingredient_category_id', $ids)
                ->update(['ingredient_category_id' => null]);

            DB::table('ingredient_categories')->whereIn('id', $ids)->delete();
        });
    }

    /**
     * @return array<string, int> category name => id, for THIS branch
     */
    private static function insertCategories(int $branchId, Carbon $now): array
    {
        $ids = [];

        foreach (self::CATEGORY_MAP as $name => $definition) {
            $ids[$name] = (int) DB::table('ingredient_categories')->insertGetId([
                'branch_id' => $branchId,
                'name' => $name,
                'slug' => CategorySlug::for($name),
                'sort_order' => $definition['sort_order'],
                'is_active' => true,
                'is_seeded' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $categoryIds  category name => id, for THIS branch
     */
    private static function assignIngredients(int $branchId, array $categoryIds, Carbon $now): void
    {
        $lookup = self::ingredientLookup();

        $rows = DB::table('ingredients')
            ->where('branch_id', $branchId)
            ->whereNull('ingredient_category_id')
            ->select(['id', 'name'])
            ->get();

        /** @var array<string, list<int>> $byCategory */
        $byCategory = [];

        foreach ($rows as $row) {
            $categoryName = $lookup[self::normalise((string) $row->name)] ?? null;

            if ($categoryName === null) {
                continue;
            }

            $byCategory[$categoryName][] = (int) $row->id;
        }

        foreach ($byCategory as $categoryName => $ingredientIds) {
            DB::table('ingredients')
                ->whereIn('id', $ingredientIds)
                // Re-asserted at WRITE time, not only at read time.
                ->whereNull('ingredient_category_id')
                ->update([
                    'ingredient_category_id' => $categoryIds[$categoryName],
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * normalised ingredient name => category name.
     *
     * @return array<string, string>
     */
    private static function ingredientLookup(): array
    {
        $lookup = [];

        foreach (self::CATEGORY_MAP as $categoryName => $definition) {
            foreach ($definition['ingredients'] as $ingredientName) {
                $lookup[self::normalise($ingredientName)] = $categoryName;
            }
        }

        return $lookup;
    }

    /**
     * Case-insensitive, trimmed, internal whitespace runs collapsed, so
     * "  chili   oil " and "Chili Oil" are the same ingredient.
     *
     * Punctuation is deliberately NOT stripped: "Tonkotsu / Soup" and
     * "Tonkotsu Soup" are two different names, and only the operator can say
     * whether they are the same thing.
     */
    private static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
