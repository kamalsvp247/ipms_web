<?php

use App\Models\Setting;
use App\Services\CaptchaAlgorithmService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The monitor has to distinguish "this bundle confirmed the constant" from "extraction
 * found nothing, but a stored value is still what the bot sends". Reporting null for the
 * second case reads as "we have nothing" and hides a value that is actively in use —
 * paymentConfigId, reserveRequestMeta, sendOtp and uploadRuntimeState are obfuscated
 * beyond headless extraction, so that case is normal, not an error.
 */

function syncResult(string $method): array
{
    return (new ReflectionMethod(CaptchaAlgorithmService::class, $method))
        ->invoke(app(CaptchaAlgorithmService::class));
}

beforeEach(function () {
    $dir = storage_path('framework/testing/captcha-sync-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    config(['captcha.storage_path' => $dir]);
    // A bundle with none of the constants in it, so every extraction legitimately misses.
    file_put_contents($dir.'/ivac-bundle.js', 'var __defProp=Object.defineProperty;// nothing to find here');
});

afterEach(function () {
    @unlink(config('captcha.storage_path').'/ivac-bundle.js');
});

it('reports the stored reserve slot id when the bundle has no match', function () {
    Setting::instance()->update(['reserve_slot_id' => 'ccd3dd63-e781-48ba-a48d-c65eaa4fc663']);

    $result = syncResult('syncReserveSlotId');

    expect($result['detected'])->toBeNull();
    expect($result['previous'])->toBe('ccd3dd63-e781-48ba-a48d-c65eaa4fc663');
    expect($result['changed'])->toBeFalse();
});

it('detects the reserve slot id when the bundle does contain it', function () {
    Setting::instance()->update(['reserve_slot_id' => 'old-value']);
    file_put_contents(
        config('captcha.storage_path').'/ivac-bundle.js',
        'var x="/slots/54ea9f13-f1e2-4cea-9e08-f525e8242ccf/reserve-slot";'
    );

    $result = syncResult('syncReserveSlotId');

    expect($result['detected'])->toBe('54ea9f13-f1e2-4cea-9e08-f525e8242ccf');
    expect($result['previous'])->toBe('old-value');
    expect($result['changed'])->toBeTrue();
    expect(Setting::instance()->fresh()->reserve_slot_id)->toBe('54ea9f13-f1e2-4cea-9e08-f525e8242ccf');
});

it('reports stored request constants when extraction finds nothing', function () {
    Setting::instance()->update([
        'payment_config_id' => 'dcd59a95-d55e-41ed-b57c-60416e01617e',
        'reserve_request_meta' => 'windos.s',
    ]);

    $result = syncResult('syncRequestConstants');

    expect($result['payment_config_id']['detected'])->toBeNull();
    expect($result['payment_config_id']['previous'])->toBe('dcd59a95-d55e-41ed-b57c-60416e01617e');
    expect($result['reserve_request_meta']['previous'])->toBe('windos.s');
    // A miss must never clear a good stored value.
    expect(Setting::instance()->fresh()->payment_config_id)->toBe('dcd59a95-d55e-41ed-b57c-60416e01617e');
});

it('returns the full effective endpoint set alongside what the bundle yielded', function () {
    $result = syncResult('syncEndpoints');

    expect($result)->toHaveKeys(['changed', 'detected', 'detected_count', 'effective']);
    // Nothing real is extractable from this stub bundle. `payment` is still reported
    // because it is a fixed compiled-in template, not a bundle-derived value.
    expect(array_keys($result['detected']))->toBe(['payment']);
    expect($result['changed'])->toBe([]);
    // Every endpoint the bot will receive is reported regardless.
    expect(array_keys($result['effective']))->toBe(array_keys(Setting::defaultIvacEndpoints()));
    foreach ($result['effective'] as $value) {
        expect($value)->toBeString()->not->toBe('');
    }
});

it('keeps a manual endpoint override in the effective set', function () {
    Setting::instance()->update(['ivac_endpoints' => ['sendOtp' => '/forgot-password/sendOtpV2']]);

    $result = syncResult('syncEndpoints');

    expect($result['effective']['sendOtp'])->toBe('/forgot-password/sendOtpV2');
});
