<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaNode;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Services\CaptchaSolverService;
use App\Support\CaptchaGenerationMode;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    // Fleet capacity is cached in Redis and the generation mode is read from it, so a
    // value left by an earlier test file silently decides this one's outcome.
    Redis::flushdb();
});

it('solves a captcha using a disabled provider', function () {
    // Only ALL mode may spend a disabled provider's credit — under the ACTIVE default
    // this same job is expected to fail instead, which the next test covers.
    CaptchaGenerationMode::set(CaptchaGenerationMode::ALL);

    $provider = CaptchaProvider::factory()->create(['enabled' => false]);

    $request = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);

    $this->mock(CaptchaSolverService::class, function ($mock) {
        $mock->shouldReceive('createTask')->once()->andReturn('vendor-task-1');
    });

    (new SolveCaptchaJob($request->id, $provider->id))->handle(app(CaptchaSolverService::class), app(CaptchaNodeFleet::class), app(CaptchaRaceCoordinator::class));

    $request->refresh();
    expect($request->status)->toBe(CaptchaRequestStatus::Processing);
    expect($request->provider_id)->toBe($provider->id);
});

it('fails the request only when no providers are configured at all', function () {
    $request = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);

    (new SolveCaptchaJob($request->id))->handle(app(CaptchaSolverService::class), app(CaptchaNodeFleet::class), app(CaptchaRaceCoordinator::class));

    $request->refresh();
    expect($request->status)->toBe(CaptchaRequestStatus::Failed);
    expect($request->error_message)->toBe('No captcha providers configured.');
});

function solveJobSlotLimitFor(CaptchaProvider $provider): int
{
    $method = new ReflectionMethod(SolveCaptchaJob::class, 'slotLimitFor');

    return $method->invoke(new SolveCaptchaJob(1), $provider, app(CaptchaNodeFleet::class));
}

it('caps a vendor at its own solver_threads, not a shared global budget', function () {
    Redis::del('captcha:slots_per_provider');

    // Two keys provisioned for different parallelism. A single global budget mis-sized
    // both; the ceiling has to come from the row.
    $small = CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::CapMonster,
        'solver_threads' => 2,
    ]);
    $large = CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::CapSolver,
        'solver_threads' => 9,
    ]);

    expect(solveJobSlotLimitFor($small))->toBe(2);
    expect(solveJobSlotLimitFor($large))->toBe(9);
});

it('keeps a vendor saved with zero threads in the rotation at one slot', function () {
    $vendor = CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::CapMonster,
        'solver_threads' => 0,
    ]);

    // Zero would drop the vendor out of dispatch entirely rather than making it slow,
    // which reads as a dead provider instead of a throttled one.
    expect(solveJobSlotLimitFor($vendor))->toBe(1);
});

it('caps the in-house fleet on reported node concurrency, ignoring solver_threads', function () {
    $inHouse = CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::InHouse,
        'solver_threads' => 2,
    ]);
    CaptchaNode::factory()->create(['reported_concurrency' => 8]);

    // 8 reported x 1.5 queue factor — the fleet ceiling, not the row's thread count.
    expect(solveJobSlotLimitFor($inHouse))->toBe(app(CaptchaNodeFleet::class)->queueLimit());
    expect(solveJobSlotLimitFor($inHouse))->toBe(12);
});
