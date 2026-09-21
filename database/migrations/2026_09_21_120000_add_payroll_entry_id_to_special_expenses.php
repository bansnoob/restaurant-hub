<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a posted wage back to the payroll report it settles.
 *
 * Without it the two are unrelated facts. One wage already exists twice in
 * production — payroll_entries #12 at ₱4,790 still 'draft', and special_expenses
 * #8 at ₱5,160 paid in cash on the period end date — the same employee and the
 * same named period, ₱370 apart, with nothing to say they are the same money.
 *
 * nullOnDelete, not cascade: the wage was paid. Deleting the worksheet row it was
 * derived from must not delete the record of the payment. Unique because one
 * payroll report settles exactly once — it is what makes finalize idempotent at
 * the database level rather than only in the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_expenses', function (Blueprint $table) {
            $table->foreignId('payroll_entry_id')->nullable()->after('special_expense_category_id')
                ->constrained('payroll_entries')->nullOnDelete();
            $table->unique('payroll_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('special_expenses', function (Blueprint $table) {
            // Constraint, then index, then column — in that order, because each driver
            // refuses a different shortcut. MySQL backs the foreign key with this unique
            // index and will not drop the index while the constraint exists (errno 1553).
            // SQLite has no ALTER for constraints, but Laravel folds dropForeign() into
            // the table rebuild; drop the column without it and SQLite rejects the
            // result, since the table's own foreign-key clause then names a column that
            // is gone.
            $table->dropForeign(['payroll_entry_id']);
            $table->dropUnique(['payroll_entry_id']);
            $table->dropColumn('payroll_entry_id');
        });
    }
};
