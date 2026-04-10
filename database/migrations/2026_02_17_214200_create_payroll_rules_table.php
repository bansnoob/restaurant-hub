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
        Schema::create('payroll_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->decimal('standard_daily_hours', 5, 2)->default(8);
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(30);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.25);
            $table->unsignedSmallInteger('undertime_rounding_minutes')->default(15);
            $table->decimal('late_penalty_per_minute', 10, 4)->default(0);
            $table->decimal('absent_penalty_days', 5, 2)->default(1);
            $table->boolean('holiday_paid')->default(true);
            $table->boolean('leave_paid')->default(false);
            $table->timestamps();

            $table->unique('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_rules');
    }
};
