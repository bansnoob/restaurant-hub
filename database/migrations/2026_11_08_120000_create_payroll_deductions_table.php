<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deductions typed in by hand on a payroll report — a cash advance, a breakage —
 * each with the reason it was taken.
 *
 * Rows of their own rather than a figure folded into payroll_entries.deductions:
 * a draft report is rebuilt from attendance on every Generate and attendance edit,
 * and that rebuild rewrites `deductions` from scratch. Kept here, the manual part
 * is added back each time instead of being wiped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 200);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deductions');
    }
};
