<?php

use App\Services\CaptchaAlgorithmService;
use Illuminate\Support\Facades\File;

/**
 * Guards the content-addressed disk cache in front of app/Scripts/extract_request_constants.cjs.
 *
 * The extractor is a cold eval of the whole ~2 MB bundle (~3.3s) whose output is a pure function
 * of (bundle, extractor script), and analyze() runs it on every "Run Analysis" click. Caching it
 * keeps an unchanged bundle — the normal state between IVAC redeploys — off that 3.3s path.
 *
 * The two invariants worth pinning: a cached entry IS reused for the same bundle, and the key is
 * bound to the extractor script's hash so a fixed extractor is never handed its own pre-fix output.
 */
function callExtractBundleRequestData(): ?array
{
    $service = app(CaptchaAlgorithmService::class);
    $method = new ReflectionMethod($service, 'extractBundleRequestData');

    return $method->invoke($service);
}

function requestConstantsCacheFile(): ?string
{
    $service = app(CaptchaAlgorithmService::class);
    $method = new ReflectionMethod($service, 'requestConstantsCachePath');

    return $method->invoke(
        $service,
        rtrim((string) config('captcha.storage_path'), '/').'/ivac-bundle.js',
        base_path('app/Scripts/extract_request_constants.cjs')
    );
}

beforeEach(function () {
    $archived = glob(storage_path('app/captcha/bundles').'/*.js');
    if (empty($archived)) {
        $this->markTestSkipped('no archived bundles on disk');
    }

    // Isolated storage dir so the live bundle/meta and the real analysis_cache are never touched.
    $this->captchaDir = storage_path('framework/testing/captcha-'.uniqid());
    File::ensureDirectoryExists($this->captchaDir);
    File::copy($archived[0], $this->captchaDir.'/ivac-bundle.js');
    config(['captcha.storage_path' => $this->captchaDir]);
});

afterEach(function () {
    if (isset($this->captchaDir)) {
        File::deleteDirectory($this->captchaDir);
    }
});

it('serves a cached extraction instead of re-running the extractor', function () {
    $cacheFile = requestConstantsCacheFile();
    expect($cacheFile)->not->toBeNull();

    File::ensureDirectoryExists(dirname($cacheFile));
    File::put($cacheFile, json_encode([
        'ok' => true,
        'paymentConfigId' => 'cached-payment-config-id',
        'reserveRequestMeta' => 'cached.meta',
        'reserveSlotId' => 'cached-reserve-slot-id',
        'endpoints' => ['signin' => '/auth/cached-sign-in'],
    ]));

    $startedAt = microtime(true);
    $data = callExtractBundleRequestData();
    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($data['paymentConfigId'])->toBe('cached-payment-config-id');
    expect($data['endpoints']['signin'])->toBe('/auth/cached-sign-in');
    // A real extraction is a ~3.3s bundle eval; a cache hit is a file read.
    expect($elapsedMs)->toBeLessThan(1000.0);
});

it('ignores a cached entry that did not extract cleanly', function () {
    $cacheFile = requestConstantsCacheFile();
    File::ensureDirectoryExists(dirname($cacheFile));
    File::put($cacheFile, json_encode(['ok' => false, 'reason' => 'no call sites']));

    $data = callExtractBundleRequestData();

    // Falls through to a real extraction, which then overwrites the failed entry.
    expect($data['ok'] ?? false)->toBeTrue();
    expect($data['paymentConfigId'] ?? null)->not->toBeNull();
    expect(json_decode(File::get($cacheFile), true)['ok'])->toBeTrue();
})->skip(fn () => ! shell_exec('which node'), 'node not available');

it('writes the extraction to the cache on a miss', function () {
    $cacheFile = requestConstantsCacheFile();
    expect(File::exists($cacheFile))->toBeFalse();

    $data = callExtractBundleRequestData();

    expect($data['ok'] ?? false)->toBeTrue();
    expect(File::exists($cacheFile))->toBeTrue();
    expect(json_decode(File::get($cacheFile), true))->toEqual($data);
})->skip(fn () => ! shell_exec('which node'), 'node not available');

it('keys the cache on the extractor script so an extractor fix invalidates it', function () {
    $cacheFile = requestConstantsCacheFile();
    $scriptHash = substr(hash_file('sha256', base_path('app/Scripts/extract_request_constants.cjs')), 0, 12);

    expect(basename($cacheFile))->toStartWith('reqconst_v1_');
    expect(basename($cacheFile))->toEndWith('_'.$scriptHash.'.json');
    expect(dirname($cacheFile))->toBe(rtrim((string) config('captcha.storage_path'), '/').'/analysis_cache');
});

it('keys the cache on bundle content, ignoring the analyzer source header', function () {
    $bundlePath = rtrim((string) config('captcha.storage_path'), '/').'/ivac-bundle.js';
    $body = File::get($bundlePath);

    $withoutHeader = requestConstantsCacheFile();
    File::put($bundlePath, "// source: https://appointment.ivacbd.com/assets/x.js\n".$body);
    $withHeader = requestConstantsCacheFile();

    // dump_bundle() prepends that header on the proxy path but not on the edge path — the same
    // deployed bundle must key the same either way.
    expect($withHeader)->toBe($withoutHeader);

    File::put($bundlePath, 'window.__different__=1;'.$body);
    expect(requestConstantsCacheFile())->not->toBe($withoutHeader);
});
