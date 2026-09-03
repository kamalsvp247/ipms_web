<?php

namespace App\Services;

use App\Models\AgentSlot;
use App\Models\User;

class ConfigExportService
{
    public function __construct(
        protected SettingService $settingService
    ) {}

    public function export(User $user): array
    {
        return $this->buildExport(function () use ($user) {
            $query = \App\Models\Account::where('is_active', true)->eligibleForBot();

            // A manager's export spans their own accounts plus their agents'.
            if (($visibleIds = $user->visibleUserIds()) !== null) {
                $query->whereIn('user_id', $visibleIds);
            }

            return $query->get();
        });
    }

    public function exportForSlot(AgentSlot $slot): array
    {
        $slot->markOnline();

        return $this->buildExport(function () use ($slot) {
            return $slot->accounts()->where('is_active', true)->eligibleForBot()->get();
        });
    }

    private function buildExport(callable $accountsResolver): array
    {
        $settings = $this->settingService->get();
        $accounts = $accountsResolver();

        return [
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
            'slot429BackoffMs' => ($settings->rate_limit_safe_seconds ?? 15) * 1000,
            'captchaShelfLifeMs' => $settings->captcha_shelf_life_ms ?? 20000,
            'forgotPasswordLeadSeconds' => $settings->forgot_password_lead_seconds,
            'windowStartTime' => $settings->window_start_time,
            'windowEndTime' => $settings->window_end_time,
            'reserveSlotId' => $settings->reserve_slot_id,
            'paymentConfigId' => $settings->payment_config_id,
            'reserveRequestMeta' => $settings->reserve_request_meta,
            // Bundle-extracted IVAC endpoint paths + rotating headers (kept in sync with PublicConfigController).
            'endpoints' => $settings->ivacEndpointsWithDefaults(),
            // Lets the bot detect a mid-window rotation and hot-swap these constants without a restart.
            'configVersion' => $settings->protocolConstantsVersion(),
            'signin429ProxyUrl' => $settings->signin_429_proxy_url,
            // Booking-config rotation candidates (kept in sync with PublicConfigController).
            'bookingCityOptions' => \App\Support\IvacBookingCities::options(),
            'accounts' => $accounts->map(fn ($account) => [
                'id' => $account->id,
                'phone' => $account->phone,
                'email' => $account->email,
                'appointmentDates' => $account->appointment_dates ?? [],
                'pdfUploaded' => (bool) $account->pdf_uploaded,
                'bookingConfigured' => (bool) $account->booking_configured,
                'bookingMission' => \App\Support\IvacBookingCities::resolve($account->booking_city)['mission'] ?? null,
                'bookingIvacCenter' => \App\Support\IvacBookingCities::resolve($account->booking_city)['ivacCenter'] ?? null,
                'maxRetries' => $account->max_retries,
                'singleSignIn' => (bool) $account->single_sign_in,
                'retryDelayMs' => $account->retry_delay_ms ?? 5000,
            ]),
        ];
    }
}
