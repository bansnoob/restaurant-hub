<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Special expenses: owner-entered monthly overhead (rent, electricity, water,
 * internet, permits) that is NOT part of daily operations.
 *
 * Why a separate table instead of a flag on `expenses`:
 *
 * 1. The `expenses` table is aggregated at a dozen sites across four controllers
 *    — DayClosureController::computeTotals(), its byte-identical twin in
 *    Api/V1/DayClosureController, DashboardController and ExpenseController —
 *    and the predicates are hand-copied, never shared. A flag column would make
 *    correctness depend on remembering a `where` clause at every one of them,
 *    forever. A month's rent leaking into cash_expenses_total throws that day's
 *    drawer variance by the full rent amount and the closing cashier wears it.
 *    Rows that are not in the table cannot leak.
 *
 * 2. `expenses.branch_id` is NOT NULL with restrictOnDelete, so a company-wide
 *    cost has no valid home there — it would have to be pinned to one arbitrary
 *    branch, skewing that branch's expected_cash against every other branch.
 *    Here branch_id is nullable and means "the whole business".
 *
 * 3. Cashiers can write to `expenses` through the mobile API (routes/api.php,
 *    role:owner|cashier) while the web module is owner-only. Overhead has no API
 *    surface at all, so a cashier cannot mint one.
 *
 * `period_month` is the month the cost BELONGS to (stored as the 1st), which is
 * not the day it was paid — an August electricity bill settled on September 10th
 * is August overhead. `paid_date` records the settlement separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('special_expenses', function (Blueprint $table) {
            $table->id();
            // Nullable on purpose: null means the cost covers the whole business
            // rather than one location. See the class docblock.
            //
            // restrictOnDelete, not nullOnDelete: NULL is a meaningful value here,
            // so deleting a branch would not clear these rows — it would silently
            // RELABEL that location's whole rent history as a company-wide cost,
            // with no error and no way back. Restrict makes BranchController::destroy
            // refuse instead, matching what `expenses` already does.
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('special_expense_category_id')->nullable()
                ->constrained('special_expense_categories')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_month');
            $table->date('paid_date')->nullable();
            $table->string('description', 200)->nullable();
            $table->string('vendor_name', 140)->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'gcash', 'other'])->default('cash');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'period_month']);
            $table->index('period_month');
        });
    }

    public function down(): void
    {
        // Child before parent: special_expenses holds the FK.
        Schema::dropIfExists('special_expenses');
        Schema::dropIfExists('special_expense_categories');
    }
};
