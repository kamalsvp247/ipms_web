<?php

use App\Models\BypassIp;
use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Models\Setting;
use App\Models\User;
use App\Services\BypassIpScanner;
use App\Services\CensysOriginLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function censysActor(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function setCensysCreds(): void
{
    Setting::instance()->update(['censys_api_id' => 'id-123', 'censys_api_secret' => 'secret-abc']);
}

it('rejects the lookup when credentials are missing', function () {
    Http::fake();

    $this->actingAs(censysActor(), 'sanctum')
        ->postJson('/api/bypass-ips/scan/censys')
        ->assertStatus(422)
        ->assertJsonPath('error', fn ($m) => str_contains($m, 'credentials are not configured'));

    Http::assertNothingSent();
});

it('queries Censys, validates returned IPs, and stages genuine origins', function () {
    setCensysCreds();

    Http::fake([
        'search.censys.io/*' => Http::response([
            'result' => ['hits' => [
                ['ip' => '13.232.38.16'],
                ['ip' => '13.206.53.10'],
                ['ip' => '999.1.1.1'], // invalid octet — must be discarded
            ]],
        ], 200),
    ]);

    // Scanner reaches the network in production — stub it so only the live one comes back.
    $this->mock(BypassIpScanner::class, function ($mock) {
        $mock->shouldReceive('probe')
            ->once()
            ->with(['13.232.38.16', '13.206.53.10'])
            ->andReturn([[
                'ip' => '13.232.38.16',
                'label' => 'x',
                'response_status' => 400,
                'response_message' => 'validation error',
                'response_time_ms' => 120,
            ]]);
    });

    $res = $this->actingAs(censysActor(), 'sanctum')->postJson('/api/bypass-ips/scan/censys');

    $res->assertSuccessful()
        ->assertJsonPath('type', 'censys')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('found_count', 1);

    $session = IpScanSession::where('type', 'censys')->firstOrFail();
    expect($session->total_candidates)->toBe(2); // invalid octet filtered out
    expect(IpScanResult::where('ip', '13.232.38.16')->where('status', 'pending')->exists())->toBeTrue();
});

it('does not re-probe IPs already configured as bypass IPs', function () {
    setCensysCreds();
    BypassIp::create(['label' => 'existing', 'ip' => '13.232.38.16']);

    Http::fake([
        'search.censys.io/*' => Http::response([
            'result' => ['hits' => [['ip' => '13.232.38.16'], ['ip' => '13.206.53.10']]],
        ], 200),
    ]);

    $this->mock(BypassIpScanner::class, function ($mock) {
        $mock->shouldReceive('probe')->once()->with(['13.206.53.10'])->andReturn([]);
    });

    $this->actingAs(censysActor(), 'sanctum')
        ->postJson('/api/bypass-ips/scan/censys')
        ->assertSuccessful();
});

it('surfaces a Censys auth failure as a 422', function () {
    setCensysCreds();
    Http::fake(['search.censys.io/*' => Http::response('nope', 403)]);

    $this->actingAs(censysActor(), 'sanctum')
        ->postJson('/api/bypass-ips/scan/censys')
        ->assertStatus(422)
        ->assertJsonPath('error', fn ($m) => str_contains($m, 'rejected the credentials'));
});

it('saves credentials without leaking the secret and preserves it on blank resubmit', function () {
    $actor = censysActor();

    $this->actingAs($actor, 'sanctum')
        ->postJson('/api/bypass-ips/censys/config', ['api_id' => 'pub-id', 'api_secret' => 'top-secret'])
        ->assertSuccessful()
        ->assertJson(['api_id' => 'pub-id', 'configured' => true])
        ->assertJsonMissing(['api_secret' => 'top-secret']);

    expect(Setting::instance()->censys_api_secret)->toBe('top-secret');

    // Blank secret keeps the stored one.
    $this->actingAs($actor, 'sanctum')
        ->postJson('/api/bypass-ips/censys/config', ['api_id' => 'pub-id-2'])
        ->assertSuccessful();

    $setting = Setting::instance();
    expect($setting->censys_api_id)->toBe('pub-id-2');
    expect($setting->censys_api_secret)->toBe('top-secret');
});

it('extracts and sanitises IPs from a raw response body', function () {
    $ips = app(CensysOriginLookup::class)->extractIps('{"a":"13.232.38.16","b":"256.0.0.1","c":"13.232.38.16","d":"3.7.190.55"}');

    expect($ips)->toBe(['13.232.38.16', '3.7.190.55']);
});
