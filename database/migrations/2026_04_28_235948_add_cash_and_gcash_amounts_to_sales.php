<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('cash_amount', 10, 2)->nullable()->after('payment_method');
            $table->decimal('gcash_amount', 10, 2)->nullable()->after('cash_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'gcash_amount']);
        });
    }
};
