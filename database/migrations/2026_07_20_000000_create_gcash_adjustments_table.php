<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correcting entries against GCash takings, kept deliberately out of `sales` and `expenses`.
 *
 * A correction is not revenue and not a cost, so writing it to either table would distort the
 * Sales page (order counts, average order value) or the expense reporting. Keeping it separate
 * also means DayClosureController::computeTotals() — which reads `sales` — cannot see it, so an
 * adjustment can never invalidate the gcash_sales_total snapshot a closed day was signed off
 * with. That is why adjustments need no closed-day guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gcash_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            // Signed: negative deducts from GCash takings. The column allows either direction
            // so a future "add back" correction needs no migration; the UI writes deductions.
            $table->decimal('amount', 12, 2);
            $table->string('reason', 200);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gcash_adjustments');
    }
};
