<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The restock claim mechanism reads inventory_movements by
 * (branch_id, reference_type IS NULL, id) and (ingredient_id, reference_type
 * IS NULL). The table only had (ingredient_id, moved_at) and
 * (branch_id, moved_at), so every count session start scanned a branch's whole
 * lifetime of already-claimed movements. Additive indexes only — no column or
 * type changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['branch_id', 'reference_type', 'id'], 'inv_moves_branch_ref_id_idx');
            $table->index(['ingredient_id', 'reference_type'], 'inv_moves_ingredient_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inv_moves_branch_ref_id_idx');
            $table->dropIndex('inv_moves_ingredient_ref_idx');
        });
    }
};
