<?php

namespace Database\Seeders;

use App\Models\CaptchaProvider;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::updateOrCreate(['id' => 1], [
            'base_url' => 'https://api.ivacbd.com/iams/api/v1',
            'captcha_page_url' => 'https://appointment.ivacbd.com/',
            'captcha_site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
            'recaptcha_site_key' => '6LdyiGMsAAAAAJefesdWMjxy8pu3A3DmbeJkkdUl',
            'recaptcha_page_url' => 'https://appointment.ivacbd.com/',
            'max_retries' => 10,
            'captcha_fetch_seconds_before_window' => 30,
            'sign_in_retry_delay_ms' => 500,
            'otp_interval_delay_ms' => 1000,
            'otp_timeout_ms' => 20000,
            'reserve_slot_retry_delay_ms' => 500,
            'initiate_payment_retry_delay_ms' => 4000,
            'rate_limit_safe_seconds' => 15,
            'window_start_time' => '17:00:00',
            'window_end_time' => '23:59:00',
            'forgot_password_lead_seconds' => 45,
            'use_java_captcha_generator' => true,
            'captcha_generator_interval_ms' => 5000,
            'captcha_generator_max_tokens' => 5,
            'captcha_bot_secret' => '59f5ad67-e68d-474b-9dbf-7aff211726d1',
        ]);

        $superAdmin = User::where('role', 'super_admin')->first();

        if ($superAdmin === null) {
            $temporaryPassword = env('SEED_ADMIN_PASSWORD') ?: Str::password(24);
            $adminEmail = env('SEED_ADMIN_EMAIL', 'admin@localhost');

            $superAdmin = User::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                'password' => Hash::make($temporaryPassword),
                'role' => User::ROLE_SUPER_ADMIN,
                'is_approved' => true,
                'approved_at' => now(),
            ]);

            $this->command?->info("Admin created: {$adminEmail}");
            $this->command?->warn('Temporary password: '.$temporaryPassword);
        }

        $providers = [
            [
                'name' => 'captchaai',
                'enabled' => true,
                'api_key' => 'a2739d70bd6dc5632093448135353e45',
                'solver_threads' => 2,
                'params' => [
                    'siteKey' => '0x4AAAAAACghKkJHL1t7UkuZ',
                    'pageUrl' => 'https://appointment.ivacbd.com/',
                ],
            ],
            [
                'name' => 'capmonster',
                'enabled' => true,
                'api_key' => '8f755be1ffca9f13069c7cea448358b4',
                'solver_threads' => 2,
                'params' => [
                    'siteKey' => '0x4AAAAAACghKkJHL1t7UkuZ',
                    'pageUrl' => 'https://appointment.ivacbd.com/',
                ],
            ],
        ];

        foreach ($providers as $provider) {
            CaptchaProvider::updateOrCreate(
                ['name' => $provider['name']],
                array_merge($provider, ['user_id' => $superAdmin->id])
            );
        }
    }
}
