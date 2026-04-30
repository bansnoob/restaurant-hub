<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('closed_at_date');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at');

            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('cash_sales_total', 12, 2)->default(0);
            $table->decimal('mixed_cash_total', 12, 2)->default(0);
            $table->decimal('gcash_sales_total', 12, 2)->default(0);
            $table->decimal('cash_expenses_total', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2)->default(0);
            $table->decimal('variance', 12, 2)->default(0);

            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('expense_count')->default(0);
            $table->unsignedInteger('auto_clocked_out_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'closed_at_date']);
            $table->index('closed_at_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_closures');
    }
};
