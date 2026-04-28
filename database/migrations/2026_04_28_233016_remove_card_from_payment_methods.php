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

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'e_wallet', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'e_wallet', 'other') DEFAULT 'cash'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'card', 'e_wallet', 'mixed', 'unpaid') DEFAULT 'cash'");
        DB::statement("ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash', 'bank_transfer', 'card', 'e_wallet', 'other') DEFAULT 'cash'");
    }
};
