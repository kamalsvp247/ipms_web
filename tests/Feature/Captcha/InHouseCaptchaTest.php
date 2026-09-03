<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\InHouseCaptchaClient;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const SOLVER_SITE_KEY = '0x4AAAAAACghKkJHL1t7UkuZ';
const SOLVER_PAGE_URL = 'https://appointment.ivacbd.com/';
const SOLVER_TOKEN = '1.abcdefghijklmnopqrstuvwxyz0123456789';

function solverAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function configureSolverSettings(): void
{
    Setting::instance()->update([
        'captcha_site_key' => SOLVER_SITE_KEY,
        'captcha_page_url' => SOLVER_PAGE_URL,
    ]);
}

describe('InHouseCaptchaClient', function () {
    it('returns the token, timing and attempt count from the sidecar', function () {
        Http::fake(['*/solve' => Http::response(['token' => SOLVER_TOKEN, 'ms' => 2754, 'attempts' => 1], 200)]);

        $result = app(InHouseCaptchaClient::class)->solve(SOLVER_SITE_KEY, SOLVER_PAGE_URL);

        expect($result)->toBe(['token' => SOLVER_TOKEN, 'ms' => 2754, 'attempts' => 1]);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/solve')
            && $req['siteKey'] === SOLVER_SITE_KEY
            && $req['pageUrl'] === SOLVER_PAGE_URL);
    });

    it('surfaces the sidecar error message rather than returning a null token', function () {
        Http::fake(['*/solve' => Http::response(['error' => 'solver saturated (4 active, 32 queued)'], 503)]);

        expect(fn () => app(InHouseCaptchaClient::class)->solve(SOLVER_SITE_KEY, SOLVER_PAGE_URL))
            ->toThrow(RuntimeException::class, 'solver saturated');
    });

    it('treats an empty token as a failure', function () {
        Http::fake(['*/solve' => Http::response(['token' => ''], 200)]);

        expect(fn () => app(InHouseCaptchaClient::class)->solve(SOLVER_SITE_KEY, SOLVER_PAGE_URL))
            ->toThrow(RuntimeException::class, 'empty token');
    });

    it('reports null health when the sidecar is unreachable', function () {
        Http::fake(['*/health' => Http::response('', 500)]);

        expect(app(InHouseCaptchaClient::class)->health())->toBeNull();
    });
});

describe('POST /api/in-house-captcha/generate', function () {
    it('returns a solved token', function () {
        configureSolverSettings();
        Http::fake(['*/solve' => Http::response(['token' => SOLVER_TOKEN, 'ms' => 3100, 'attempts' => 2], 200)]);

        $this->actingAs(solverAdmin())
            ->postJson('/api/in-house-captcha/generate')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'token' => SOLVER_TOKEN,
                'ms' => 3100,
                'attempts' => 2,
                'site_key' => SOLVER_SITE_KEY,
                'page_url' => SOLVER_PAGE_URL,
            ]);
    });

    it('solves against the configured settings, never a hardcoded pair', function () {
        Setting::instance()->update([
            'captcha_site_key' => '0xROTATEDKEY123456',
            'captcha_page_url' => 'https://example.test/',
        ]);
        Http::fake(['*/solve' => Http::response(['token' => SOLVER_TOKEN, 'ms' => 1, 'attempts' => 1], 200)]);

        $this->actingAs(solverAdmin())->postJson('/api/in-house-captcha/generate')->assertOk();

        Http::assertSent(fn ($req) => $req['siteKey'] === '0xROTATEDKEY123456'
            && $req['pageUrl'] === 'https://example.test/');
    });

    it('fails with 422 when the site key or page url is unset', function () {
        Setting::instance()->update(['captcha_site_key' => '', 'captcha_page_url' => '']);
        Http::fake();

        $this->actingAs(solverAdmin())
            ->postJson('/api/in-house-captcha/generate')
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        Http::assertNothingSent();
    });

    it('returns 502 with the solver message when solving fails', function () {
        configureSolverSettings();
        Http::fake(['*/solve' => Http::response(['error' => 'turnstile error-callback: 300030'], 504)]);

        $this->actingAs(solverAdmin())
            ->postJson('/api/in-house-captcha/generate')
            ->assertStatus(502)
            ->assertJsonPath('status', 'failed')
            ->assertJsonFragment(['message' => 'In-house solver error: turnstile error-callback: 300030']);
    });

    it('is denied to non super admins', function () {
        configureSolverSettings();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'agent']))
            ->postJson('/api/in-house-captcha/generate')
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('is denied to guests', function () {
        $this->postJson('/api/in-house-captcha/generate')->assertUnauthorized();
    });
});

describe('GET /api/in-house-captcha/health', function () {
    it('passes the sidecar snapshot through when it is up', function () {
        Http::fake(['*/health' => Http::response([
            'ok' => true,
            'chrome' => 'up',
            'pool' => ['concurrency' => 4, 'active' => 0, 'queued' => 0],
        ], 200)]);

        $this->actingAs(solverAdmin())
            ->getJson('/api/in-house-captcha/health')
            ->assertOk()
            ->assertJsonPath('status', 'up')
            ->assertJsonPath('chrome', 'up')
            ->assertJsonPath('pool.concurrency', 4);
    });

    it('returns 503 with install guidance when the sidecar is down', function () {
        Http::fake(['*/health' => Http::response('', 500)]);

        $this->actingAs(solverAdmin())
            ->getJson('/api/in-house-captcha/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonFragment(['message' => 'Solver sidecar is unreachable. Check: systemctl status ipms-in-house-captcha']);
    });
});

describe('POST /api/in-house-captcha/restart', function () {
    it('returns ok when the browser relaunches', function () {
        Http::fake(['*/restart' => Http::response(['ok' => true], 200)]);

        $this->actingAs(solverAdmin())
            ->postJson('/api/in-house-captcha/restart')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    });

    it('returns 502 when the relaunch fails', function () {
        Http::fake(['*/restart' => Http::response('', 500)]);

        $this->actingAs(solverAdmin())
            ->postJson('/api/in-house-captcha/restart')
            ->assertStatus(502);
    });
});
