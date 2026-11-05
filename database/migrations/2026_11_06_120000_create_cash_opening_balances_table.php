<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The starting point of a branch's Cash on Hand: the cash the owner was holding on
 * opening_date, counted by hand.
 *
 * The Cash Report could only ever say what the tills took over a date range, never what
 * should be in hand, because nothing recorded the cash already held when tracking began.
 * Same shape as gcash_wallets, which solves the same problem for the GCash account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            // The cash held on opening_date, before that day's takings were added to it.
            $table->decimal('opening_balance', 12, 2)->default(0);
            // Closed days and cash paid out before this date are treated as already folded
            // into opening_balance, so they are not counted twice.
            $table->date('opening_date');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_opening_balances');
    }
};
