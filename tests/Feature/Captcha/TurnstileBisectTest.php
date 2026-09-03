<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\InHouseCaptchaClient;
use App\Services\Captcha\TurnstileBisectStore;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Step 3 of the protocol-emulation plan: measuring which browser signals Cloudflare checks.
 *
 * The measurement is only worth anything if the harness provably reaches the challenge,
 * which is what the positive control arm is for — the first version of that control used an
 * injected script that only affects the NEXT document, so it sat inert at 100% and would
 * have certified a completely blind run as clean.
 */
function bisectAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function writeBisect(string $file, array $overrides = []): void
{
    $store = app(TurnstileBisectStore::class);
    @mkdir($store->directory(), 0775, true);

    file_put_contents($store->directory().'/'.$file, json_encode(array_replace([
        'captured_at' => '2026-07-28T08:20:00.000Z',
        'samples_per_arm' => 6,
        'baseline' => ['arm' => 'baseline', 'samples' => 6, 'solved' => 6, 'rate' => 100],
        'arms' => [
            ['arm' => 'ua-headless', 'samples' => 6, 'solved' => 0, 'rate' => 0, 'checked' => true, 'errors' => []],
            ['arm' => 'timezone-utc', 'samples' => 6, 'solved' => 6, 'rate' => 100, 'checked' => false, 'errors' => []],
        ],
        'checked' => ['ua-headless'],
        'ignored' => ['timezone-utc'],
    ], $overrides)));
}

afterEach(function () {
    foreach (glob(app(TurnstileBisectStore::class)->directory().'/*.json') ?: [] as $stale) {
        @unlink($stale);
    }
});

describe('TurnstileBisectStore', function () {
    it('summarises reports newest first', function () {
        writeBisect('2026-07-27T08-00-00-000Z.json', ['checked' => []]);
        writeBisect('2026-07-28T08-20-00-000Z.json');

        $listed = app(TurnstileBisectStore::class)->list();

        expect($listed)->toHaveCount(2);
        expect($listed[0]['file'])->toBe('2026-07-28T08-20-00-000Z.json');
        expect($listed[0]['baseline_rate'])->toBe(100);
        expect($listed[0]['checked'])->toBe(['ua-headless']);
        expect($listed[1]['checked'])->toBe([]);
    });

    it('returns the newest report in full and null when none exists', function () {
        expect(app(TurnstileBisectStore::class)->latest())->toBeNull();

        writeBisect('2026-07-28T08-20-00-000Z.json');

        expect(app(TurnstileBisectStore::class)->latest()['arms'])->toHaveCount(2);
    });
});

describe('bisect endpoints', function () {
    it('refuses to bisect before the site key and page URL are configured', function () {
        Setting::instance()->update(['captcha_site_key' => '', 'captcha_page_url' => '']);

        $this->actingAs(bisectAdmin())
            ->postJson('/api/in-house-captcha/bisect')
            ->assertStatus(422);
    });

    it('passes the sample count and mutation list through to the solver', function () {
        Setting::instance()->update([
            'captcha_site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
            'captcha_page_url' => 'https://appointment.ivacbd.com/',
        ]);
        Http::fake(['*/bisect' => Http::response([
            'baseline' => ['rate' => 100],
            'arms' => [],
            'checked' => [],
            'ignored' => [],
        ], 200)]);

        $this->actingAs(bisectAdmin())
            ->postJson('/api/in-house-captcha/bisect', ['samples' => 4, 'mutations' => ['ua-headless']])
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/bisect')
            && $req['samples'] === 4
            && $req['mutations'] === ['ua-headless']);
    });

    it('serves the latest report with its history', function () {
        writeBisect('2026-07-28T08-20-00-000Z.json');

        $this->actingAs(bisectAdmin())
            ->getJson('/api/in-house-captcha/bisect')
            ->assertOk()
            ->assertJsonPath('report.checked', ['ua-headless'])
            ->assertJsonPath('history.0.baseline_rate', 100);
    });

    it('surfaces a solver failure rather than reporting an empty run', function () {
        Setting::instance()->update([
            'captcha_site_key' => '0x4AAAAAACghKkJHL1t7UkuZ',
            'captcha_page_url' => 'https://appointment.ivacbd.com/',
        ]);
        Http::fake(['*/bisect' => Http::response(['error' => 'solver saturated'], 503)]);

        expect(fn () => app(InHouseCaptchaClient::class)->bisect('0x4AAAAAACghKkJHL1t7UkuZ', 'https://appointment.ivacbd.com/'))
            ->toThrow(RuntimeException::class, 'solver saturated');
    });
});
