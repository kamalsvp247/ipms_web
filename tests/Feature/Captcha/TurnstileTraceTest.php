<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\InHouseCaptchaClient;
use App\Services\Captcha\TurnstileTraceStore;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Covers the challenge-trace capture: step 1 of the protocol-emulation plan.
 *
 * A trace is the specification every later step is written against, so what matters here
 * is that a capture is retrievable, that its enormous bodies never reach a browser intact,
 * and that a filename cannot be used to read outside the trace directory.
 */
function traceAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function writeTrace(string $file, array $overrides = []): string
{
    $store = app(TurnstileTraceStore::class);
    @mkdir($store->directory(), 0775, true);

    $trace = array_replace([
        'captured_at' => '2026-07-28T07:45:48.745Z',
        'site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
        'page_url' => 'https://appointment.ivacbd.com/',
        'outcome' => ['solved' => true, 'token_length' => 752, 'ms' => 2453],
        'summary' => ['requests' => 12, 'challenge_sequence' => [['order' => 5], ['order' => 18]]],
        'calls' => [[
            'order' => 5,
            'url' => 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/h/g/fo/x/y/z',
            'request_body' => str_repeat('q', 3692),
            'response_body' => str_repeat('r', 822700),
        ]],
    ], $overrides);

    $path = $store->directory().'/'.$file;
    file_put_contents($path, json_encode($trace));

    return $path;
}

afterEach(function () {
    foreach (glob(app(TurnstileTraceStore::class)->directory().'/*.json') ?: [] as $stale) {
        @unlink($stale);
    }
});

describe('TurnstileTraceStore', function () {
    it('refuses a filename it would not have written', function () {
        $store = app(TurnstileTraceStore::class);

        expect($store->path('../../../.env'))->toBeNull();
        expect($store->path('trace.json/../secret'))->toBeNull();
        expect($store->path('2026-07-28T07-45-48-745Z.json'))
            ->toBe($store->directory().'/2026-07-28T07-45-48-745Z.json');
    });

    /**
     * One capture carries ~270 KB of iframe bootstrap and ~800 KB of challenge payload.
     * Shipping that to a browser to render a request list is what the preview exists to
     * prevent — while the true length is kept, because size is itself diagnostic.
     */
    it('reduces bodies to a preview but keeps their true length', function () {
        writeTrace('2026-07-28T07-45-48-745Z.json');

        $store = app(TurnstileTraceStore::class);
        $summarised = $store->summarise($store->read('2026-07-28T07-45-48-745Z.json'));
        $call = $summarised['calls'][0];

        expect(strlen($call['response_body']))->toBe(600);
        expect($call['response_body_length'])->toBe(822700);
        expect(strlen($call['request_body']))->toBe(600);
        expect($call['request_body_length'])->toBe(3692);
    });

    it('lists captures newest first and reports the latest', function () {
        writeTrace('2026-07-27T09-00-00-000Z.json', ['outcome' => ['solved' => false]]);
        writeTrace('2026-07-28T07-45-48-745Z.json');

        $store = app(TurnstileTraceStore::class);
        $listed = $store->list();

        expect($listed)->toHaveCount(2);
        expect($listed[0]['file'])->toBe('2026-07-28T07-45-48-745Z.json');
        expect($listed[0]['solved'])->toBeTrue();
        expect($listed[0]['challenge_requests'])->toBe(2);
        expect($listed[1]['solved'])->toBeFalse();
        expect($store->latest()['captured_at'])->toBe('2026-07-28T07:45:48.745Z');
    });

    it('returns null rather than throwing on a missing or corrupt trace', function () {
        $store = app(TurnstileTraceStore::class);
        @mkdir($store->directory(), 0775, true);
        file_put_contents($store->directory().'/broken.json', 'not json');

        expect($store->read('nope.json'))->toBeNull();
        expect($store->read('broken.json'))->toBeNull();
    });
});

describe('InHouseCaptchaClient::trace', function () {
    it('returns the capture shape without the payload', function () {
        Http::fake(['*/trace' => Http::response([
            'file' => '2026-07-28T07-45-48-745Z.json',
            'captured_at' => '2026-07-28T07:45:48.745Z',
            'outcome' => ['solved' => true, 'token_length' => 752],
            'summary' => ['requests' => 12],
        ], 200)]);

        $result = app(InHouseCaptchaClient::class)->trace('0x4AAAAAACghKkJHL1t7UkuZ', 'https://appointment.ivacbd.com/');

        expect($result['file'])->toBe('2026-07-28T07-45-48-745Z.json');
        expect($result['outcome']['solved'])->toBeTrue();
        expect($result['summary']['requests'])->toBe(12);
    });

    it('surfaces the sidecar error instead of returning an empty capture', function () {
        Http::fake(['*/trace' => Http::response(['error' => 'turnstile error-callback: 600010'], 504)]);

        expect(fn () => app(InHouseCaptchaClient::class)->trace('0x4AAAAAACghKkJHL1t7UkuZ', 'https://appointment.ivacbd.com/'))
            ->toThrow(RuntimeException::class, 'turnstile error-callback');
    });
});

describe('trace endpoints', function () {
    it('refuses to trace before the site key and page URL are configured', function () {
        Setting::instance()->update(['captcha_site_key' => '', 'captcha_page_url' => '']);

        $this->actingAs(traceAdmin())
            ->postJson('/api/in-house-captcha/trace')
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    });

    /**
     * The token is only valid for the pair it was minted against, so a trace has to be
     * captured against the configured site key and page URL — never a hardcoded one.
     */
    it('traces against the configured site key and page url', function () {
        Setting::instance()->update([
            'captcha_site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
            'captcha_page_url' => 'https://appointment.ivacbd.com/',
        ]);
        Http::fake(['*/trace' => Http::response([
            'file' => '2026-07-28T07-45-48-745Z.json',
            'captured_at' => '2026-07-28T07:45:48.745Z',
            'outcome' => ['solved' => true],
            'summary' => ['requests' => 12],
        ], 200)]);

        $this->actingAs(traceAdmin())
            ->postJson('/api/in-house-captcha/trace')
            ->assertOk()
            ->assertJsonPath('file', '2026-07-28T07-45-48-745Z.json');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/trace')
            && $req['siteKey'] === '0x4AAAAAACghKkJHL1t7UkuZ'
            && $req['pageUrl'] === 'https://appointment.ivacbd.com/');
    });

    it('serves a stored trace with previewed bodies and 404s an unknown one', function () {
        writeTrace('2026-07-28T07-45-48-745Z.json');
        $admin = traceAdmin();

        $this->actingAs($admin)
            ->getJson('/api/in-house-captcha/traces')
            ->assertOk()
            ->assertJsonPath('traces.0.file', '2026-07-28T07-45-48-745Z.json');

        $this->actingAs($admin)
            ->getJson('/api/in-house-captcha/traces/2026-07-28T07-45-48-745Z.json')
            ->assertOk()
            ->assertJsonPath('trace.calls.0.response_body_length', 822700);

        $this->actingAs($admin)
            ->getJson('/api/in-house-captcha/traces/missing.json')
            ->assertStatus(404);
    });
});
