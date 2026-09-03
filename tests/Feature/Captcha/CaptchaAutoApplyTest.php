<?php

use App\Services\Captcha\CaptchaBundleVersionService;
use App\Services\Captcha\LiveBundleClient;
use App\Services\CaptchaAlgorithmService;
use App\Services\IvacEdgeBundleFetcher;
use App\Models\CaptchaTransformSeed;
use App\Support\CaptchaTokenTransformer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const AA_TOKEN = '0.Abc123-_xYzDEFghijklmNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz';
const AA_LOGIN_SECRET = 'tbp&12login_secret_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const AA_RESERVE_SECRET = 'y%62jreserve_secret_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * A CaptchaAlgorithmService whose Python analyzer call is replaced with a canned result,
 * so the auto-apply quality gate can be tested without hitting the network or live bundle.
 */
function fakeService(array $canned): CaptchaAlgorithmService
{
    return new class(app(LiveBundleClient::class), app(CaptchaBundleVersionService::class), app(IvacEdgeBundleFetcher::class), $canned) extends CaptchaAlgorithmService
    {
        public function __construct(LiveBundleClient $sidecar, CaptchaBundleVersionService $bundleVersions, IvacEdgeBundleFetcher $edgeFetcher, private array $canned)
        {
            parent::__construct($sidecar, $bundleVersions, $edgeFetcher);
        }

        protected function runScript(string $proxy, ?callable $progressCallback = null): array
        {
            return $this->canned;
        }
    };
}

function cleanScriptResult(): array
{
    $login = CaptchaTokenTransformer::transformLogin(AA_TOKEN, AA_LOGIN_SECRET, 7, 19);
    $reserve = CaptchaTokenTransformer::transformReserve(AA_TOKEN, AA_RESERVE_SECRET, 5, 17);

    return [
        'error' => null,
        'bundle_url' => 'https://appointment.ivacbd.com/assets/index-DEADBEEF.js',
        'magic_numbers_match' => true,
        'login_magic_match' => true,
        'detected_offset' => 5,
        'detected_length' => 17,
        'js_function' => null,
        'captcha_encryption' => null,
        'captcha_constants' => ['charset' => null, 'secret' => AA_RESERVE_SECRET, 'skip' => 5, 'encrypt_len' => 17],
        'login_constants' => ['secret' => AA_LOGIN_SECRET, 'skip' => 7, 'encrypt_len' => 19],
        'call_sites' => [
            ['var' => 'FQ', 'algorithm' => 'login', 'skip' => 7, 'enc_len' => 19, 'secret' => AA_LOGIN_SECRET],
            ['var' => 'KZ', 'algorithm' => 'reserve', 'skip' => 5, 'enc_len' => 17, 'secret' => AA_RESERVE_SECRET],
        ],
        'impl_check' => ['test_token' => AA_TOKEN, 'login' => $login, 'reserve' => $reserve],
        'encrypt_meta' => [
            'login' => ['module' => 'P1', 'version' => 8, 'skip' => 7, 'enc_len' => 19, 'secret' => AA_LOGIN_SECRET],
            'reserve' => ['module' => 'u1', 'version' => 7, 'skip' => 5, 'enc_len' => 17, 'secret' => AA_RESERVE_SECRET],
        ],
        'meta_written' => true,
        'extraction_ok' => true,
        'extraction_reason' => null,
        'live_modules' => ['P1', 'u1'],
        'bundle_hash' => 'abc123',
        'logs' => [],
    ];
}

beforeEach(function () {
    Http::fake(['*/reload' => Http::response(['ok' => true], 200), '*/health' => Http::response(['ok' => true], 200)]);
    Cache::forget(CaptchaAlgorithmService::NEEDS_ATTENTION_CACHE_KEY);
    Cache::forget(CaptchaAlgorithmService::BUNDLE_ASSET_CACHE_KEY);
});

it('auto-applies both seeds from the live bundle on a clean extraction', function () {
    $result = fakeService(cleanScriptResult())->analyze('http://proxy');

    expect($result['auto_applied']['applied'])->toBeTrue();

    $login = CaptchaTransformSeed::activeForType('login');
    $reserve = CaptchaTransformSeed::activeForType('reserve');
    expect($login->seed)->toBe(AA_LOGIN_SECRET);
    expect([$login->offset, $login->length])->toBe([7, 19]);
    expect($reserve->seed)->toBe(AA_RESERVE_SECRET);
    expect([$reserve->offset, $reserve->length])->toBe([5, 17]);

    expect(Cache::get(CaptchaAlgorithmService::NEEDS_ATTENTION_CACHE_KEY))->toBeNull();

    // Marker advances on a clean apply so the scheduled run short-circuits next tick.
    expect(Cache::get(CaptchaAlgorithmService::BUNDLE_ASSET_CACHE_KEY))->toBe('index-DEADBEEF.js');
});

it('does not apply seeds and raises needs-attention on an unclean extraction', function () {
    $canned = cleanScriptResult();
    $canned['extraction_ok'] = false;
    $canned['meta_written'] = false;
    $canned['extraction_reason'] = 'incomplete extraction for: reserve';
    // Drop the reserve module so the extraction alarm also triggers.
    $canned['encrypt_meta']['reserve']['module'] = null;

    $result = fakeService($canned)->analyze('http://proxy');

    expect($result['auto_applied']['applied'])->toBeFalse();
    expect(CaptchaTransformSeed::where('is_active', true)->count())->toBe(0);
    expect(Cache::get(CaptchaAlgorithmService::NEEDS_ATTENTION_CACHE_KEY))->not->toBeNull();

    // Marker stays stale on an unclean run so the cron retries the redeploy.
    expect(Cache::get(CaptchaAlgorithmService::BUNDLE_ASSET_CACHE_KEY))->toBeNull();
});
