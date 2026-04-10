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
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'base_salary')) {
                $table->dropColumn('base_salary');
            }
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('payroll_entries', 'base_salary')) {
                $table->dropColumn('base_salary');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'base_salary')) {
                $table->decimal('base_salary', 12, 2)->nullable()->after('daily_rate');
            }
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_entries', 'base_salary')) {
                $table->decimal('base_salary', 12, 2)->default(0)->after('daily_rate');
            }
        });
    }
};
