<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Models\CaptchaNode;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Services\Captcha\CaptchaNodeFleet;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function reaperProvider(): CaptchaProvider
{
    return CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::InHouse,
        'enabled' => true,
        'api_key' => null,
    ]);
}

function leasedRequest(CaptchaProvider $provider, CaptchaNode $node, int $attempts = 0): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Processing,
        'source' => 'pool',
        'provider_id' => $provider->id,
        'node_id' => $node->id,
        'leased_at' => now()->subSeconds(60),
        'lease_expires_at' => now()->subSeconds(20),
        'lease_attempts' => $attempts,
    ]);
}

/**
 * Age a row's timestamps.
 *
 * Has to go through the query builder: Eloquent's update() refreshes updated_at on save,
 * so setting it as an attribute silently does nothing.
 *
 * @param  array<string, \Illuminate\Support\Carbon>  $timestamps
 */
function ageRequest(CaptchaRequest $request, array $timestamps): void
{
    CaptchaRequest::whereKey($request->id)->toBase()->update($timestamps);
}

beforeEach(function () {
    // Provider slot counters are keyed by provider ID, and RefreshDatabase restarts those
    // at 1 every test — so without a flush one test's leftover :active count silently
    // changes the next test's capacity maths. phpunit.xml pins tests to Redis db 15.
    Redis::flushdb();
});

describe('expired leases', function () {
    it('requeues work from a node that died mid-solve', function () {
        $provider = reaperProvider();
        $node = CaptchaNode::factory()->create();
        $request = leasedRequest($provider, $node);

        $result = app(CaptchaNodeFleet::class)->reapExpiredLeases();

        expect($result['requeued'])->toBe(1)
            ->and($result['failed'])->toBe(0);

        $request->refresh();

        // Back to unowned and Processing, so the next node to poll can claim it.
        expect($request->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->node_id)->toBeNull()
            ->and($request->lease_attempts)->toBe(1)
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(1);
    });

    it('gives up on the second expiry rather than cycling a poisoned request forever', function () {
        $provider = reaperProvider();
        $node = CaptchaNode::factory()->create();
        $request = leasedRequest($provider, $node, attempts: 1);

        $result = app(CaptchaNodeFleet::class)->reapExpiredLeases();

        expect($result['failed'])->toBe(1);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->error_message)->toContain('lease expired twice')
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(0);
    });

    it('leaves a lease that is still live alone', function () {
        $provider = reaperProvider();
        $node = CaptchaNode::factory()->create();

        $request = leasedRequest($provider, $node);
        $request->update(['lease_expires_at' => now()->addSeconds(30)]);

        expect(app(CaptchaNodeFleet::class)->reapExpiredLeases()['requeued'])->toBe(0);
        expect($request->fresh()->node_id)->toBe($node->id);
    });
});

describe('orphaned work', function () {
    it('re-pushes a request that never reached the queue', function () {
        $provider = reaperProvider();

        // Marked Processing but the LPUSH never happened — a crash between the two, or a
        // Redis flush. Without this sweep it would sit there until it timed out.
        $request = CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Processing,
            'source' => 'pool',
            'provider_id' => $provider->id,
        ]);
        ageRequest($request, ['updated_at' => now()->subSeconds(30)]);

        expect(app(CaptchaNodeFleet::class)->reapExpiredLeases()['requeued'])->toBe(1)
            ->and(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(1);
    });

    it('abandons work nobody has picked up long after every consumer gave up', function () {
        $provider = reaperProvider();

        $request = CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Processing,
            'source' => 'pool',
            'provider_id' => $provider->id,
        ]);
        ageRequest($request, ['created_at' => now()->subSeconds(200), 'updated_at' => now()->subSeconds(200)]);

        expect(app(CaptchaNodeFleet::class)->reapExpiredLeases()['failed'])->toBe(1);
        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Failed);
    });

    it('ignores vendor work, which the task poller owns', function () {
        $provider = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => true,
        ]);

        $request = CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Processing,
            'source' => 'pool',
            'provider_id' => $provider->id,
            'vendor_task_id' => '12345',
        ]);
        ageRequest($request, ['updated_at' => now()->subSeconds(30)]);

        $result = app(CaptchaNodeFleet::class)->reapExpiredLeases();

        expect($result['requeued'])->toBe(0)
            ->and($result['failed'])->toBe(0)
            ->and($request->fresh()->status)->toBe(CaptchaRequestStatus::Processing);
    });
});
