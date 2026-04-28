<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Step 1: Add gcash to enum while keeping e_wallet
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'e_wallet', 'gcash', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'e_wallet', 'gcash', 'other') DEFAULT 'cash'");

        // Step 2: Migrate existing data
        DB::statement("UPDATE sales SET payment_method = 'gcash' WHERE payment_method = 'e_wallet'");
        DB::statement("UPDATE expenses SET payment_method = 'gcash' WHERE payment_method = 'e_wallet'");

        // Step 3: Remove e_wallet from enum
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'gcash', 'other') DEFAULT 'cash'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'e_wallet', 'gcash', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'e_wallet', 'gcash', 'other') DEFAULT 'cash'");

        DB::statement("UPDATE sales SET payment_method = 'e_wallet' WHERE payment_method = 'gcash'");
        DB::statement("UPDATE expenses SET payment_method = 'e_wallet' WHERE payment_method = 'gcash'");

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'e_wallet', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'e_wallet', 'other') DEFAULT 'cash'");
    }
};
