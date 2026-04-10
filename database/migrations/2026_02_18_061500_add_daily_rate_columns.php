<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->decimal('daily_rate', 10, 2)->nullable()->after('hourly_rate');
        });

        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->decimal('daily_rate', 10, 2)->default(0)->after('hourly_rate');
        });

        DB::statement('UPDATE employees SET daily_rate = ROUND(hourly_rate * 8, 2) WHERE daily_rate IS NULL AND hourly_rate IS NOT NULL');
        DB::statement('UPDATE payroll_entries SET daily_rate = ROUND(hourly_rate * 8, 2) WHERE daily_rate = 0 AND hourly_rate > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->dropColumn('daily_rate');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('daily_rate');
        });
    }
};
