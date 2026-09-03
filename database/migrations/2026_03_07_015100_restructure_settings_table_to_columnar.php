<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capture existing key-value pairs before dropping the table
        $existing = [];
        if (Schema::hasTable('settings')) {
            $existing = DB::table('settings')->pluck('value', 'key')->toArray();

            // JSON-decode each value since they were stored as JSON
            foreach ($existing as $k => $v) {
                $decoded = json_decode($v, true);
                $existing[$k] = $decoded ?? $v;
            }
        }

        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->nullable();
            $table->string('firebase_otp_url')->nullable();
            $table->string('maven_path')->nullable();
            $table->string('captcha_page_url')->nullable();
            $table->string('captcha_site_key')->nullable();
            $table->unsignedInteger('default_timeout_ms')->default(30000);
            $table->unsignedInteger('max_retries')->default(100);
            $table->unsignedInteger('lane_warmup_0_seconds')->default(40);
            $table->unsignedInteger('lane_warmup_1_seconds')->default(35);
            $table->unsignedInteger('lane_warmup_2_seconds')->default(30);
            $table->unsignedInteger('captcha_fetch_seconds_before_window')->default(30);
            $table->unsignedInteger('sign_in_retry_delay_ms')->default(2000);
            $table->unsignedInteger('otp_interval_delay_ms')->default(3000);
            $table->unsignedInteger('otp_timeout_ms')->default(120000);
            $table->unsignedInteger('otp_verify_retry_delay_ms')->default(2000);
            $table->unsignedInteger('reserve_slot_retry_delay_ms')->default(2000);
            $table->unsignedInteger('initiate_payment_retry_delay_ms')->default(2000);
            $table->unsignedInteger('rate_limit_safe_seconds')->default(15);
            $table->string('window_start_time')->default('00:00');
            $table->string('window_end_time')->default('23:59');
            $table->timestamps();
        });

        $defaultMavenPath = str_starts_with(PHP_OS, 'WIN')
            ? 'C:\Users\sulai\AppData\Local\Programs\IntelliJ IDEA Community Edition\plugins\maven\lib\maven3\bin\mvn.cmd'
            : '/usr/bin/mvn';

        DB::table('settings')->insert([
            'base_url' => $existing['base_url'] ?? null,
            'firebase_otp_url' => $existing['firebase_otp_url'] ?? null,
            'maven_path' => $existing['maven_path'] ?? $defaultMavenPath,
            'captcha_page_url' => $existing['captcha_page_url'] ?? null,
            'captcha_site_key' => $existing['captcha_site_key'] ?? null,
            'default_timeout_ms' => 30000,
            'max_retries' => isset($existing['max_retries']) ? (int) $existing['max_retries'] : 100,
            'lane_warmup_0_seconds' => 40,
            'lane_warmup_1_seconds' => 35,
            'lane_warmup_2_seconds' => 30,
            'captcha_fetch_seconds_before_window' => 30,
            'sign_in_retry_delay_ms' => isset($existing['signin_retry_delay_ms']) ? (int) $existing['signin_retry_delay_ms'] : 2000,
            'otp_interval_delay_ms' => isset($existing['otp_poll_interval_ms']) ? (int) $existing['otp_poll_interval_ms'] : 3000,
            'otp_timeout_ms' => isset($existing['otp_poll_timeout_ms']) ? (int) $existing['otp_poll_timeout_ms'] : 120000,
            'otp_verify_retry_delay_ms' => 2000,
            'reserve_slot_retry_delay_ms' => isset($existing['slot_retry_delay_ms']) ? (int) $existing['slot_retry_delay_ms'] : 2000,
            'initiate_payment_retry_delay_ms' => isset($existing['payment_retry_delay_ms']) ? (int) $existing['payment_retry_delay_ms'] : 2000,
            'rate_limit_safe_seconds' => 15,
            'window_start_time' => $existing['allowed_start_time'] ?? '00:00',
            'window_end_time' => $existing['allowed_end_time'] ?? '23:59',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });
    }
};
