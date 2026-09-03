<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\CaptchaNode;
use App\Models\Setting;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Services\CaptchaSolverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

const PIPELINE_SITE_KEY = '0x4AAAAAACghKkJHL1t7UkuZ';
const PIPELINE_PAGE_URL = 'https://appointment.ivacbd.com/';
const PIPELINE_RAW_TOKEN = '0.rawTurnstileTokenFromLocalChrome';

function inHouseProvider(): CaptchaProvider
{
    return CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::InHouse,
        'enabled' => true,
        'api_key' => null,
    ]);
}

function pendingCaptchaRequest(): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'on_demand',
    ]);
}

function handleSolveJob(CaptchaRequest $request, ?int $providerId): void
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

    Setting::instance()->update([
        'captcha_site_key' => PIPELINE_SITE_KEY,
        'captcha_page_url' => PIPELINE_PAGE_URL,
    ]);
});

describe('SolveCaptchaJob with an in-house provider', function () {
    it('hands the request to the solver fleet instead of blocking the queue worker', function () {
        $provider = inHouseProvider();
        CaptchaNode::factory()->create(['reported_concurrency' => 8]);
        $request = pendingCaptchaRequest();

        Http::fake();

        handleSolveJob($request, $provider->id);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->provider_id)->toBe($provider->id)
            // Never enters the vendor poller's queue: that path keys off vendor_task_id.
            ->and($request->vendor_task_id)->toBeNull();

        expect(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(1);

        // The solve happens on a node. Nothing here talks to a local Chrome any more,
        // which is what frees the worker after milliseconds instead of ~5 seconds.
        Http::assertNothingSent();
    });

    it('delivers the configured settings to the node, so a site key rotation is picked up', function () {
        $provider = inHouseProvider();
        $node = CaptchaNode::factory()->create(['reported_concurrency' => 8]);

        Setting::instance()->update([
            'captcha_site_key' => '0xROTATEDKEY123456',
            'captcha_page_url' => 'https://example.test/',
        ]);

        handleSolveJob(pendingCaptchaRequest(), $provider->id);

        $work = app(CaptchaNodeFleet::class)->lease($node, 1);

        expect($work)->toHaveCount(1)
            ->and($work[0]['site_key'])->toBe('0xROTATEDKEY123456')
            ->and($work[0]['page_url'])->toBe('https://example.test/');
    });

    it('caps the node solve budget below the lease TTL so a failure can still be reported', function () {
        expect(CaptchaNodeFleet::SOLVE_BUDGET_MS)
            ->toBeLessThan(CaptchaNodeFleet::LEASE_TTL_SECONDS * 1000);
    });

    it('releases the provider slot and counts the solve when the node reports back', function () {
        $provider = inHouseProvider();
        $node = CaptchaNode::factory()->create(['reported_concurrency' => 8]);
        $request = pendingCaptchaRequest();

        handleSolveJob($request, $provider->id);
        app(CaptchaNodeFleet::class)->lease($node, 1);

        Redis::set("captcha:provider:{$provider->id}:active", 1);

        app(CaptchaNodeFleet::class)->complete($node, $request->request_id, PIPELINE_RAW_TOKEN, null);

        expect($request->fresh()->token)->toBe(PIPELINE_RAW_TOKEN)
            ->and((int) Redis::get("captcha:provider:{$provider->id}:active"))->toBe(0)
            ->and((int) Redis::get("captcha:provider:{$provider->id}:count"))->toBeGreaterThan(0);
    });

    it('records the node error and releases the slot when a solve fails', function () {
        $provider = inHouseProvider();
        $node = CaptchaNode::factory()->create(['reported_concurrency' => 8]);
        $request = pendingCaptchaRequest();

        handleSolveJob($request, $provider->id);
        app(CaptchaNodeFleet::class)->lease($node, 1);

        Redis::set("captcha:provider:{$provider->id}:active", 1);

        app(CaptchaNodeFleet::class)
            ->complete($node, $request->request_id, null, 'solver saturated (9 active, 32 queued)');

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->error_message)->toContain('solver saturated')
            ->and($request->token)->toBeNull()
            ->and((int) Redis::get("captcha:provider:{$provider->id}:active"))->toBe(0);
    });

    it('still submits a vendor task for a paid provider', function () {
        $provider = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => true,
        ]);
        $request = pendingCaptchaRequest();

        Http::fake([
            '*/createTask' => Http::response(['errorId' => 0, 'taskId' => 987654], 200),
        ]);

        handleSolveJob($request, $provider->id);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->vendor_task_id)->toBe('987654');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'capmonster.cloud/createTask'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/solve'));
    });
});

describe('CaptchaSolverService rejects the in-house type', function () {
    it('refuses to build a vendor task for it', function () {
        $provider = inHouseProvider();
        Http::fake();

        expect(fn () => app(CaptchaSolverService::class)
            ->createTask($provider, PIPELINE_SITE_KEY, PIPELINE_PAGE_URL))
            ->toThrow(RuntimeException::class, 'no task API');

        // The guard exists so an empty api_key is never posted to a vendor endpoint.
        Http::assertNothingSent();
    });

    it('refuses to poll a vendor task result for it', function () {
        $provider = inHouseProvider();
        Http::fake();

        expect(fn () => app(CaptchaSolverService::class)->getTaskResult($provider, 'task-1'))
            ->toThrow(RuntimeException::class, 'no task API');

        Http::assertNothingSent();
    });

    it('reports that a self-hosted solver has no balance', function () {
        $provider = inHouseProvider();
        Http::fake();

        expect(fn () => app(CaptchaSolverService::class)->getBalance($provider))
            ->toThrow(RuntimeException::class, 'no vendor balance');

        Http::assertNothingSent();
    });

    it('returns 422 from the balance endpoint rather than a bogus figure', function () {
        $provider = inHouseProvider();
        Http::fake();

        $this->actingAs(App\Models\User::factory()->create(['role' => 'super_admin']))
            ->getJson("/api/captcha-providers/{$provider->id}/balance")
            ->assertStatus(422)
            ->assertJsonPath('error', 'The in-house solver is self-hosted and has no vendor balance.');
    });
});

describe('provider management', function () {
    it('accepts in_house as a provider type for an admin', function () {
        $this->actingAs(App\Models\User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha-providers', [
                'name' => 'Local Chrome',
                'type' => 'in_house',
                'enabled' => true,
                'solver_threads' => 4,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('captcha_providers', ['name' => 'Local Chrome', 'type' => 'in_house']);
    });

    // The providers page is open to managers and agents, but the solver behind this type
    // is a super-admin subsystem — so the type must not become a way around that gate.
    it('refuses in_house to a non-admin who can otherwise manage providers', function (string $role) {
        $this->actingAs(App\Models\User::factory()->create(['role' => $role]))
            ->postJson('/api/captcha-providers', [
                'name' => 'Sneaky Local',
                'type' => 'in_house',
                'enabled' => true,
                'solver_threads' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('captcha_providers', ['type' => 'in_house']);
    })->with(['manager', 'agent']);

    it('refuses a non-admin switching an existing provider to in_house', function () {
        $manager = App\Models\User::factory()->create(['role' => 'manager']);
        $provider = CaptchaProvider::factory()->create([
            'user_id' => $manager->id,
            'type' => CaptchaProviderType::CapMonster,
        ]);

        $this->actingAs($manager)
            ->putJson("/api/captcha-providers/{$provider->id}", ['type' => 'in_house'])
            ->assertForbidden();

        expect($provider->fresh()->type)->toBe(CaptchaProviderType::CapMonster);
    });

    it('still lets a non-admin create a vendor provider', function () {
        $this->actingAs(App\Models\User::factory()->create(['role' => 'manager']))
            ->postJson('/api/captcha-providers', [
                'name' => 'Their CapMonster',
                'type' => 'capmonster',
                'enabled' => false,
                'api_key' => 'key-123',
                'solver_threads' => 1,
            ])
            ->assertSuccessful();
    });
});
