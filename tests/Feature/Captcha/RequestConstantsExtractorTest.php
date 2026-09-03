<?php

use Symfony\Component\Process\Process;

/**
 * Guards app/Scripts/extract_request_constants.cjs against IVAC bundle-obfuscation
 * rotations. The extractor recovers three rotating request constants by EXECUTING the
 * bundle's own obfuscated code:
 *   - paymentConfigId    (POST /payment/{id}/dg-epay/initiate)
 *   - reserveRequestMeta (x-v-request-meta header on reserve-slot)
 *   - reserveSlotId      (POST /slots/{id}/reserve-slot)
 *
 * Two extraction strategies must both keep working, so the corpus below deliberately
 * spans both bundle shapes seen in production:
 *   - a465bbff: payment is a MODULE-SCOPE builder gated on paymentMethod==="dg-epay"
 *               (recovered by invoking builders against a stubbed axios instance).
 *   - 0c7383b7: payment moved INSIDE a React-Query mutation in a component that never
 *               runs headless (recovered by decoding the URL ternary directly).
 *   - 5e866412: the v23 redeploy (current live), same component-embedded shape.
 *
 * Skips (never red-fails) when an archived fixture is absent, so pruning old bundles
 * degrades coverage gracefully.
 */
function extractRequestConstants(string $hashPrefix): array
{
    $dir = storage_path('app/captcha/bundles');
    $matches = glob($dir.'/'.$hashPrefix.'*.js');
    if (empty($matches)) {
        test()->markTestSkipped("archived bundle {$hashPrefix} is not on disk");
    }

    $process = new Process([
        'node', base_path('app/Scripts/extract_request_constants.cjs'), $matches[0],
    ]);
    $process->setTimeout(90);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $out = json_decode(trim($process->getOutput()), true);
    expect($out)->toBeArray();
    expect($out['ok'] ?? false)->toBeTrue('extractor reported: '.json_encode($out));

    return $out;
}

/**
 * Anchors each endpoint key to a STABLE IVAC-contract substring. A well-formed path starts
 * with "/" and carries its anchor; the extractor omits anything that fails this so the bot
 * keeps its compiled-in default rather than adopting a garbage path.
 *
 * @return array<string, string>
 */
function endpointAnchors(): array
{
    return [
        'signin' => 'sign-in',
        'sendOtp' => 'sendOtp',
        'verifyOtp' => 'verifySigninOtp',
        'uploadFile' => 'upload_file',
        'bookingConfig' => 'appointment-booking-config',
        'getBookingConfig' => 'get-booking-config',
        'reserveSlot' => 'reserve-slot',
        'payment' => 'dg-epay/initiate',
    ];
}

beforeEach(function () {
    $p = new Process(['which', 'node']);
    $p->run();
    if (! $p->isSuccessful()) {
        $this->markTestSkipped('node not available');
    }
});

it('extracts the rotating request constants across both bundle shapes', function (string $hashPrefix, array $expected) {
    $out = extractRequestConstants($hashPrefix);

    expect($out['paymentConfigId'])->toBe($expected['paymentConfigId'], "{$hashPrefix}: paymentConfigId");
    expect($out['reserveRequestMeta'])->toBe($expected['reserveRequestMeta'], "{$hashPrefix}: reserveRequestMeta");
    expect($out['reserveSlotId'])->toBe($expected['reserveSlotId'], "{$hashPrefix}: reserveSlotId");
})->with([
    // module-scope payment builder (PART A: invoke + axios stub)
    'a465bbff' => ['a465bbff', [
        'paymentConfigId' => 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f',
        'reserveRequestMeta' => 'windos.s',
        'reserveSlotId' => 'ccd3dd63-e781-48bf-a48d-c65eaa4fc663',
    ]],
    // component-embedded payment mutation (PART B: decode URL ternary)
    '0c7383b7' => ['0c7383b7', [
        'paymentConfigId' => 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f',
        'reserveRequestMeta' => 'windos.s',
        'reserveSlotId' => 'ccd3dd63-e781-48bf-a48d-c65eaa4fc663',
    ]],
    // v23 redeploy (current live at time of writing)
    '5e866412' => ['5e866412', [
        'paymentConfigId' => 'dcd59a95-d55e-41ed-b57c-60416e01617e',
        'reserveRequestMeta' => 'windos.s',
        'reserveSlotId' => '54ea9f13-f1e2-4cea-9e08-f525e8242ccf',
    ]],
    // ms2tfbf2: axios instance minified to `$h`, and the payment URL moved into a local
    // object map picked by the ternary. Both broke extraction — see the two tests below.
    '2ad7b1ad' => ['2ad7b1ad', [
        'paymentConfigId' => 'dcd59a95-d55e-41ed-b57c-60416e01617e',
        'reserveRequestMeta' => 'windos.s',
        'reserveSlotId' => '54ea9f13-f1e2-4cea-9e08-f525e8242ccf',
    ]],
    // ms72wysd: the factory property stopped being obfuscated (`xh.create` instead of
    // `bh[Hh(200)+"e"]`), and this is the redeploy that rotated paymentConfigId 41ed -> 41ad.
    '39f6d546' => ['39f6d546', [
        'paymentConfigId' => 'dcd59a95-d55e-41ad-b57c-60416e01617e',
        'reserveRequestMeta' => 'windos.s',
        'reserveSlotId' => '54ea9f13-f1e2-4cea-9e18-f525e8242ccf',
    ]],
]);

/**
 * The Jul 30 2026 build emitted the axios factory as a plain `xh.create(` where every earlier
 * bundle assembled it as a computed member (`bh[Hh(200)+"e"]`). Matching only the computed form
 * left findAxiosName() returning null, which skips Strategy A entirely: the extractor still
 * exited ok, endpoint paths still came back off their plain-literal fallbacks, and the only
 * casualties were the three values that ONLY the recorder can supply. That redeploy also
 * rotated paymentConfigId, so the portal silently served the stale UUID to the bot.
 */
it('records axios calls when the factory property is not obfuscated', function () {
    $out = extractRequestConstants('39f6d546');

    expect($out['reserveRequestMeta'])->toBe('windos.s', 'recorder was not injected against xh.create');
    expect($out['endpoints']['signinNavState'] ?? null)
        ->toBe('80d51dc5-af20-46fa-a7bb-e6a8f3f80065');
    expect($out['paymentConfigId'])->toBe('dcd59a95-d55e-41ad-b57c-60416e01617e');
});

/**
 * Minified identifiers can contain `$`, which is a regex anchor. Interpolating the axios
 * instance name into a pattern unescaped produced a regex that could never match, so BOTH
 * strategies went silently blind: the recorder was never injected (0 axios calls, losing
 * reserveRequestMeta + signinNavState) and the payment call site was "not found". The
 * failure mode is silent — the extractor still exits ok with nulls — so it is worth pinning.
 */
it('handles an axios instance whose minified name contains a regex metacharacter', function () {
    $out = extractRequestConstants('2ad7b1ad');

    // Recorded axios calls are the ONLY source of these two; non-null proves the recorder
    // was injected against the `$h` instance.
    expect($out['reserveRequestMeta'])->toBe('windos.s');
    expect($out['endpoints']['signinNavState'] ?? null)
        ->toMatch('/^[0-9a-fA-F-]{36}$/', 'navState comes off the recorded sign-in call');
});

/**
 * This build parks both candidate payment URLs in a local object and the ternary only picks
 * a key (`const e={hDRjx:<dg-epay concat>,...}` then `const r=e[..](..)?e[..]:e[..]`), so the
 * branch expression evaluates to undefined unless the object comes along. The assignment is
 * also a `const` declaration rather than a comma-sequence member.
 */
it('decodes a payment URL that lives in a local object map', function () {
    $out = extractRequestConstants('2ad7b1ad');

    expect($out['paymentConfigId'])->toBe('dcd59a95-d55e-41ed-b57c-60416e01617e');
    expect($out['endpoints']['payment'] ?? null)->toBe('/payment/{paymentConfigId}/dg-epay/initiate');
});

/**
 * The endpoint-path extraction must be VERSION-AGNOSTIC: the archive spans /auth/sign-in-v4,
 * /auth/v12-sign-in and /auth/v23-sign-in, so we assert well-formedness (anchor + leading slash)
 * and the always-literal core set, never a fixed version string.
 */
it('extracts only well-formed endpoint paths from every archived bundle', function () {
    $bundles = glob(storage_path('app/captcha/bundles').'/*.js');
    if (empty($bundles)) {
        test()->markTestSkipped('no archived bundles on disk');
    }

    $anchors = endpointAnchors();

    foreach ($bundles as $bundle) {
        $name = basename($bundle);
        $process = new Process(['node', base_path('app/Scripts/extract_request_constants.cjs'), $bundle]);
        $process->setTimeout(90);
        $process->run();

        expect($process->isSuccessful())->toBeTrue("{$name}: ".$process->getErrorOutput());
        $out = json_decode(trim($process->getOutput()), true);
        expect($out['ok'] ?? false)->toBeTrue("{$name}: ".json_encode($out));

        // Resolving the shared axios instance is the precondition for Strategy A, and every
        // bundle ever archived satisfies it. Asserting it here is shape-agnostic, so a future
        // build that mangles the factory call fails loudly instead of silently dropping the
        // three recorder-only constants.
        expect($out['axiosName'] ?? null)->not->toBeNull("{$name}: shared axios instance not located");

        $ep = $out['endpoints'] ?? null;
        expect($ep)->toBeArray("{$name}: endpoints missing");

        // Every observed bundle yields these six as plain literals, independent of the axios instance.
        foreach (['signin', 'verifyOtp', 'bookingConfig', 'getBookingConfig', 'reserveSlot', 'payment'] as $core) {
            expect(array_key_exists($core, $ep))->toBeTrue("{$name}: missing core endpoint {$core}");
        }

        // Templated paths keep their placeholders — the UUID stays synced separately.
        expect($ep['reserveSlot'])->toBe('/slots/{reserveSlotId}/reserve-slot', "{$name}: reserveSlot template");
        expect($ep['payment'])->toBe('/payment/{paymentConfigId}/dg-epay/initiate', "{$name}: payment template");

        foreach ($ep as $key => $val) {
            if ($key === 'signinNavState') {
                expect($val)->toMatch('/^[0-9a-fA-F-]{36}$/', "{$name}: navState not a UUID: {$val}");

                continue;
            }
            expect(str_starts_with($val, '/'))->toBeTrue("{$name}: {$key} must start with / ({$val})");
            expect(str_contains($val, $anchors[$key]))->toBeTrue("{$name}: {$key} must contain {$anchors[$key]} ({$val})");
        }
    }
});

/**
 * Pins the current live (v23) endpoint contract exactly, including the nav-state header the
 * extractor records off the sign-in call. Update these values on the next IVAC redeploy.
 */
it('extracts the exact live-bundle (v23) endpoint contract', function () {
    $out = extractRequestConstants('5e866412');

    expect($out['endpoints'])->toEqual([
        'signin' => '/auth/v23-sign-in',
        'verifyOtp' => '/otp/verifySigninOtp',
        'uploadFile' => '/file/upload_file_v23',
        'bookingConfig' => '/appointment/appointment-booking-config',
        'getBookingConfig' => '/appointment/get-booking-config',
        'reserveSlot' => '/slots/{reserveSlotId}/reserve-slot',
        'payment' => '/payment/{paymentConfigId}/dg-epay/initiate',
        'signinNavState' => '80d51dc5-af20-46fa-a7bb-e6a8f3f80065',
    ]);
});
