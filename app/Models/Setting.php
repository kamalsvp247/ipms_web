<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    private const PROTOCOL_VERSION_CACHE_KEY = 'ivac:protocol_constants_version';

    protected static function booted(): void
    {
        static::saved(static function (): void {
            Cache::forget(self::PROTOCOL_VERSION_CACHE_KEY);
        });
    }

    protected $fillable = [
        'base_url',
        'max_retries',
        'captcha_fetch_seconds_before_window',
        'sign_in_retry_delay_ms',
        'otp_interval_delay_ms',
        'otp_timeout_ms',
        'otp_verify_retry_delay_ms',
        'reserve_slot_retry_delay_ms',
        'initiate_payment_retry_delay_ms',
        'rate_limit_safe_seconds',
        'window_start_time',
        'window_end_time',
        'account_lock_enabled',
        'account_lock_start_time',
        'account_lock_end_time',
        'reserve_slot_id',
        'payment_config_id',
        'reserve_request_meta',
        'ivac_endpoints',
        'turnstile_endpoints',
        'signin_429_proxy_url',
        'forgot_password_lead_seconds',
        'captcha_site_key',
        'captcha_page_url',
        'recaptcha_site_key',
        'recaptcha_page_url',
        'use_java_captcha_generator',
        'captcha_generator_interval_ms',
        'captcha_generator_max_tokens',
        'captcha_bot_secret',
        'captcha_shelf_life_ms',
        'captcha_daily_limit_per_account',
        'captcha_bd_proxy_url',
        'lightnode_api_token',
        'lightnode_region_code',
        'lightnode_zone_code',
        'lightnode_plan_code',
        'lightnode_image_uuid',
        'latest_jar_version',
        'censys_api_id',
        'censys_api_secret',
        'prestage_otp_timeout_ms',
    ];

    protected function casts(): array
    {
        return [
            'use_java_captcha_generator' => 'boolean',
            'account_lock_enabled' => 'boolean',
            'captcha_shelf_life_ms' => 'integer',
            'captcha_daily_limit_per_account' => 'integer',
            'ivac_endpoints' => 'array',
            'turnstile_endpoints' => 'array',
        ];
    }

    /**
     * Returns the singleton settings row (creates it if missing).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([]);
    }

    /**
     * The compiled-in IVAC endpoint paths + rotating headers. These mirror the Java bot's fallback
     * defaults (AppConfig getters) and seed settings.ivac_endpoints. reserveSlot/payment are templates —
     * their UUIDs are synced separately (reserve_slot_id, payment_config_id) and substituted by the bot.
     *
     * @return array<string, string>
     */
    public static function defaultIvacEndpoints(): array
    {
        return [
            'signin' => '/auth/v23-sign-in',
            'sendOtp' => '/forgot-password/sendOtp',
            'verifyOtp' => '/otp/verifySigninOtp',
            'uploadFile' => '/file/upload_file_v23',
            'bookingConfig' => '/appointment/appointment-booking-config',
            'getBookingConfig' => '/appointment/get-booking-config',
            'reserveSlot' => '/slots/{reserveSlotId}/reserve-slot',
            'payment' => '/payment/{paymentConfigId}/dg-epay/initiate',
            'profile' => '/profile',
            'profileSendOtp' => '/profile/sendOtp',
            'profileVerifyAndUpdate' => '/profile/verifyAndUpdate',
            'signinNavState' => '80d51dc5-af20-46fa-a7bb-e6a8f3f80065',
            'uploadRuntimeState' => 'v1.5a4c8831.9a53.47ed.b579.042a2c0cee5a',
        ];
    }

    /**
     * The stored IVAC endpoints merged over the compiled-in defaults, so every known key is always
     * present (a partially-populated column, or one missing keys the extractor never emits, still
     * resolves to a usable value).
     *
     * @return array<string, string>
     */
    public function ivacEndpointsWithDefaults(): array
    {
        $stored = is_array($this->ivac_endpoints) ? $this->ivac_endpoints : [];

        return array_merge(static::defaultIvacEndpoints(), array_filter(
            $stored,
            static fn ($value): bool => is_string($value) && $value !== '',
        ));
    }

    /**
     * An opaque hash of the bundle-derived IVAC constants the bot may swap while a race is running:
     * the endpoint paths/headers plus the reserve, payment and reserve-meta deployment values.
     *
     * The bot compares this against the version it holds to decide whether to refetch /api/config.
     * It is computed over the settings columns rather than over an analysis result, so a manual edit
     * on the IVAC Endpoints page bumps it exactly like a bundle sync does. Only these four values
     * feed it — topology settings must not trigger a live swap, since worker threads and tick
     * schedules are built from those at cycle start.
     */
    /**
     * Cached protocolConstantsVersion(), for the captcha poll: every parked bot hits that endpoint
     * every 500ms per account, and recomputing means a settings row read each time. Invalidated by
     * the saved() hook above, so a bundle sync or a manual IVAC Endpoints edit is visible at once.
     */
    public static function currentProtocolConstantsVersion(): string
    {
        return Cache::rememberForever(
            self::PROTOCOL_VERSION_CACHE_KEY,
            static fn (): string => static::instance()->protocolConstantsVersion(),
        );
    }

    public function protocolConstantsVersion(): string
    {
        // Hash exactly what /api/config delivers, not the raw column. The two diverged once the
        // export started merging over the compiled-in defaults: a key present only in the defaults
        // would change the payload every parked bot receives without moving this version, so the
        // mid-race hot-swap would never adopt it.
        $endpoints = $this->ivacEndpointsWithDefaults();
        ksort($endpoints);

        return substr(hash('sha256', (string) json_encode([
            'endpoints' => $endpoints,
            'reserveSlotId' => $this->reserve_slot_id,
            'paymentConfigId' => $this->payment_config_id,
            'reserveRequestMeta' => $this->reserve_request_meta,
        ])), 0, 16);
    }
}
