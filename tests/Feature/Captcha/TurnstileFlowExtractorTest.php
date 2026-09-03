<?php

use App\Models\Setting;
use App\Services\Captcha\TurnstileFlowExtractor;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Step 2 of the protocol-emulation plan: telling Cloudflare's deployment constants apart
 * from its per-session values.
 *
 * Getting this split wrong fails in one of two ways, and both are covered below. Storing a
 * per-session value bakes one solve's cf-ray into the configuration and every later solve
 * is rejected; treating a deployment constant as per-session leaves the emulator with
 * nowhere to read it from.
 *
 * The fixtures are two real captures taken minutes apart against the live widget.
 */
function traceFixture(string $ray, string $cacheBust, string $chl, string $ts): array
{
    $chlToken = "{$chl}-{$ts}-1.2.1.1-".str_repeat('K', 40);
    $patToken = "{$chl}-{$ts}-1.3.1.1-".str_repeat('L', 40);

    return ['calls' => [
        ['role' => 'document', 'host' => 'appointment.ivacbd.com', 'path' => '/'],
        ['role' => 'turnstile', 'host' => 'challenges.cloudflare.com', 'path' => '/turnstile/v0/g/b0da9f4911ba/api.js'],
        ['role' => 'challenge', 'host' => 'challenges.cloudflare.com', 'path' => "/cdn-cgi/challenge-platform/h/g/turnstile/f/av0/rch/{$cacheBust}/0x4AAAAAACghKkJHL1t7UkuZ/auto/fbE/new/normal?lang=auto"],
        ['role' => 'challenge', 'host' => 'challenges.cloudflare.com', 'path' => "/cdn-cgi/challenge-platform/h/g/fo/1337154840:1785222027:dvRMihd0_YhU77KiPs7yHIwtEfpGxt2lCyj6q2sEBZg/{$ray}/{$chlToken}"],
        ['role' => 'challenge', 'host' => 'hagen.challenges.cloudflare.com', 'path' => "/cdn-cgi/challenge-platform/h/g/i/{$ray}/{$chlToken}"],
        ['role' => 'challenge', 'host' => 'challenges.cloudflare.com', 'path' => "/cdn-cgi/challenge-platform/h/g/pat/{$ray}/{$ts}538/".str_repeat('a', 64)."/{$patToken}"],
        ['role' => 'challenge', 'host' => 'challenges.cloudflare.com', 'path' => "/cdn-cgi/challenge-platform/h/g/ci/{$ray}/{$ts}539/{$patToken}"],
        ['role' => 'blob', 'host' => '', 'path' => 'blob:https://challenges.cloudflare.com/66004e09'],
    ]];
}

function firstCapture(): array
{
    return traceFixture('a22247993c88f3f4', 'lgy6x', '_.Vk7q0P87HkcHLDhEdHr5v9GGLmJx2QYHghOXtyo4E', '1785224887');
}

function secondCapture(): array
{
    return traceFixture('a2224b36faa5cc23', 'bye7w', 'kl5N3ZsLXK2CLlgW.mUhkv9VVDvgde7dOGypCf8rJpY', '1785225035');
}

describe('TurnstileFlowExtractor', function () {
    it('pulls the deployment constants out of a capture', function () {
        $derived = app(TurnstileFlowExtractor::class)->extract(firstCapture());

        expect($derived['constants'])->toBe([
            'widget_host' => 'challenges.cloudflare.com',
            'api_asset' => 'b0da9f4911ba',
            'branch' => 'g',
            'av' => 'av0',
            'fb' => 'fbE',
            'deploy_triple' => '1337154840:1785222027:dvRMihd0_YhU77KiPs7yHIwtEfpGxt2lCyj6q2sEBZg',
            'telemetry_host' => 'hagen.challenges.cloudflare.com',
        ]);
    });

    it('templatises every leg of the flow', function () {
        $templates = app(TurnstileFlowExtractor::class)->extract(firstCapture())['templates'];

        expect(array_keys($templates))->toBe(['api_js', 'iframe', 'flow', 'init', 'pat', 'ci']);
        expect($templates['flow'])->toBe('/cdn-cgi/challenge-platform/h/{branch}/fo/{deploy_triple}/{ray}/{chl_token}');
        expect($templates['pat'])->toBe('/cdn-cgi/challenge-platform/h/{branch}/pat/{ray}/{pat_ts}/{pat_digest}/{pat_token}');
    });

    /**
     * cf-ray, the challenge token and the rch cache-buster change on every single solve.
     * They belong to the session, are read from the live bootstrap, and must never reach
     * the stored configuration.
     */
    it('keeps per-session values out of the constants', function () {
        $derived = app(TurnstileFlowExtractor::class)->extract(firstCapture());

        expect($derived['session'])->toHaveKeys(['ray', 'chl_token', 'cache_bust', 'pat_ts', 'pat_digest']);
        expect($derived['session']['ray'])->toBe('a22247993c88f3f4');
        expect($derived['session']['cache_bust'])->toBe('lgy6x');

        foreach (['a22247993c88f3f4', 'lgy6x'] as $sessionValue) {
            expect(implode('|', $derived['constants']))->not->toContain($sessionValue);
        }
    });

    /**
     * The split is measured rather than asserted: re-deriving across captures is what
     * proves a value is deployment-scoped. Every constant held across two real solves.
     */
    it('reports the constants as stable across separate captures', function () {
        $report = app(TurnstileFlowExtractor::class)->stability([firstCapture(), secondCapture()]);

        expect($report['samples'])->toBe(2);
        expect($report['volatile'])->toBe([]);
        expect($report['stable']['branch'])->toBe('g');
        expect($report['stable']['api_asset'])->toBe('b0da9f4911ba');
    });

    it('flags a constant that moved between captures as volatile', function () {
        $rotated = secondCapture();
        // Cloudflare redeploys onto a new branch letter and api.js asset.
        $rotated['calls'][1]['path'] = '/turnstile/v0/g/ffffffffffff/api.js';

        $report = app(TurnstileFlowExtractor::class)->stability([firstCapture(), $rotated]);

        expect($report['volatile'])->toHaveKey('api_asset');
        expect($report['volatile']['api_asset'])->toBe(['b0da9f4911ba', 'ffffffffffff']);
    });
});

describe('TurnstileFlowExtractor::sync', function () {
    it('stores the constants and templates, and is idempotent', function () {
        $extractor = app(TurnstileFlowExtractor::class);

        $first = $extractor->sync(firstCapture());
        expect($first['changed'])->not->toBeEmpty();
        expect(Setting::instance()->fresh()->turnstile_endpoints['branch'])->toBe('g');

        expect($extractor->sync(firstCapture())['changed'])->toBe([]);
    });

    /**
     * A failed or partial capture must leave the previous configuration in place. Clearing
     * it would take the emulator down until the next successful trace, which is strictly
     * worse than running on slightly stale constants.
     */
    it('leaves a good configuration alone when a capture yields nothing well-formed', function () {
        $extractor = app(TurnstileFlowExtractor::class);
        $extractor->sync(firstCapture());
        $good = Setting::instance()->fresh()->turnstile_endpoints;

        $extractor->sync(['calls' => [
            ['role' => 'challenge', 'host' => 'evil.example.com', 'path' => '/cdn-cgi/challenge-platform/h/ZZZZ/fo/notatriple/xx/yy'],
        ]]);

        expect(Setting::instance()->fresh()->turnstile_endpoints)->toBe($good);
    });

    it('adopts a rotation without a redeploy', function () {
        $extractor = app(TurnstileFlowExtractor::class);
        $extractor->sync(firstCapture());

        $rotated = secondCapture();
        $rotated['calls'][1]['path'] = '/turnstile/v0/g/ffffffffffff/api.js';

        expect($extractor->sync($rotated)['changed'])->toBe(['api_asset' => 'ffffffffffff']);
        expect(Setting::instance()->fresh()->turnstile_endpoints['api_asset'])->toBe('ffffffffffff');
    });
});
