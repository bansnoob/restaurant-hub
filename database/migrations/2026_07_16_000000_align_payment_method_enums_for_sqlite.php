<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `remove_card_from_payment_methods` and `rename_e_wallet_to_gcash_in_payment_methods`
 * migrations both return early on SQLite, because they drive the change with raw MySQL
 * `ALTER TABLE ... MODIFY COLUMN` statements. SQLite therefore still carries the original
 * pre-rename CHECK constraints and rejects `payment_method = 'gcash'` outright.
 *
 * The test suite runs on SQLite, so without this the schema under test disagrees with
 * production and no GCash row can be inserted at all. This realigns SQLite using the
 * portable schema builder. MySQL already reached the correct enum via the two migrations
 * above and is left untouched.
 *
 * Two SQLite-specific hazards drive the shape of this file:
 *
 *  1. A CHECK constraint is enforced during the rewrite, so the legacy values must be moved
 *     while BOTH the old and new values are permitted. Hence widen -> move data -> narrow,
 *     mirroring the three-step dance the MySQL migration performs.
 *  2. Laravel implements ->change() on SQLite by rebuilding the table, and only re-emits
 *     CHECK constraints for columns redeclared in the closure. Every other enum column on
 *     the table must therefore be restated or it silently degrades to an unconstrained
 *     varchar — which would defeat the whole point of aligning with production.
 */
return new class extends Migration
{
    /** Every enum column on `sales`, with its production values and default. */
    private const SALES_ENUMS = [
        'order_type' => [['dine_in', 'takeout', 'delivery'], 'dine_in'],
        'status' => [['open', 'completed', 'voided', 'refunded'], 'completed'],
    ];

    /** Every enum column on `expenses`, with its production values and default. */
    private const EXPENSES_ENUMS = [
        'status' => [['draft', 'approved', 'voided'], 'approved'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // 1. Widen to a superset so legacy and target values are both valid.
        $this->setPaymentMethod('sales', ['cash', 'card', 'e_wallet', 'gcash', 'mixed', 'unpaid']);
        $this->setPaymentMethod('expenses', ['cash', 'bank_transfer', 'card', 'e_wallet', 'gcash', 'other']);

        // 2. Move the legacy values across, matching the MySQL migrations.
        DB::table('sales')->where('payment_method', 'e_wallet')->update(['payment_method' => 'gcash']);
        DB::table('sales')->where('payment_method', 'card')->update(['payment_method' => 'cash']);
        DB::table('expenses')->where('payment_method', 'e_wallet')->update(['payment_method' => 'gcash']);
        DB::table('expenses')->where('payment_method', 'card')->update(['payment_method' => 'other']);

        // 3. Narrow to the production enum.
        $this->setPaymentMethod('sales', ['cash', 'gcash', 'mixed', 'unpaid']);
        $this->setPaymentMethod('expenses', ['cash', 'bank_transfer', 'gcash', 'other']);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->setPaymentMethod('sales', ['cash', 'card', 'e_wallet', 'gcash', 'mixed', 'unpaid']);
        $this->setPaymentMethod('expenses', ['cash', 'bank_transfer', 'card', 'e_wallet', 'gcash', 'other']);

        DB::table('sales')->where('payment_method', 'gcash')->update(['payment_method' => 'e_wallet']);
        DB::table('expenses')->where('payment_method', 'gcash')->update(['payment_method' => 'e_wallet']);

        $this->setPaymentMethod('sales', ['cash', 'card', 'e_wallet', 'mixed', 'unpaid']);
        $this->setPaymentMethod('expenses', ['cash', 'bank_transfer', 'card', 'e_wallet', 'other']);
    }

    /**
     * Repoint `payment_method` at the given values, restating the table's other enum
     * columns so the SQLite table rebuild does not drop their CHECK constraints.
     *
     * @param  array<int, string>  $methods
     */
    private function setPaymentMethod(string $table, array $methods): void
    {
        $companions = $table === 'sales' ? self::SALES_ENUMS : self::EXPENSES_ENUMS;

        Schema::table($table, function (Blueprint $blueprint) use ($methods, $companions) {
            $blueprint->enum('payment_method', $methods)->default('cash')->change();

            foreach ($companions as $column => [$values, $columnDefault]) {
                $blueprint->enum($column, $values)->default($columnDefault)->change();
            }
        });
    }
};
