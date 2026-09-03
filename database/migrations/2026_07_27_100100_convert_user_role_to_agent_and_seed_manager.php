<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Introduces the three-tier hierarchy: super_admin > manager > agent.
 *
 * The legacy 'user' role was already an agent in everything but name, so it is renamed in
 * place. A manager (Jewel) is seeded and every pre-existing agent is placed under them, so
 * the manager scope is populated rather than empty on first login. Admin accounts are left
 * untouched and unparented.
 */
return new class extends Migration
{
    private const MANAGER_EMAIL = 'jewel@ipms.senda.fit';

    public function up(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'agent']);

        $managerId = DB::table('users')->where('email', self::MANAGER_EMAIL)->value('id');

        if ($managerId === null) {
            $managerId = DB::table('users')->insertGetId([
                'name' => 'Jewel',
                'email' => self::MANAGER_EMAIL,
                'role' => 'manager',
                'parent_id' => null,
                'password' => Hash::make('jewel@2026@#'),
                'plain_password' => Crypt::encrypt('jewel@2026@#'),
                'is_approved' => true,
                'approved_at' => now(),
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('users')->where('id', $managerId)->update([
                'role' => 'manager',
                'parent_id' => null,
                'updated_at' => now(),
            ]);
        }

        DB::table('users')
            ->where('role', 'agent')
            ->whereNull('parent_id')
            ->update(['parent_id' => $managerId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'agent')->update(['role' => 'user', 'parent_id' => null]);
        DB::table('users')->where('email', self::MANAGER_EMAIL)->delete();
    }
};
