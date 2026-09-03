<?php

use App\Models\CaptchaProvider;
use App\Models\CaptchaTransformSeed;
use App\Models\User;
use App\Services\Captcha\CaptchaBundleVersionService;
use App\Services\Captcha\LiveBundleClient;
use App\Services\CaptchaAlgorithmService;
use App\Services\IvacEdgeBundleFetcher;
use App\Support\CaptchaTokenTransformer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const GT_TOKEN = '0.Abc123-_xYzDEFghijklmNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz';
const GT_LOGIN_SECRET = '671hnk6vg7e5hnv$4fy+7-_ch_io0)q$_xz=k++r-^&i32dfst';
const GT_RESERVE_SECRET = '@541m3tp&t63noy&3ngwa%fgfivy3n1_7d)zvj$h-au+bah50f';

/**
 * Build a service whose runScript() returns a canned analyzer result, so we can test
 * the ground-truth comparison + cache + response without hitting the live site.
 *
 * @param  array<string, mixed>  $scriptResult
 */
function fakeAlgorithmService(array $scriptResult): CaptchaAlgorithmService
{
    return new class($scriptResult) extends CaptchaAlgorithmService
    {
        public function __construct(private array $canned)
        {
            parent::__construct(app(LiveBundleClient::class), app(CaptchaBundleVersionService::class), app(IvacEdgeBundleFetcher::class));
        }

        protected function runScript(string $proxy, ?callable $progressCallback = null): array
        {
            return $this->canned;
        }
    };
}

function seedActive(): void
{
    CaptchaTransformSeed::create(['token_type' => 'login', 'seed' => GT_LOGIN_SECRET, 'offset' => 7, 'length' => 23, 'is_active' => true]);
    CaptchaTransformSeed::create(['token_type' => 'reserve', 'seed' => GT_RESERVE_SECRET, 'offset' => 4, 'length' => 22, 'is_active' => true]);
}

it('reports PHP matches when the PHP output equals the live bundle output', function () {
    seedActive();

    // Live outputs computed by the real PHP transformer == what the live bundle would produce.
    $liveLogin = CaptchaTokenTransformer::transformLogin(GT_TOKEN, GT_LOGIN_SECRET, 7, 23);
    $liveReserve = CaptchaTokenTransformer::transformReserve(GT_TOKEN, GT_RESERVE_SECRET, 4, 22);

    $result = fakeAlgorithmService([
        'bundle_url' => 'https://appointment.ivacbd.com/assets/test.js',
        'magic_numbers_match' => true,
        'login_magic_match' => true,
        'detected_offset' => 4, 'detected_length' => 22,
        'call_sites' => [
            ['var' => 'J', 'algorithm' => 'login', 'skip' => 7, 'enc_len' => 23, 'secret' => GT_LOGIN_SECRET],
            ['var' => 'A', 'algorithm' => 'reserve', 'skip' => 4, 'enc_len' => 22, 'secret' => GT_RESERVE_SECRET],
        ],
        'impl_check' => ['test_token' => GT_TOKEN, 'login' => $liveLogin, 'reserve' => $liveReserve],
        'encrypt_meta' => ['login' => ['module' => 'J0', 'version' => 6], 'reserve' => ['module' => 'A0', 'version' => 5]],
        'error' => null,
        'logs' => [],
    ])->analyze('proxy');

    expect($result['login_impl_match'])->toBeTrue();
    expect($result['reserve_impl_match'])->toBeTrue();
    expect($result['live_login_output'])->toBe($liveLogin);
    expect($result['engine'])->toHaveKey('sidecar');
});

it('flags PHP stale and caches false when the live output differs', function () {
    seedActive();

    $result = fakeAlgorithmService([
        'bundle_url' => 'https://appointment.ivacbd.com/assets/test.js',
        'magic_numbers_match' => true,
        'login_magic_match' => true,
        'detected_offset' => 4, 'detected_length' => 22,
        'call_sites' => [
            ['var' => 'J', 'algorithm' => 'login', 'skip' => 7, 'enc_len' => 23, 'secret' => GT_LOGIN_SECRET],
            ['var' => 'A', 'algorithm' => 'reserve', 'skip' => 4, 'enc_len' => 22, 'secret' => GT_RESERVE_SECRET],
        ],
        // A rotated algorithm would produce different bytes than our PHP port:
        'impl_check' => ['test_token' => GT_TOKEN, 'login' => '0.DIFFERENT_login_xxxxxxxxxxxx', 'reserve' => '0.DIFFERENT_reserve_xxxxxxxxxx'],
        'error' => null,
        'logs' => [],
    ])->analyze('proxy');

    expect($result['login_impl_match'])->toBeFalse();
    expect($result['reserve_impl_match'])->toBeFalse();
});

/**
 * Build a healthy (no-alarm) analyzer result — every extraction signal present.
 *
 * @return array<string, mixed>
 */
function healthyScriptResult(): array
{
    $liveLogin = CaptchaTokenTransformer::transformLogin(GT_TOKEN, GT_LOGIN_SECRET, 7, 23);
    $liveReserve = CaptchaTokenTransformer::transformReserve(GT_TOKEN, GT_RESERVE_SECRET, 4, 22);

    return [
        'bundle_url' => 'https://appointment.ivacbd.com/assets/test.js',
        'magic_numbers_match' => true,
        'login_magic_match' => true,
        'detected_offset' => 4, 'detected_length' => 22,
        'call_sites' => [
            ['var' => 'J', 'algorithm' => 'login', 'skip' => 7, 'enc_len' => 23, 'secret' => GT_LOGIN_SECRET],
            ['var' => 'A', 'algorithm' => 'reserve', 'skip' => 4, 'enc_len' => 22, 'secret' => GT_RESERVE_SECRET],
        ],
        'impl_check' => ['test_token' => GT_TOKEN, 'login' => $liveLogin, 'reserve' => $liveReserve],
        'encrypt_meta' => ['login' => ['module' => 'J0', 'version' => 6], 'reserve' => ['module' => 'A0', 'version' => 5]],
        'live_modules' => ['J0', 'A0'],
        'error' => null,
        'logs' => [],
    ];
}

it('does not raise the extraction alarm when every signal is present', function () {
    seedActive();

    $result = fakeAlgorithmService(healthyScriptResult())->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeFalse();
    expect($result['extraction_alarm']['issues'])->toBe([]);
});

it('raises the extraction alarm when no encrypt modules are exposed', function () {
    seedActive();

    $script = healthyScriptResult();
    $script['live_modules'] = [];

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeTrue();
    expect(implode(' ', $result['extraction_alarm']['issues']))->toContain('No encrypt modules');
});

it('raises the extraction alarm when no call sites are resolved', function () {
    seedActive();

    $script = healthyScriptResult();
    $script['call_sites'] = [];

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeTrue();
    expect(implode(' ', $result['extraction_alarm']['issues']))->toContain('No encrypt call sites');
});

it('raises the extraction alarm when the live ground-truth output is missing', function () {
    seedActive();

    $script = healthyScriptResult();
    $script['impl_check']['reserve'] = null;

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeTrue();
    expect($result['reserve_impl_match'])->toBeNull();
    expect(implode(' ', $result['extraction_alarm']['issues']))->toContain('ground-truth output could not be computed');
});

it('classifies a not-yet-shipped captcha version as a mid-rollout, not a structural failure', function () {
    seedActive();

    // Login config selects version 11, but the bundle dispatch table only ships 1-10
    // (IVAC mid-rollout). Reserve is fully resolved and unaffected.
    $liveReserve = CaptchaTokenTransformer::transformReserve(GT_TOKEN, GT_RESERVE_SECRET, 4, 22);

    $script = healthyScriptResult();
    $script['dispatch_versions'] = range(1, 10);
    $script['encrypt_meta']['login'] = ['module' => null, 'version' => 11, 'skip' => 5, 'enc_len' => 21, 'secret' => GT_LOGIN_SECRET];
    unset($script['impl_check']['login']);
    $script['impl_check']['reserve'] = $liveReserve;

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeTrue();
    expect($result['extraction_alarm']['severity'])->toBe('rollout');
    expect($result['extraction_alarm']['pending_rollout'])->toBe(['login']);
    expect($result['extraction_alarm']['unaffected'])->toContain('reserve');
    $joined = implode(' ', $result['extraction_alarm']['issues']);
    expect($joined)->toContain('mid-rollout');
    expect($joined)->toContain('version 11');
    // The generic structural messages must NOT fire for the rollout type.
    expect($joined)->not->toContain('no module is mapped in encrypt_meta');
});

it('still flags a true structural failure as severity structural', function () {
    seedActive();

    $script = healthyScriptResult();
    $script['live_modules'] = [];

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['severity'])->toBe('structural');
});

it('raises the extraction alarm when encrypt_meta has no module for a type', function () {
    seedActive();

    $script = healthyScriptResult();
    $script['encrypt_meta']['login']['module'] = null;

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeTrue();
    expect(implode(' ', $result['extraction_alarm']['issues']))->toContain('no module is mapped in encrypt_meta');
});

it('attributes from encrypt_meta when call-site labels are missing (identical-module redeploy)', function () {
    seedActive();

    $liveLogin = CaptchaTokenTransformer::transformLogin(GT_TOKEN, GT_LOGIN_SECRET, 7, 23);
    $liveReserve = CaptchaTokenTransformer::transformReserve(GT_TOKEN, GT_RESERVE_SECRET, 4, 22);

    // Reproduce the redeploy where login and reserve share the same module + params and
    // the raw call sites carry NO algorithm label and do not match the active DB rows,
    // so the secondary call-site attribution cannot label reserve. The Live-JS sidecar
    // still encrypts both types because encrypt_meta is complete — so the structural
    // alarm must NOT fire and both impl checks must still be computed.
    $script = healthyScriptResult();
    $script['call_sites'] = [
        ['var' => 'X', 'algorithm' => null, 'skip' => 9, 'enc_len' => 30, 'secret' => GT_LOGIN_SECRET],
        ['var' => 'Y', 'algorithm' => null, 'skip' => 9, 'enc_len' => 30, 'secret' => GT_RESERVE_SECRET],
    ];
    $script['encrypt_meta'] = [
        'login' => ['module' => 'J0', 'version' => 6, 'skip' => 7, 'enc_len' => 23, 'secret' => GT_LOGIN_SECRET],
        'reserve' => ['module' => 'A0', 'version' => 5, 'skip' => 4, 'enc_len' => 22, 'secret' => GT_RESERVE_SECRET],
    ];
    $script['impl_check'] = ['test_token' => GT_TOKEN, 'login' => $liveLogin, 'reserve' => $liveReserve];

    $result = fakeAlgorithmService($script)->analyze('proxy');

    expect($result['extraction_alarm']['triggered'])->toBeFalse();
    expect($result['extraction_alarm']['issues'])->toBe([]);
    expect($result['login_impl_match'])->toBeTrue();
    expect($result['reserve_impl_match'])->toBeTrue();
});

/**
 * Runs the controller against a mocked analyze() result and returns [$data, $disabledProviders].
 *
 * @param  array<string, mixed>  $overrides  merged over a clean, sidecar-healthy analysis result
 * @return array{0: array<string, mixed>, 1: array<int, CaptchaProvider>}
 */
function analyzeWithProviders(array $overrides): array
{
    seedActive();

    $user = User::factory()->create(['role' => 'super_admin']);
    $disabled1 = CaptchaProvider::factory()->create(['user_id' => $user->id, 'enabled' => false]);
    $disabled2 = CaptchaProvider::factory()->create(['user_id' => $user->id, 'enabled' => false]);

    $service = Mockery::mock(CaptchaAlgorithmService::class);
    $service->shouldReceive('analyze')->once()->andReturn(array_merge(
        healthyScriptResult(),
        [
            'snapshot_status' => 'unchanged',
            'auto_applied' => ['applied' => true, 'reason' => null, 'types' => ['login', 'reserve']],
            'engine' => ['sidecar' => ['healthy' => true]],
            'needs_attention' => null,
        ],
        $overrides,
    ));

    $versions = app(\App\Services\Captcha\CaptchaBundleVersionService::class);
    $controller = new \App\Http\Controllers\Api\CaptchaAlgorithmController($service, $versions);

    $request = \Illuminate\Http\Request::create('/api/captcha-algorithm/analyze', 'POST');
    $request->replace(['proxy' => 'http://proxy.example.com']);

    return [json_decode($controller->analyze($request)->getContent(), true), [$disabled1, $disabled2]];
}

it('enables disabled captcha providers after a clean analysis', function () {
    [$data, $providers] = analyzeWithProviders([]);

    expect($data['providers_enabled'])->toBe(2);
    expect($data['providers_withheld_reason'])->toBeNull();
    expect($providers[0]->fresh()->enabled)->toBeTrue();
    expect($providers[1]->fresh()->enabled)->toBeTrue();
});

it('keeps providers disabled when the extraction was unclean', function () {
    // analyze() returns error=null on the unclean path too: autoApplySeeds() kept the last-known-good
    // seeds and the previous bundle was restored to disk, so the sidecar is healthy but serving the
    // OLD bundle. Enabling here would release the bot to race a rotated deployment with stale
    // encryption — the exact midnight failure the kill switch exists to prevent.
    [$data, $providers] = analyzeWithProviders([
        'auto_applied' => ['applied' => false, 'reason' => 'login could not be fully resolved', 'types' => []],
    ]);

    expect($data['providers_enabled'])->toBe(0);
    expect($data['providers_withheld_reason'])->toContain('login could not be fully resolved');
    expect($providers[0]->fresh()->enabled)->toBeFalse();
    expect($providers[1]->fresh()->enabled)->toBeFalse();
});

it('keeps providers disabled when the sidecar is unhealthy', function () {
    [$data, $providers] = analyzeWithProviders(['engine' => ['sidecar' => ['healthy' => false]]]);

    expect($data['providers_enabled'])->toBe(0);
    expect($data['providers_withheld_reason'])->toContain('sidecar');
    expect($providers[0]->fresh()->enabled)->toBeFalse();
});

it('keeps providers disabled while a needs-attention alarm is raised', function () {
    [$data, $providers] = analyzeWithProviders([
        'needs_attention' => ['reason' => 'sidecar reload failed after activating the new bundle', 'at' => now()->toIso8601String()],
    ]);

    expect($data['providers_enabled'])->toBe(0);
    expect($data['providers_withheld_reason'])->toContain('sidecar reload failed');
    expect($providers[0]->fresh()->enabled)->toBeFalse();
});

it('does not enable captcha providers when analysis returns an error', function () {
    seedActive();

    $user = User::factory()->create(['role' => 'super_admin']);
    $disabled = CaptchaProvider::factory()->create(['user_id' => $user->id, 'enabled' => false]);

    $service = Mockery::mock(CaptchaAlgorithmService::class);
    $service->shouldReceive('analyze')
        ->once()
        ->andReturn(['error' => 'Failed to fetch main page: HTTP 403', 'logs' => []]);

    $versions = app(\App\Services\Captcha\CaptchaBundleVersionService::class);
    $controller = new \App\Http\Controllers\Api\CaptchaAlgorithmController($service, $versions);

    $request = \Illuminate\Http\Request::create('/api/captcha-algorithm/analyze', 'POST');
    $request->replace(['proxy' => 'http://proxy.example.com']);

    $response = $controller->analyze($request);
    $data = json_decode($response->getContent(), true);

    expect($data['providers_enabled'])->toBe(0);
    expect($disabled->fresh()->enabled)->toBeFalse();
});
