<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Only seed an admin account on a fresh install (no users yet).
        // The password must be set manually after seeding — no default credentials.
        if (User::count() === 0) {
            $domain = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
            $adminEmail = "admin@{$domain}";
            $randomPassword = \Illuminate\Support\Str::password(20);

            User::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                'password' => Hash::make($randomPassword),
                'role' => 'super_admin',
            ]);

            $this->command->info("Admin created: {$adminEmail}");
            $this->command->warn("Temporary password: {$randomPassword}  — change this immediately!");
        }

        $this->call([
            SettingSeeder::class,
            AccountSeeder::class,
            BypassIpSeeder::class,
        ]);
    }
}
