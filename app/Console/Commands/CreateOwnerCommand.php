<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateOwnerCommand extends Command
{
    protected $signature = 'app:create-owner
                            {--email= : Owner email address}
                            {--name= : Owner display name}
                            {--password= : Owner password (min 8 chars). Will prompt securely if not provided.}';

    protected $description = 'Create or upgrade a user to owner. Also ensures owner/cashier roles exist.';

    public function handle(): int
    {
        Role::firstOrCreate(['name' => 'owner']);
        Role::firstOrCreate(['name' => 'cashier']);

        $email = $this->option('email') ?: $this->ask('Owner email');
        $name = $this->option('name') ?: $this->ask('Owner name', 'Owner');
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:120'],
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            if (! $this->confirm("User {$email} already exists. Update name/password and re-assign owner role?", true)) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }

            $existing->update([
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ]);
            $existing->syncRoles(['owner']);

            $this->info("✓ Updated {$email} and ensured owner role.");

            return self::SUCCESS;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('owner');

        $this->info("✓ Owner user created: {$email}");

        return self::SUCCESS;
    }
}
