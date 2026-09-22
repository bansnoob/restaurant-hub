<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets each late tier be a fixed amount OR a percentage of the daily rate.
 *
 * The shape used to be hardcoded: tiers one and two were pesos, only the third was a
 * percentage. An owner who wanted the deduction expressed as a proportion of the day
 * had exactly one tier to put it on — which is why the live rule reads ₱40 / ₱80 / 50%.
 * The percentage sits on the third tier because that is the only place it fits, not
 * because the first two were meant to be flat.
 *
 * The defaults reproduce the old hardcoding exactly, so every existing rule keeps
 * computing what it computed before without being touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->enum('first_deduction_type', ['amount', 'percent'])
                ->default('amount')->after('first_deduction_amount');
            $table->decimal('first_deduction_percent', 5, 2)
                ->default(0)->after('first_deduction_type');

            $table->enum('second_deduction_type', ['amount', 'percent'])
                ->default('amount')->after('second_deduction_amount');
            $table->decimal('second_deduction_percent', 5, 2)
                ->default(0)->after('second_deduction_type');

            $table->enum('third_deduction_type', ['amount', 'percent'])
                ->default('percent')->after('third_deduction_percent');
            $table->decimal('third_deduction_amount', 10, 2)
                ->default(0)->after('third_deduction_type');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->dropColumn([
                'first_deduction_type', 'first_deduction_percent',
                'second_deduction_type', 'second_deduction_percent',
                'third_deduction_type', 'third_deduction_amount',
            ]);
        });
    }
};
