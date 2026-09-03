<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaNode;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Services\CaptchaSolverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function fleetDispatchProvider(CaptchaProviderType $type): CaptchaProvider
{
    return CaptchaProvider::factory()->create([
        'type' => $type,
        'enabled' => true,
        'api_key' => $type === CaptchaProviderType::InHouse ? null : 'vendor-key',
    ]);
}

function fleetPendingRequest(): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'on_demand',
    ]);
}

function dispatchSolveJob(CaptchaRequest $request, ?int $providerId = null): void
{
    (new SolveCaptchaJob($request->id, $providerId))->handle(
        app(CaptchaSolverService::class),
        app(CaptchaNodeFleet::class),
        app(CaptchaRaceCoordinator::class),
    );
}

beforeEach(function () {
    // Provider slot counters are keyed by provider ID, and RefreshDatabase restarts those
    // at 1 every test — so without a flush one test's leftover :active count silently
    // changes the next test's capacity maths. phpunit.xml pins tests to Redis db 15.
    Redis::flushdb();
    Redis::del('captcha:slots_per_provider');

    Setting::instance()->update([
        'captcha_site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
        'captcha_page_url' => 'https://appointment.ivacbd.com/',
    ]);
});

describe('in-house dispatch', function () {
    it('queues the request for the fleet instead of solving it in the worker', function () {
        $provider = fleetDispatchProvider(CaptchaProviderType::InHouse);
        CaptchaNode::factory()->create(['reported_concurrency' => 8]);
        $request = fleetPendingRequest();

        Http::fake();

        dispatchSolveJob($request, $provider->id);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->provider_id)->toBe($provider->id)
            ->and($request->node_id)->toBeNull()
            ->and($request->token)->toBeNull()
            // Nothing carries a vendor task ID, so the vendor poller still ignores these.
            ->and($request->vendor_task_id)->toBeNull();

        expect(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(1);

        // The whole point of the change: no headless Chrome is touched by the queue worker.
        Http::assertNothingSent();
    });

    it('fails fast rather than spinning when it is pinned to a fleet with no nodes', function () {
        $provider = fleetDispatchProvider(CaptchaProviderType::InHouse);
        $request = fleetPendingRequest();

        dispatchSolveJob($request, $provider->id);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->error_message)->toContain('No captcha solver nodes are online')
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(0);
    });

    it('falls through to a vendor when the fleet is offline and nothing is pinned', function () {
        fleetDispatchProvider(CaptchaProviderType::InHouse);
        fleetDispatchProvider(CaptchaProviderType::CapMonster);
        $request = fleetPendingRequest();

        Http::fake(['*/createTask' => Http::response(['errorId' => 0, 'taskId' => 42], 200)]);

        dispatchSolveJob($request);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->vendor_task_id)->toBe('42')
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(0);
    });
});

describe('provider preference', function () {
    it('prefers the fleet over a paid provider while it has capacity', function () {
        $inHouse = fleetDispatchProvider(CaptchaProviderType::InHouse);
        fleetDispatchProvider(CaptchaProviderType::CapMonster);
        CaptchaNode::factory()->create(['reported_concurrency' => 8]);

        Http::fake(['*/createTask' => Http::response(['errorId' => 0, 'taskId' => 1], 200)]);

        // Repeated because the vendor list is shuffled; a preference that only held some of
        // the time would still burn credit.
        foreach (range(1, 5) as $i) {
            $request = fleetPendingRequest();
            dispatchSolveJob($request);

            expect($request->fresh()->provider_id)->toBe($inHouse->id);
        }

        Http::assertNothingSent();
    });

    it('spills to a vendor once the fleet is saturated', function () {
        $inHouse = fleetDispatchProvider(CaptchaProviderType::InHouse);
        $vendor = fleetDispatchProvider(CaptchaProviderType::CapMonster);
        CaptchaNode::factory()->create(['reported_concurrency' => 1]);

        Http::fake(['*/createTask' => Http::response(['errorId' => 0, 'taskId' => 7], 200)]);

        $limit = app(CaptchaNodeFleet::class)->queueLimit();

        // Fill the fleet's budget first.
        foreach (range(1, $limit) as $i) {
            dispatchSolveJob(fleetPendingRequest());
        }

        $overflow = fleetPendingRequest();
        dispatchSolveJob($overflow);

        expect($overflow->fresh()->provider_id)->toBe($vendor->id)
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe($limit)
            ->and($inHouse->id)->not->toBe($vendor->id);
    });
});
