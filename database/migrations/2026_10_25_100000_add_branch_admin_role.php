<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The branch admin role: runs one branch from the website (and the POS), locked to
 * that branch. `owner` is the super admin and `cashier` the POS-only role; both
 * already exist (RolesAndAdminSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table(config('permission.table_names.roles', 'roles'))->updateOrInsert(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.roles', 'roles'))
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
