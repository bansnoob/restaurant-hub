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

        $owner = User::updateOrCreate(
            ['email' => 'owner@restauranthub.local'],
            [
                'name' => 'System Owner',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $owner->syncRoles(['owner']);
    }
}
