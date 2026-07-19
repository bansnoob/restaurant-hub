<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the GCash report into a wallet ledger.
 *
 * `gcash_wallets` holds the per-branch starting point: the balance on the day tracking began.
 * Without it the running balance could only ever show movement since the system was installed,
 * not what is actually in the account.
 *
 * `gcash_entry_statuses` records whether an entry was seen on the wallet statement. It is a
 * side table keyed by (entry_type, entry_id) rather than a column on `sales`/`expenses` so that
 * reviewing money never rewrites the sales or expense rows themselves — the same separation the
 * adjustments table uses. Absence of a row means "not reviewed yet", so only exceptions and
 * explicit confirmations cost storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gcash_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            // The wallet balance on opening_date, before any entry recorded here.
            $table->decimal('opening_balance', 12, 2)->default(0);
            // Entries before this date are treated as already folded into opening_balance,
            // so switching the system on mid-life does not double-count history.
            $table->date('opening_date');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gcash_entry_statuses', function (Blueprint $table) {
            $table->id();
            // Plain string rather than a polymorphic class name: the value is stored for years
            // and must not break if a model is ever renamed or moved.
            $table->enum('entry_type', ['sale', 'expense']);
            $table->unsignedBigInteger('entry_id');
            $table->enum('status', ['accepted', 'declined']);
            $table->string('note', 200)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at');
            $table->timestamps();

            // One verdict per entry; re-reviewing updates it in place.
            $table->unique(['entry_type', 'entry_id']);
            $table->index(['status', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gcash_entry_statuses');
        Schema::dropIfExists('gcash_wallets');
    }
};
