<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class IvacEndpointController extends Controller
{
    /**
     * Per-key presentation + validation metadata. `group` splits paths from headers, `sync` marks
     * whether the Algorithm Monitor auto-extracts the value from the bundle (auto) or it is
     * manual-only because it is not headless-decodable (manual). `anchor`, when set, is the stable
     * substring a path value must contain to be well-formed.
     *
     * @return array<string, array{label: string, group: 'path'|'header', header?: string, sync: 'auto'|'manual', anchor?: string, note?: string}>
     */
    private function meta(): array
    {
        return [
            'signin' => ['label' => 'Sign-in', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'sign-in', 'note' => 'POST — mints the JWT.'],
            'sendOtp' => ['label' => 'Send OTP', 'group' => 'path', 'sync' => 'manual', 'anchor' => 'sendOtp', 'note' => 'POST — forgot-password OTP. Obfuscated in the bundle, so not auto-extracted.'],
            'verifyOtp' => ['label' => 'Verify OTP', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'verifySigninOtp', 'note' => 'POST — verifies the sign-in OTP.'],
            'uploadFile' => ['label' => 'Upload File', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'upload_file', 'note' => 'POST — applicant PDF upload (multipart).'],
            'bookingConfig' => ['label' => 'Booking Config', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'appointment-booking-config', 'note' => 'POST — booking setup gate.'],
            'getBookingConfig' => ['label' => 'Get Booking Config', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'get-booking-config', 'note' => 'GET — appointmentId + available dates.'],
            'reserveSlot' => ['label' => 'Reserve Slot', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'reserve-slot', 'note' => 'POST — template; {reserveSlotId} is substituted by the bot.'],
            'payment' => ['label' => 'Payment Initiate', 'group' => 'path', 'sync' => 'auto', 'anchor' => 'dg-epay/initiate', 'note' => 'POST — template; {paymentConfigId} is substituted by the bot.'],
            'profile' => ['label' => 'Profile', 'group' => 'path', 'sync' => 'manual', 'anchor' => 'profile', 'note' => 'GET — the account holder\'s registered name/passport/NID. Read before a name-mismatch fix.'],
            'profileSendOtp' => ['label' => 'Profile Send OTP', 'group' => 'path', 'sync' => 'manual', 'anchor' => 'sendOtp', 'note' => 'POST — emails the OTP that gates a profile update.'],
            'profileVerifyAndUpdate' => ['label' => 'Profile Verify & Update', 'group' => 'path', 'sync' => 'manual', 'anchor' => 'verifyAndUpdate', 'note' => 'POST — applies the corrected name once the emailed OTP is verified.'],
            'signinNavState' => ['label' => 'Sign-in Nav State', 'group' => 'header', 'header' => 'x-sec-navigation-state', 'sync' => 'auto', 'note' => 'Header on the sign-in call.'],
            'uploadRuntimeState' => ['label' => 'Upload Runtime State', 'group' => 'header', 'header' => 'x-sec-runtime-state', 'sync' => 'manual', 'note' => 'Header on the upload call. Runtime-dependent, so not auto-extracted.'],
        ];
    }

    /**
     * The two rotating UUIDs the bot substitutes into the reserveSlot/payment path templates. They
     * live in their own settings columns (not in ivac_endpoints) because the Algorithm Monitor syncs
     * them separately from the bundle, but they are edited here so a template and the value it
     * resolves with are in one place.
     *
     * @return array<string, array{label: string, column: string, placeholder: string, template: string, note: string}>
     */
    private function constantMeta(): array
    {
        return [
            'reserveSlotId' => [
                'label' => 'Reserve Slot ID',
                'column' => 'reserve_slot_id',
                'placeholder' => 'ccd3dd63-e781-48ba-a48d-c65eaa4fc663',
                'template' => 'reserveSlot',
                'note' => 'Substituted into the Reserve Slot path. Auto-synced by the Algorithm Monitor; update here when reserve starts returning 404.',
            ],
            'paymentConfigId' => [
                'label' => 'Payment Config ID',
                'column' => 'payment_config_id',
                'placeholder' => 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f',
                'template' => 'payment',
                'note' => 'Substituted into the Payment Initiate path. Auto-synced by the Algorithm Monitor; update here when payment starts returning 404.',
            ],
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private function constants(Setting $setting): array
    {
        $values = [];
        foreach ($this->constantMeta() as $key => $info) {
            $values[$key] = $setting->{$info['column']};
        }

        return $values;
    }

    public function index(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $setting = Setting::instance();
        $constants = $this->constants($setting);

        return response()->json([
            'endpoints' => $setting->ivacEndpointsWithDefaults(),
            'stored' => is_array($setting->ivac_endpoints) ? $setting->ivac_endpoints : [],
            'defaults' => Setting::defaultIvacEndpoints(),
            'meta' => $this->meta(),
            'constantsMeta' => $this->constantMeta(),
            'constants' => $constants,
            'reserveSlotId' => $constants['reserveSlotId'],
            'paymentConfigId' => $constants['paymentConfigId'],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $meta = $this->meta();

        $validated = $request->validate([
            'endpoints' => 'required|array',
            'endpoints.*' => 'required|string|max:2048',
            'constants' => 'sometimes|array',
            'constants.*' => 'nullable|string|max:255',
        ]);

        $errors = [];
        $clean = [];
        foreach ($validated['endpoints'] as $key => $value) {
            if (! isset($meta[$key])) {
                continue; // ignore unknown keys — the key set is fixed
            }
            $value = trim((string) $value);
            $info = $meta[$key];

            if ($info['group'] === 'path' && ! str_starts_with($value, '/')) {
                $errors["endpoints.{$key}"] = ["{$info['label']} path must start with \"/\"."];

                continue;
            }
            if (isset($info['anchor']) && ! str_contains($value, $info['anchor'])) {
                $errors["endpoints.{$key}"] = ["{$info['label']} must contain \"{$info['anchor']}\"."];

                continue;
            }
            $clean[$key] = $value;
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        // Persist the full known set, filling any omitted key from the compiled-in defaults so the
        // column always resolves every endpoint.
        $merged = array_merge(Setting::defaultIvacEndpoints(), $clean);

        $setting = Setting::instance();
        $updates = ['ivac_endpoints' => $merged];

        // A blank constant is SKIPPED rather than written: the bot builds "/slots/{id}/reserve-slot"
        // from it, so persisting an empty string would silently produce a broken URL.
        $constantMeta = $this->constantMeta();
        foreach ($validated['constants'] ?? [] as $key => $value) {
            $value = trim((string) $value);
            if (! isset($constantMeta[$key]) || $value === '') {
                continue;
            }
            $updates[$constantMeta[$key]['column']] = $value;
        }

        $setting->update($updates);

        Log::info('[IvacEndpoints] endpoints updated manually', [
            'keys' => array_keys($clean),
            'constants' => array_keys(array_diff_key($updates, ['ivac_endpoints' => null])),
        ]);

        $constants = $this->constants($setting);

        return response()->json([
            'endpoints' => $setting->ivacEndpointsWithDefaults(),
            'stored' => $setting->ivac_endpoints,
            'constants' => $constants,
            'reserveSlotId' => $constants['reserveSlotId'],
            'paymentConfigId' => $constants['paymentConfigId'],
        ]);
    }

    /**
     * Reset every endpoint back to the compiled-in defaults (clears the stored overrides). The
     * request constants are left alone — they have no compiled-in default, only the value the
     * Algorithm Monitor last synced from the bundle.
     */
    public function reset(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $setting = Setting::instance();
        $setting->update(['ivac_endpoints' => Setting::defaultIvacEndpoints()]);

        Log::info('[IvacEndpoints] endpoints reset to defaults');

        $constants = $this->constants($setting);

        return response()->json([
            'endpoints' => $setting->ivacEndpointsWithDefaults(),
            'stored' => $setting->ivac_endpoints,
            'constants' => $constants,
            'reserveSlotId' => $constants['reserveSlotId'],
            'paymentConfigId' => $constants['paymentConfigId'],
        ]);
    }
}
