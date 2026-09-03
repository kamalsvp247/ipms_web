<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\AgentSlot;
use App\Models\BypassIp;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class PublicConfigController extends Controller
{
    /** @return string[] */
    private function selectBypassIps(): array
    {
        $ips = BypassIp::query()->pluck('ip')->values();

        if ($ips->isEmpty()) {
            return [];
        }

        if ($ips->count() >= 10) {
            return $ips->all();
        }

        $count = $ips->count();
        $result = [];
        for ($i = 0; $i < 10; $i++) {
            $result[] = $ips[$i % $count];
        }

        return $result;
    }

    public function __invoke(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = Setting::instance();

        // Distributed mode: filter accounts by slot if Bearer token matches an agent slot.
        $apiKey = $request->bearerToken();
        $slot = $apiKey ? AgentSlot::where('api_key', $apiKey)->with('bypassIp')->first() : null;

        // Optional: filter accounts to a specific user by gmail param
        $gmail = $request->query('gmail');
        $user = $gmail ? User::where('email', $gmail)->first() : null;

        $accountQuery = Account::where('is_active', true)
            ->where('status', 'running')
            ->eligibleForBot();

        if ($slot) {
            // Distributed VPS mode — only return accounts assigned to this slot.
            $accountQuery->where('agent_slot_id', $slot->id);
        } elseif ($user && ($visibleIds = $user->visibleUserIds()) !== null) {
            $accountQuery->whereIn('user_id', $visibleIds);
        }

        $accounts = $accountQuery->with('latestSession')->get();

        return response()->json([
            'baseUrl' => rtrim($settings->base_url ?? '', '/'),
            'ipmsWebBaseUrl' => rtrim(config('app.url'), '/'),
            'otpApiBaseUrl' => rtrim(config('app.url'), '/').'/api',
            'captchaGetUrl' => config('captcha.captcha_get_url'),
            'maxRetries' => $settings->max_retries,
            'captchaFetchSecondsBeforeWindow' => $settings->captcha_fetch_seconds_before_window,
            'signInRetryDelayMs' => $settings->sign_in_retry_delay_ms,
            'otpIntervalDelayMs' => $settings->otp_interval_delay_ms,
            'otpTimeoutMs' => $settings->otp_timeout_ms,
            'reserveSlotRetryDelayMs' => $settings->reserve_slot_retry_delay_ms,
            'initiatePaymentRetryDelayMs' => $settings->initiate_payment_retry_delay_ms,
            // rate_limit_safe_seconds (seconds) → slot429BackoffMs (ms) consumed by slot reservation service
            'slot429BackoffMs' => ($settings->rate_limit_safe_seconds ?? 15) * 1000,
            'windowStartTime' => $settings->window_start_time,
            'windowEndTime' => $settings->window_end_time,
            'forgotPasswordLeadSeconds' => $settings->forgot_password_lead_seconds,
            'captchaSiteKey' => $settings->captcha_site_key,
            'captchaPageUrl' => $settings->captcha_page_url,
            'useJavaCaptchaGenerator' => $settings->use_java_captcha_generator,
            'captchaGeneratorIntervalMs' => $settings->captcha_generator_interval_ms,
            'captchaBotSecret' => $settings->captcha_bot_secret,
            'captchaShelfLifeMs' => $settings->captcha_shelf_life_ms ?? 20000,
            'bypassIps' => $this->selectBypassIps(),
            'reserveSlotId' => $settings->reserve_slot_id,
            'paymentConfigId' => $settings->payment_config_id,
            'reserveRequestMeta' => $settings->reserve_request_meta,
            // IVAC endpoint paths + rotating headers, bundle-extracted so a redeploy rotation is
            // picked up here with no Java edit or JAR rebuild (bot falls back to compiled-in defaults).
            'endpoints' => $settings->ivacEndpointsWithDefaults(),
            // Lets the bot detect a mid-window rotation and hot-swap these constants without a restart.
            'configVersion' => $settings->protocolConstantsVersion(),
            'signin429ProxyUrl' => $settings->signin_429_proxy_url,
            // Every IVAC centre, so booking config can rotate onto another one when IVAC answers
            // 400 "Invalid High Commission." for the account's configured centre.
            'bookingCityOptions' => \App\Support\IvacBookingCities::options(),

            'accounts' => $accounts->map(fn ($account) => [
                'id' => $account->id,
                'phone' => $account->phone,
                'email' => $account->email,
                'tag' => $account->tag,
                'appointmentId' => $account->appointment_id,
                'appointmentDates' => $account->appointment_dates ?? [],
                'pdfUploaded' => $account->pdfUploadedToday(),
                'bookingConfigured' => $account->bookingConfiguredToday(),
                'bookingMission' => \App\Support\IvacBookingCities::resolve($account->booking_city)['mission'] ?? null,
                'bookingIvacCenter' => \App\Support\IvacBookingCities::resolve($account->booking_city)['ivacCenter'] ?? null,
                'maxRetries' => $account->max_retries,
                'signinTickShots' => $account->signin_tick_shots ?? 10,
                'signinTickIntervalMs' => $account->signin_tick_interval_ms ?? 1000,
                'singleSignIn' => (bool) $account->single_sign_in,
                'retryDelayMs' => $account->retry_delay_ms ?? 5000,
                'otpTickShots' => $account->otp_tick_shots ?? 10,
                'otpTickIntervalMs' => $account->otp_tick_interval_ms ?? 1000,
                'slotTickShots' => $account->slot_tick_shots ?? 10,
                'slotTickIntervalMs' => $account->slot_tick_interval_ms ?? 1000,
                'paymentTickShots' => $account->payment_tick_shots ?? 10,
                'paymentTickIntervalMs' => $account->payment_tick_interval_ms ?? 1000,
                'accessToken' => $this->validJwtToken($account->latestSession),
                'jwtExpiresAtMs' => $this->jwtExpiresAtMs($account->latestSession),
                'isOtpVerified' => $this->isOtpVerifiedForSession($account->latestSession),
                'signinRequestId' => $account->latestSession?->request_id,
                'signinServerTimeMs' => $this->signinServerTimeMs($account->latestSession),
            ]),
        ]);
    }

    private function isOtpVerifiedForSession(?AccountSession $session): bool
    {
        if ($session === null || $session->jwt_token === null) {
            return false;
        }

        if ($session->jwt_expires_at === null || $session->jwt_expires_at->isPast()) {
            return false;
        }

        return (bool) $session->is_otp_verified;
    }

    private function signinServerTimeMs(?AccountSession $session): ?int
    {
        if ($session?->signed_in_server_time === null) {
            return null;
        }

        return (int) \Carbon\Carbon::parse($session->signed_in_server_time)->getTimestampMs();
    }

    private function validJwtToken(?AccountSession $session): ?string
    {
        if ($session === null || $session->jwt_token === null) {
            return null;
        }

        if ($session->jwt_expires_at === null || $session->jwt_expires_at->isPast()) {
            return null;
        }

        return $session->jwt_token;
    }

    private function jwtExpiresAtMs(?AccountSession $session): ?int
    {
        if ($session === null || $session->jwt_expires_at === null || $session->jwt_expires_at->isPast()) {
            return null;
        }

        return $session->jwt_expires_at->getTimestampMs();
    }
}
