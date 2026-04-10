<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_rules', 'required_clock_in_time')) {
                $table->time('required_clock_in_time')->default('09:00:00')->after('standard_daily_hours');
            }
            if (! Schema::hasColumn('payroll_rules', 'first_deduction_time')) {
                $table->time('first_deduction_time')->default('09:15:00')->after('required_clock_in_time');
            }
            if (! Schema::hasColumn('payroll_rules', 'first_deduction_amount')) {
                $table->decimal('first_deduction_amount', 10, 2)->default(0)->after('first_deduction_time');
            }
            if (! Schema::hasColumn('payroll_rules', 'second_deduction_time')) {
                $table->time('second_deduction_time')->default('09:30:00')->after('first_deduction_amount');
            }
            if (! Schema::hasColumn('payroll_rules', 'second_deduction_amount')) {
                $table->decimal('second_deduction_amount', 10, 2)->default(0)->after('second_deduction_time');
            }
            if (! Schema::hasColumn('payroll_rules', 'third_deduction_time')) {
                $table->time('third_deduction_time')->default('10:00:00')->after('second_deduction_amount');
            }
            if (! Schema::hasColumn('payroll_rules', 'third_deduction_percent')) {
                $table->decimal('third_deduction_percent', 5, 2)->default(0)->after('third_deduction_time');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table): void {
            foreach ([
                'required_clock_in_time',
                'first_deduction_time',
                'first_deduction_amount',
                'second_deduction_time',
                'second_deduction_amount',
                'third_deduction_time',
                'third_deduction_percent',
            ] as $column) {
                if (Schema::hasColumn('payroll_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
