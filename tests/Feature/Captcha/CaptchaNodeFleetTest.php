<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Models\CaptchaNode;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\CaptchaNodeFleet;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

const FLEET_SITE_KEY = '0x4AAAAAACghKkJHL1t7UkuZ';
const FLEET_PAGE_URL = 'https://appointment.ivacbd.com/';
const FLEET_RAW_TOKEN = '0.rawTurnstileTokenFromANode';

function fleetProvider(): CaptchaProvider
{
    return CaptchaProvider::factory()->create([
        'type' => CaptchaProviderType::InHouse,
        'enabled' => true,
        'api_key' => null,
    ]);
}

function queuedRequest(CaptchaProvider $provider, ?CaptchaNode $target = null): CaptchaRequest
{
    $request = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);

    app(CaptchaNodeFleet::class)->enqueue($request, $provider->id, $target);

    return $request->fresh();
}

beforeEach(function () {
    // Provider slot counters are keyed by provider ID, and RefreshDatabase restarts those
    // at 1 every test — so without a flush one test's leftover :active count silently
    // changes the next test's capacity maths. phpunit.xml pins tests to Redis db 15.
    Redis::flushdb();

    Setting::instance()->update([
        'captcha_site_key' => FLEET_SITE_KEY,
        'captcha_page_url' => FLEET_PAGE_URL,
    ]);
});

describe('heartbeat', function () {
    it('marks the node online and records what it reported', function () {
        $node = CaptchaNode::factory()->offline()->create();

        $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/heartbeat', [
                'worker_state' => 'solving',
                'hostname' => 'solver-01',
                'script_version' => 'abc123def456',
                'cpu_cores' => 16,
                'reported_concurrency' => 16,
                'active' => 3,
                'solved' => 120,
                'failed' => 2,
                'avg_ms' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('site_key', FLEET_SITE_KEY)
            ->assertJsonPath('page_url', FLEET_PAGE_URL);

        $node->refresh();

        expect($node->status)->toBe('online')
            ->and($node->worker_state)->toBe('solving')
            ->and($node->hostname)->toBe('solver-01')
            ->and($node->reported_concurrency)->toBe(16)
            ->and($node->solved)->toBe(120);
    });

    it('hands over a pending command exactly once', function () {
        $node = CaptchaNode::factory()->create(['pending_command' => 'pause']);

        $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('pending_command', 'pause');

        // Consumed on read, so a node acts on it once — same contract as the bot's slot
        // heartbeat. Without this a 'restart_browsers' would fire on every check-in.
        $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('pending_command', null);
    });

    it('pushes the portal-set concurrency back so a node restart cannot silently revert it', function () {
        $node = CaptchaNode::factory()->create(['concurrency' => 12]);

        $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('desired_concurrency', 12);
    });

    it('rejects an unknown key', function () {
        $this->withToken('not-a-real-key')
            ->postJson('/api/captcha-nodes/heartbeat', [])
            ->assertStatus(401);
    });
});

describe('lease', function () {
    it('claims queued work and stamps ownership on it', function () {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->create();
        $request = queuedRequest($provider);

        $response = $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/lease', ['capacity' => 4])
            ->assertOk();

        expect($response->json('work'))->toHaveCount(1)
            ->and($response->json('work.0.request_id'))->toBe($request->request_id)
            ->and($response->json('work.0.site_key'))->toBe(FLEET_SITE_KEY)
            ->and($response->json('work.0.timeout_ms'))->toBe(CaptchaNodeFleet::SOLVE_BUDGET_MS);

        $request->refresh();

        expect($request->node_id)->toBe($node->id)
            ->and($request->leased_at)->not->toBeNull()
            ->and($request->lease_expires_at)->not->toBeNull();
    });

    it('never hands the same request to two nodes', function () {
        $provider = fleetProvider();
        $first = CaptchaNode::factory()->create();
        $second = CaptchaNode::factory()->create();
        queuedRequest($provider);

        $a = $this->withToken($first->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 4]);
        $b = $this->withToken($second->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 4]);

        expect(count($a->json('work')) + count($b->json('work')))->toBe(1);
    });

    it('honours the capacity the node asks for', function () {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->create();

        collect(range(1, 5))->each(fn () => queuedRequest($provider));

        $response = $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/lease', ['capacity' => 2]);

        expect($response->json('work'))->toHaveCount(2);
        expect(Redis::llen(CaptchaNodeFleet::QUEUE_KEY))->toBe(3);
    });

    it('serves a node-targeted request only to that node', function () {
        $provider = fleetProvider();
        $target = CaptchaNode::factory()->create();
        $other = CaptchaNode::factory()->create();

        queuedRequest($provider, $target);

        expect($this->withToken($other->api_key)
            ->postJson('/api/captcha-nodes/lease', ['capacity' => 4])->json('work'))->toHaveCount(0);

        expect($this->withToken($target->api_key)
            ->postJson('/api/captcha-nodes/lease', ['capacity' => 4])->json('work'))->toHaveCount(1);
    });

    it('gives nothing to a paused or disabled node', function (string $state) {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->{$state}()->create();
        queuedRequest($provider);

        expect($this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/lease', ['capacity' => 4])->json('work'))->toHaveCount(0);
    })->with(['paused', 'disabled']);
});

describe('result', function () {
    it('stores the raw token and marks the request ready', function () {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->create();
        $request = queuedRequest($provider);

        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 1]);

        $this->withToken($node->api_key)
            ->postJson('/api/captcha-nodes/result', [
                'results' => [['request_id' => $request->request_id, 'token' => FLEET_RAW_TOKEN, 'ms' => 2400]],
            ])
            ->assertOk()
            ->assertJsonPath('accepted', 1);

        $request->refresh();

        // Raw on purpose: the login/reserve transform is applied by
        // CaptchaRequestController::show() at poll time, and needs portal-only bundle state.
        expect($request->status)->toBe(CaptchaRequestStatus::Ready)
            ->and($request->token)->toBe(FLEET_RAW_TOKEN)
            ->and($request->solved_at)->not->toBeNull()
            ->and($request->vendor_task_id)->toBeNull();
    });

    it('is a no-op when the same result is reported twice', function () {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->create();
        $request = queuedRequest($provider);

        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 1]);

        $payload = ['results' => [['request_id' => $request->request_id, 'token' => FLEET_RAW_TOKEN]]];

        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/result', $payload)
            ->assertJsonPath('accepted', 1);

        // A retry after a network blip must not release the provider slot a second time.
        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/result', $payload)
            ->assertJsonPath('accepted', 0);
    });

    it('records a failure when the node could not solve', function () {
        $provider = fleetProvider();
        $node = CaptchaNode::factory()->create();
        $request = queuedRequest($provider);

        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 1]);

        $this->withToken($node->api_key)->postJson('/api/captcha-nodes/result', [
            'results' => [['request_id' => $request->request_id, 'error' => 'Waiting failed: 10000ms exceeded']],
        ])->assertJsonPath('accepted', 1);

        $request->refresh();

        expect($request->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->error_message)->toContain('Waiting failed');
    });

    it('ignores a result for work the node does not own', function () {
        $provider = fleetProvider();
        $owner = CaptchaNode::factory()->create();
        $stranger = CaptchaNode::factory()->create();
        $request = queuedRequest($provider);

        $this->withToken($owner->api_key)->postJson('/api/captcha-nodes/lease', ['capacity' => 1]);

        $this->withToken($stranger->api_key)->postJson('/api/captcha-nodes/result', [
            'results' => [['request_id' => $request->request_id, 'token' => 'stolen']],
        ])->assertJsonPath('accepted', 0);

        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Processing);
    });
});

describe('capacity', function () {
    it('sums only the nodes that can actually take work', function () {
        CaptchaNode::factory()->create(['reported_concurrency' => 8]);
        CaptchaNode::factory()->create(['reported_concurrency' => 4]);
        CaptchaNode::factory()->offline()->create(['reported_concurrency' => 16]);
        CaptchaNode::factory()->paused()->create(['reported_concurrency' => 16]);
        CaptchaNode::factory()->disabled()->create(['reported_concurrency' => 16]);

        expect(app(CaptchaNodeFleet::class)->refreshCapacity())->toBe(12);
    });

    it('is zero with no nodes, which is what makes vendors take over', function () {
        expect(app(CaptchaNodeFleet::class)->refreshCapacity())->toBe(0)
            ->and(app(CaptchaNodeFleet::class)->queueLimit())->toBe(0);
    });

    it('allows a little queueing above raw capacity so nodes never run dry between polls', function () {
        CaptchaNode::factory()->create(['reported_concurrency' => 10]);

        expect(app(CaptchaNodeFleet::class)->queueLimit())->toBeGreaterThan(10);
    });
});

describe('script distribution', function () {
    it('serves the solver to a node with its content version', function () {
        $node = CaptchaNode::factory()->create();

        $expected = substr(hash_file('sha256', app_path('Scripts/in_house_captcha_solver.cjs')), 0, 12);

        $this->withToken($node->api_key)
            ->get('/api/captcha-nodes/script')
            ->assertOk()
            ->assertHeader('X-Script-Version', $expected);
    });

    it('refuses an unknown key', function () {
        $this->get('/api/captcha-nodes/script')->assertStatus(401);
    });
});

describe('provisioning', function () {
    it('tells the installer the concurrency the portal picked, before the node has checked in', function () {
        $node = CaptchaNode::factory()->offline()->create(['concurrency' => 12, 'profile' => 'shared']);

        $this->withToken($node->api_key)
            ->getJson('/api/captcha-nodes/provisioning')
            ->assertOk()
            ->assertJsonPath('desired_concurrency', 12)
            ->assertJsonPath('profile', 'shared');
    });

    it('reports no preference when none was set, so the installer sizes from cores', function () {
        $node = CaptchaNode::factory()->create(['concurrency' => null]);

        $this->withToken($node->api_key)
            ->getJson('/api/captcha-nodes/provisioning')
            ->assertOk()
            ->assertJsonPath('desired_concurrency', null);
    });

    it('does not mark the node online — installing is not checking in', function () {
        $node = CaptchaNode::factory()->offline()->create();

        $this->withToken($node->api_key)->getJson('/api/captcha-nodes/provisioning')->assertOk();

        expect($node->fresh()->status)->toBe('offline');
    });

    it('refuses an unknown key', function () {
        $this->getJson('/api/captcha-nodes/provisioning')->assertStatus(401);
    });
});

describe('console', function () {
    it('flags a node running an older script', function () {
        CaptchaNode::factory()->create(['script_version' => 'stale00stale']);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->getJson('/api/captcha-nodes')
            ->assertOk()
            ->assertJsonPath('nodes.0.update_available', true);
    });

    it('creates a node with its own key', function () {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha-nodes', ['name' => 'solver-a', 'profile' => 'shared'])
            ->assertCreated()
            ->assertJsonPath('profile', 'shared');

        expect(CaptchaNode::where('name', 'solver-a')->first()->api_key)->toHaveLength(64);
    });

    it('accepts a concurrency chosen at creation time', function () {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha-nodes', ['name' => 'solver-b', 'concurrency' => 12])
            ->assertCreated()
            ->assertJsonPath('concurrency', 12);
    });

    it('leaves concurrency unset when none is given, so the installer sizes from cores', function () {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha-nodes', ['name' => 'solver-c'])
            ->assertCreated()
            ->assertJsonPath('concurrency', null);
    });

    it('queues a concurrency change as a command so it lands without waiting a cycle', function () {
        $node = CaptchaNode::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patchJson("/api/captcha-nodes/{$node->id}", ['concurrency' => 6])
            ->assertOk();

        expect($node->fresh()->pending_command)->toBe('set_concurrency:6');
    });

    it('refuses a command the node does not implement', function () {
        $node = CaptchaNode::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson("/api/captcha-nodes/{$node->id}/command", ['command' => 'rm -rf'])
            ->assertStatus(422);
    });

    it('keeps the fleet console out of a manager\'s reach', function () {
        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->getJson('/api/captcha-nodes')
            ->assertForbidden();
    });
});
