<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function __construct(protected SettingService $service) {}

    public function index()
    {
        Gate::authorize('settings.read');

        return $this->service->get();
    }

    public function update(Request $request)
    {
        Gate::authorize('settings.write');

        $data = $request->validate([
            'base_url' => 'nullable|string|url',
            'max_retries' => 'integer|min:1',
            'captcha_fetch_seconds_before_window' => 'integer|min:0',
            'captcha_site_key' => 'nullable|string',
            'captcha_page_url' => 'nullable|string|url',
            'recaptcha_site_key' => 'nullable|string',
            'recaptcha_page_url' => 'nullable|string|url',
            'sign_in_retry_delay_ms' => 'integer|min:0',
            'otp_interval_delay_ms' => 'integer|min:100',
            'otp_timeout_ms' => 'integer|min:1000',
            'otp_verify_retry_delay_ms' => 'integer|min:0',
            'reserve_slot_retry_delay_ms' => 'integer|min:0',
            'initiate_payment_retry_delay_ms' => 'integer|min:0',
            'rate_limit_safe_seconds' => 'integer|min:0',
            'window_start_time' => 'string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'window_end_time' => 'string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'account_lock_enabled' => 'boolean',
            'account_lock_start_time' => 'nullable|string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'account_lock_end_time' => 'nullable|string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'reserve_slot_id' => 'nullable|string',
            'payment_config_id' => 'nullable|string',
            'reserve_request_meta' => 'nullable|string',
            'signin_429_proxy_url' => 'nullable|string',
            'forgot_password_lead_seconds' => 'integer|min:0',
            'prestage_otp_timeout_ms' => 'integer|min:1000',
            'use_java_captcha_generator' => 'boolean',
            'captcha_generator_interval_ms' => 'integer|min:1000',
            'captcha_generator_max_tokens' => 'integer|min:1',
            'captcha_bot_secret' => 'nullable|string',
            'captcha_shelf_life_ms' => 'integer|min:1000',
            'censys_api_id' => 'nullable|string',
            'censys_api_secret' => 'nullable|string',
        ]);

        return $this->service->update($data);
    }
}
