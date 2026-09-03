<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\User;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Services\CaptchaSolverService;
use App\Support\CaptchaGenerationMode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function activeOnlyRequest(): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);
}

function runSolveJob(int $requestId, ?int $providerId = null): void
{
    (new SolveCaptchaJob($requestId, $providerId))->handle(
        app(CaptchaSolverService::class),
        app(CaptchaNodeFleet::class),
        app(CaptchaRaceCoordinator::class),
    );
}

beforeEach(function () {
    // Slot counters live on a Redis shared with production. The spy returns null for
    // every read, so CaptchaGenerationMode falls back to its ACTIVE default — which is
    // what these first cases exercise.
    Redis::spy();
});

describe('active-only mode', function () {
    it('does not submit a task for a disabled provider', function () {
        $disabled = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => false,
        ]);
        $request = activeOnlyRequest();
        Http::fake();

        runSolveJob($request->id, $disabled->id);

        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Failed);
        Http::assertNothingSent();
    });

    it('picks an enabled provider and skips the disabled ones', function () {
        CaptchaProvider::factory()->count(3)->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => false,
        ]);
        $enabled = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => true,
        ]);
        $request = activeOnlyRequest();

        Http::fake(['*/createTask' => Http::response(['errorId' => 0, 'taskId' => 42], 200)]);

        runSolveJob($request->id);

        expect($request->fresh()->provider_id)->toBe($enabled->id);
    });

    it('fails the request when providers exist but none are enabled', function () {
        CaptchaProvider::factory()->count(2)->create(['enabled' => false]);
        $request = activeOnlyRequest();
        Http::fake();

        runSolveJob($request->id);

        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->fresh()->error_message)->toBe('No captcha providers are enabled.');
    });

    it('still reports the original message when no providers exist at all', function () {
        $request = activeOnlyRequest();
        Http::fake();

        runSolveJob($request->id);

        expect($request->fresh()->error_message)->toBe('No captcha providers configured.');
    });

    // Without this the enabled filter turns "nothing eligible" into an endless
    // re-dispatch every second, because that is the branch for a full slot pool.
    it('does not re-queue itself forever when nothing is eligible', function () {
        CaptchaProvider::factory()->create(['enabled' => false]);
        $request = activeOnlyRequest();
        Http::fake();
        Queue::fake();

        runSolveJob($request->id);

        Queue::assertNothingPushed();
    });

    it('stops solving for a provider disabled after dispatch', function () {
        $provider = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => true,
        ]);
        $request = activeOnlyRequest();
        Http::fake();
        Queue::fake();

        $provider->update(['enabled' => false]);
        runSolveJob($request->id, $provider->id);

        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Failed)
            ->and($request->fresh()->error_message)->toContain('was disabled');

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    });
});

describe('all-providers mode', function () {
    beforeEach(function () {
        // Redis::spy() would answer every read with null; this pins the stored mode to
        // ALL so the job takes the original, unfiltered path.
        Redis::shouldReceive('get')->with('captcha:generation_mode')->andReturn(CaptchaGenerationMode::ALL);
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('incr')->andReturn(1);
        Redis::shouldReceive('decr', 'set', 'del')->andReturnNull();
    });

    it('solves with a disabled provider, as it did before the mode existed', function () {
        $disabled = CaptchaProvider::factory()->create([
            'type' => CaptchaProviderType::CapMonster,
            'enabled' => false,
        ]);
        $request = activeOnlyRequest();

        Http::fake(['*/createTask' => Http::response(['errorId' => 0, 'taskId' => 77], 200)]);

        runSolveJob($request->id, $disabled->id);

        expect($request->fresh()->status)->toBe(CaptchaRequestStatus::Processing)
            ->and($request->fresh()->provider_id)->toBe($disabled->id);
    });

    it('still fails when no providers exist at all', function () {
        $request = activeOnlyRequest();
        Http::fake();

        runSolveJob($request->id);

        expect($request->fresh()->error_message)->toBe('No captcha providers configured.');
    });
});

describe('POST /api/captcha/control/start', function () {
    it('refuses active-only when every provider is disabled', function () {
        CaptchaProvider::factory()->count(2)->create(['enabled' => false]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha/control/start', ['mode' => 'active'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_active_providers');
    });

    it('refuses either mode when no providers exist', function (string $mode) {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha/control/start', ['mode' => $mode])
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_providers')
            ->assertJsonPath('detail', 'No captcha providers exist yet. Add one on Captcha Providers first.');
    })->with(['all', 'active']);

    it('rejects an unknown mode', function () {
        CaptchaProvider::factory()->create(['enabled' => true]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha/control/start', ['mode' => 'everything'])
            ->assertStatus(422);
    });

    // All-providers mode is the point of the second button: disabled rows are eligible,
    // so a start that active-only would refuse must succeed here.
    it('allows all-providers mode when every provider is disabled', function () {
        CaptchaProvider::factory()->count(2)->create(['enabled' => false]);

        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson('/api/captcha/control/start', ['mode' => 'all']);

        // 409 here would mean the mode was ignored; a 500 only means systemctl is not
        // available to the test user, which is not what this asserts.
        expect($response->status())->not->toBe(409);
    });
});

describe('GET /api/captcha/control/status', function () {
    it('reports the mode and both provider counts the buttons gate on', function () {
        CaptchaProvider::factory()->count(3)->create(['enabled' => false]);
        CaptchaProvider::factory()->count(2)->create(['enabled' => true]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->getJson('/api/captcha/control/status')
            ->assertOk()
            ->assertJsonPath('active_providers', 2)
            ->assertJsonPath('total_providers', 5)
            ->assertJsonStructure(['generation_mode']);
    });
});
