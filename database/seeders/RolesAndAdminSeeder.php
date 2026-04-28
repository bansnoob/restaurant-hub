<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndAdminSeeder extends Seeder
{
    /**
     * Seed initial roles and a default owner account.
     */
    public function run(): void
    {
        $roles = ['owner', 'cashier'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $branch = \App\Models\Branch::where('code', 'MAIN001')->first();

        $owner = User::updateOrCreate(
            ['email' => 'owner@restauranthub.local'],
            [
                'name' => 'System Owner',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'branch_id' => $branch?->id,
            ]
        );

        $owner->syncRoles(['owner']);
    }
}
