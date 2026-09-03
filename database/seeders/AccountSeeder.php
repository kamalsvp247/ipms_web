<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'super_admin')->first();

        if (! $admin) {
            return;
        }

        $accounts = [
            [
                'phone' => '01951253750',
                'email' => 'komalaj.p.k.a@gmail.com',
                'tag' => 'DOU',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01988895708',
                'email' => 'iva.c.d.h.k0.1.9@gmail.com',
                'tag' => 'DOU',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 1000,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01922568561',
                'email' => 'ivac.dhk019@gmail.com',
                'tag' => 'DOU',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01951274019',
                'email' => 'komala.j.pk.a@gmail.com',
                'tag' => 'DOU',
                'password' => 'Dhaka@2024',
                'max_retries' => 20,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01846481269',
                'email' => 'IVA.CDHK.0.1.2@gmail.com',
                'tag' => 'DDE4',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01842418213',
                'email' => 'IVA.CDHK.0.12@gmail.com',
                'tag' => 'DDE3',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01905397750',
                'email' => 'IVA.CDHK.012@gmail.com',
                'tag' => 'DDE1',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
            [
                'phone' => '01882697667',
                'email' => 'IVA.CDHK.01.2@gmail.com',
                'tag' => 'DDE2',
                'password' => 'Dhaka@2024',
                'max_retries' => 10,
                'retry_delay_ms' => 700,
                'slot_tick_shots' => 10,
                'slot_tick_interval_ms' => 1000,
                'is_active' => true,
                'status' => 'running',
            ],
        ];

        foreach ($accounts as $data) {
            Account::updateOrCreate(
                ['phone' => $data['phone']],
                array_merge($data, ['user_id' => $admin->id])
            );
        }
    }
}
