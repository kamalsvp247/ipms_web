<?php

use App\Models\CaptchaBundleVersion;
use App\Services\CaptchaAlgorithmService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The monitor re-downloads the 2.2 MB bundle on every analysis, but IVAC's assets are
 * content-hashed by Vite and served "cache-control: immutable" — a filename we have
 * already archived cannot have different bytes behind it. These cover the lookup that
 * lets an unchanged bundle skip the download entirely, and the guards that make it fall
 * back to a real fetch rather than feed stale or damaged bytes into analysis.
 */

function archiveBundle(string $contents, ?string $filename = null): CaptchaBundleVersion
{
    $hash = hash('sha256', $contents);
    $dir = config('captcha.storage_path').'/bundles';
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($dir.'/'.$hash.'.js', $contents);

    return CaptchaBundleVersion::create([
        'bundle_filename' => $filename ?? 'stub-'.substr($hash, 0, 8).'.js',
        'bundle_hash' => $hash,
        'extraction_ok' => true,
    ]);
}

function resolveArchive(string $assetName): ?string
{
    $method = new ReflectionMethod(CaptchaAlgorithmService::class, 'archivedBundlePath');

    return $method->invoke(app(CaptchaAlgorithmService::class), $assetName);
}

beforeEach(function () {
    config(['captcha.storage_path' => storage_path('framework/testing/captcha-'.getmypid())]);
});

afterEach(function () {
    $dir = config('captcha.storage_path');
    if (is_dir($dir.'/bundles')) {
        array_map('unlink', glob($dir.'/bundles/*.js') ?: []);
    }
});

it('returns the archived path for a filename it has already downloaded', function () {
    $version = archiveBundle('var __defProp=1;// archived', 'mrx52llu-V6dyI3yh.js');

    expect(resolveArchive('mrx52llu-V6dyI3yh.js'))
        ->toBe(config('captcha.storage_path').'/bundles/'.$version->bundle_hash.'.js');
});

it('returns null for a filename it has never seen', function () {
    archiveBundle('var __defProp=1;', 'mrx52llu-V6dyI3yh.js');

    expect(resolveArchive('brand-new-Ab12Cd.js'))->toBeNull();
});

it('returns null when the archived file has been pruned off disk', function () {
    $version = archiveBundle('var __defProp=1;', 'pruned-Zz99.js');
    unlink(config('captcha.storage_path').'/bundles/'.$version->bundle_hash.'.js');

    expect(resolveArchive('pruned-Zz99.js'))->toBeNull();
});

it('returns null when the archived file no longer matches its recorded hash', function () {
    // A truncated or tampered archive must trigger a real download, never a silent
    // analysis of the wrong bytes.
    $version = archiveBundle('var __defProp=1;// original', 'corrupt-Yy88.js');
    file_put_contents(config('captcha.storage_path').'/bundles/'.$version->bundle_hash.'.js', 'truncated');

    expect(resolveArchive('corrupt-Yy88.js'))->toBeNull();
});

it('prefers the newest row when a filename was registered more than once', function () {
    archiveBundle('var __defProp=1;// first', 'dupe-Xx77.js');
    $newer = archiveBundle('var __defProp=1;// second', 'dupe-Xx77.js');

    expect(resolveArchive('dupe-Xx77.js'))
        ->toBe(config('captcha.storage_path').'/bundles/'.$newer->bundle_hash.'.js');
});
