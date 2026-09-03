<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_export_returns_json(): void
    {
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $this->seed(\Database\Seeders\CaptchaProviderSeeder::class);
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/config/export');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'baseUrl',
                'firebaseOtpUrl',
                'maxRetries',
                'laneWarmup0Seconds',
                'laneWarmup1Seconds',
                'laneWarmup2Seconds',
                'captchaFetchSecondsBeforeWindow',
                'signInRetryDelayMs',
                'otpIntervalDelayMs',
                'otpTimeoutMs',
                'otpVerifyRetryDelayMs',
                'reserveSlotRetryDelayMs',
                'initiatePaymentRetryDelayMs',
                'rateLimitSafeSeconds',
                'windowStartTime',
                'windowEndTime',
                'captchaProviders' => [
                    '*' => ['name', 'enabled', 'apiKey', 'solverThreads'],
                ],
                'accounts' => [],
            ]);
    }

    public function test_config_export_reflects_settings_values(): void
    {
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $this->seed(\Database\Seeders\CaptchaProviderSeeder::class);
        $user = User::factory()->create(['role' => 'user']);

        Setting::instance()->update([
            'max_retries' => 50,
            'rate_limit_safe_seconds' => 20,
            'window_start_time' => '08:00:00',
            'window_end_time' => '10:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/config/export');

        $response->assertStatus(200)
            ->assertJsonPath('maxRetries', 50)
            ->assertJsonPath('rateLimitSafeSeconds', 20)
            ->assertJsonPath('windowStartTime', '08:00:00')
            ->assertJsonPath('windowEndTime', '10:00:00');
    }

    public function test_admin_config_export_includes_all_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $user = User::factory()->create(['role' => 'user']);
        $account = $user->accounts()->create([
            'phone' => '1234567890',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/config/export');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'accounts');
    }

    public function test_admin_config_export_excludes_accounts_without_a_primary_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $user = User::factory()->create(['role' => 'user']);
        // No PDFs at all — must be excluded.
        $user->accounts()->create([
            'phone' => '1111111111',
            'password' => 'secret',
            'is_active' => true,
        ]);
        // Has a PDF, but none flagged primary — must be excluded.
        $noPrimary = $user->accounts()->create([
            'phone' => '2222222222',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $noPrimary->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/config/export');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'accounts');
    }

    public function test_bot_config_includes_account_appointment_dates(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $user = User::factory()->create(['role' => 'user']);
        $account = $user->accounts()->create([
            'phone' => '1234567890',
            'password' => 'secret',
            'appointment_dates' => ['2026-08-15', '2026-08-16'],
            'is_active' => true,
            'status' => 'running',
        ]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        // The bot fetches /api/config (PublicConfigController), not /api/config/export.
        $response = $this->getJson('/api/config');

        $response->assertStatus(200)
            ->assertJsonPath('accounts.0.appointmentDates', ['2026-08-15', '2026-08-16']);
    }

    public function test_bot_config_includes_reserve_slot_id(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        Setting::instance()->update(['reserve_slot_id' => 'ccd3dd63-e781-48ba-a48d-c65eaa4fc663']);

        $response = $this->getJson('/api/config');

        $response->assertStatus(200)
            ->assertJsonPath('reserveSlotId', 'ccd3dd63-e781-48ba-a48d-c65eaa4fc663');
    }

    public function test_bot_config_includes_payment_config_id_and_reserve_request_meta(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        Setting::instance()->update([
            'payment_config_id' => 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f',
            'reserve_request_meta' => 'windos.s',
        ]);

        $response = $this->getJson('/api/config');

        $response->assertStatus(200)
            ->assertJsonPath('paymentConfigId', 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f')
            ->assertJsonPath('reserveRequestMeta', 'windos.s');
    }
}
