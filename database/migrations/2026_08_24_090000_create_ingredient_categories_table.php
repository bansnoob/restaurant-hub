<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA ONLY — not one row of data is written here.
 *
 * The seed that classifies the live ingredients is a SEPARATE migration
 * (…_seed_ingredient_categories) so that a problem while writing production's
 * ingredient rows can be rolled back and re-run on its own, without also
 * dropping the table every later migration and both HTTP surfaces depend on.
 */
return new class extends Migration
{
    /** Named explicitly so down() can drop it by a name we control on every driver. */
    private const WALK_INDEX = 'ingredients_branch_category_index';

    /**
     * The v1 `ingredients` column list, in order — the shape this migration
     * must restore on SQLite, where a column named in a FOREIGN KEY clause
     * cannot be dropped in place and the table has to be rebuilt.
     *
     * @var list<string>
     */
    private const INGREDIENT_COLUMNS = [
        'id', 'branch_id', 'name', 'sku', 'unit', 'current_stock',
        'reorder_level', 'cost_per_unit', 'is_active', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        Schema::create('ingredient_categories', function (Blueprint $table): void {
            $table->id();
            // Nullable = a SHARED category visible to every branch, mirroring
            // expense_categories. Resolution is always
            // where(branch_id = X)->orWhereNull(branch_id).
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('slug', 140);
            // The PHYSICAL walk order (dry store → chiller → line → packaging).
            // Alphabetical cannot express it, which is the entire reason this
            // column exists. Seeded in steps of 10 so a new shelf can be slotted
            // between two existing ones without renumbering the rest.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Set ONLY by the default-category seeder, never by any HTTP write.
            // It is the seed's own identity marker: slug is user-editable (a
            // rename re-derives it), so rolling the seed back by slug would
            // both miss renamed rows and delete look-alike rows the owner
            // created. down() deletes exactly what up() inserted, no more.
            $table->boolean('is_seeded')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'slug']);
            $table->index(['branch_id', 'is_active']);
            $table->index(['branch_id', 'sort_order']);
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            // nullOnDelete, never cascade: deleting a category must never delete
            // stock. An orphaned ingredient becomes UNCATEGORISED and sorts at
            // the END of every list and every count session — never nowhere.
            $table->foreignId('ingredient_category_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('ingredient_categories')
                ->nullOnDelete();

            $table->index(['branch_id', 'ingredient_category_id'], self::WALK_INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ingredients', 'ingredient_category_id')) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->rebuildIngredientsWithoutCategory();
            } else {
                Schema::table('ingredients', function (Blueprint $table): void {
                    $table->dropForeign(['ingredient_category_id']);
                    $table->dropIndex(self::WALK_INDEX);
                    $table->dropColumn('ingredient_category_id');
                });
            }
        }

        Schema::dropIfExists('ingredient_categories');
    }

    /**
     * SQLite cannot drop a column that appears in a FOREIGN KEY clause — it
     * fails with `unknown column "ingredient_category_id" in foreign key
     * definition` — and Laravel's SQLite grammar compiles dropForeign(columns)
     * to nothing at all, so the clause is still there when dropColumn runs.
     * The portable answer is the one SQLite's own docs give: rebuild the table.
     */
    private function rebuildIngredientsWithoutCategory(): void
    {
        $columns = implode(', ', self::INGREDIENT_COLUMNS);

        Schema::withoutForeignKeyConstraints(function () use ($columns): void {
            DB::statement('DROP INDEX IF EXISTS '.self::WALK_INDEX);

            DB::statement(<<<'SQL'
                CREATE TABLE ingredients_rebuild (
                    id integer primary key autoincrement not null,
                    branch_id integer not null,
                    name varchar not null,
                    sku varchar,
                    unit varchar check ("unit" in ('g', 'kg', 'ml', 'l', 'pcs')) not null,
                    current_stock numeric not null default '0',
                    reorder_level numeric not null default '0',
                    cost_per_unit numeric not null default '0',
                    is_active tinyint(1) not null default '1',
                    created_at datetime,
                    updated_at datetime,
                    foreign key(branch_id) references branches(id) on delete cascade
                )
            SQL);

            DB::statement("INSERT INTO ingredients_rebuild ({$columns}) SELECT {$columns} FROM ingredients");
            DB::statement('DROP TABLE ingredients');
            DB::statement('ALTER TABLE ingredients_rebuild RENAME TO ingredients');
            DB::statement('CREATE UNIQUE INDEX ingredients_branch_id_sku_unique ON ingredients (branch_id, sku)');
            DB::statement('CREATE INDEX ingredients_branch_id_is_active_index ON ingredients (branch_id, is_active)');
        });
    }
};
