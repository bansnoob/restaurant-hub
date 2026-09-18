<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the cash for an expense actually came from.
 *
 * A day closure checks one thing: the physical cash in the drawer at close against
 * what the day's trading says should be there. That only works if every expense
 * charged against it was actually paid out of that drawer.
 *
 * It was not. On production, four closed days carry backdated rows the till could not
 * possibly have funded — a P17,200 "Cash Advance - Chicken" charged to a day that
 * counted P2,000, a P20,000 advance charged to a day that counted P9,000, both entered
 * days later. That cash is real and it did leave the business, but it left the safe,
 * not the drawer. Charging it to the day made expected_cash negative and turned a
 * correct close into a P23,188 surplus that never existed.
 *
 * 'drawer' is the default because the ordinary case — ice, water, a meryenda run — is
 * money lifted straight out of the till, and every row that existed before this column
 * did was recorded under that assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('paid_from', ['drawer', 'outside'])
                ->default('drawer')
                ->after('payment_method');

            // The day-closure path filters on branch + date + method + source.
            $table->index(['branch_id', 'expense_date', 'paid_from']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'expense_date', 'paid_from']);
            $table->dropColumn('paid_from');
        });
    }
};
