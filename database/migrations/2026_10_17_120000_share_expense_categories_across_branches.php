<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes every expense category company-wide (branch_id NULL).
 *
 * Categories used to belong to the branch they were first typed at. With a second
 * branch that meant the new branch had none on the iPad, the web forms offered
 * every branch's, and the Edit Day dialog quietly cleared a category from another
 * branch. Special Expense categories have always been one list; these now match.
 *
 * Same-named categories (one slug at several branches) are merged into the oldest,
 * preferring one that is already shared. Their expenses are re-pointed first so no
 * expense loses its category, and the survivor stays active if any copy was.
 *
 * Data only. It does not change the schema: branch_id stays nullable, so a
 * branch-specific category remains possible, it is just no longer created.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('expense_categories')
                ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->get(['id', 'slug', 'is_active'])
                ->groupBy('slug');

            foreach ($groups as $copies) {
                $survivor = $copies->first();
                $duplicateIds = $copies->skip(1)->pluck('id')->all();

                if ($duplicateIds !== []) {
                    DB::table('expenses')
                        ->whereIn('expense_category_id', $duplicateIds)
                        ->update(['expense_category_id' => $survivor->id]);
                    DB::table('expense_categories')->whereIn('id', $duplicateIds)->delete();
                }

                DB::table('expense_categories')->where('id', $survivor->id)->update([
                    'branch_id' => null,
                    'is_active' => $copies->contains(fn ($copy) => (bool) $copy->is_active),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Not reversible: which branch each category came from, and which duplicates
     * were merged, is not recorded. Shared categories work at every branch, so
     * leaving them shared on rollback is safe.
     */
    public function down(): void
    {
        //
    }
};
