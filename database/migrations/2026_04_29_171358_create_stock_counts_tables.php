<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('counted_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('total_value', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'counted_at']);
        });

        Schema::create('stock_count_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('previous_quantity', 14, 3)->default(0);
            $table->decimal('restocked_quantity', 14, 3)->default(0);
            $table->decimal('counted_quantity', 14, 3)->default(0);
            $table->decimal('consumption', 14, 3)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('line_value', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['stock_count_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_entries');
        Schema::dropIfExists('stock_counts');
    }
};
