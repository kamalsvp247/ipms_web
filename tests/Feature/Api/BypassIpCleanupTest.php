<?php

use App\Models\BypassIp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

function cleanupActor(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

it('keeps a valid 500 "GET not supported" IP', function () {
    BypassIp::create([
        'label' => 'keep-500',
        'ip' => '1.1.1.1',
        'response_status' => 500,
        'response_message' => "Request method 'GET' is not supported",
    ]);

    $this->actingAs(cleanupActor(), 'sanctum')->postJson('/api/bypass-ips/cleanup')->assertSuccessful();

    expect(BypassIp::where('ip', '1.1.1.1')->exists())->toBeTrue();
});

it('keeps a 503 IP regardless of message', function () {
    BypassIp::create([
        'label' => 'keep-503',
        'ip' => '2.2.2.2',
        'response_status' => 503,
        'response_message' => null,
    ]);

    $this->actingAs(cleanupActor(), 'sanctum')->postJson('/api/bypass-ips/cleanup')->assertSuccessful();

    expect(BypassIp::where('ip', '2.2.2.2')->exists())->toBeTrue();
});

it('deletes 500 IPs with the wrong message and non-500/503 statuses', function () {
    BypassIp::create(['label' => 'bad-500', 'ip' => '3.3.3.3', 'response_status' => 500, 'response_message' => 'Internal Server Error']);
    BypassIp::create(['label' => 'status-200', 'ip' => '4.4.4.4', 'response_status' => 200, 'response_message' => null]);
    BypassIp::create(['label' => 'status-null', 'ip' => '5.5.5.5', 'response_status' => null, 'response_message' => null]);

    $response = $this->actingAs(cleanupActor(), 'sanctum')->postJson('/api/bypass-ips/cleanup');

    $response->assertSuccessful()->assertJson(['deleted' => 3]);
    expect(BypassIp::whereIn('ip', ['3.3.3.3', '4.4.4.4', '5.5.5.5'])->count())->toBe(0);
});

it('restores the IPs removed by the last cleanup', function () {
    BypassIp::create(['label' => 'keep', 'ip' => '1.1.1.1', 'response_status' => 500, 'response_message' => "Request method 'GET' is not supported"]);
    BypassIp::create(['label' => 'bad', 'ip' => '3.3.3.3', 'response_status' => 200, 'response_message' => null]);
    BypassIp::create(['label' => 'bad2', 'ip' => '5.5.5.5', 'response_status' => null, 'response_message' => null]);

    $actor = cleanupActor();
    $this->actingAs($actor, 'sanctum')->postJson('/api/bypass-ips/cleanup')->assertJson(['deleted' => 2]);
    expect(BypassIp::count())->toBe(1);

    $this->actingAs($actor, 'sanctum')->postJson('/api/bypass-ips/cleanup/restore')->assertSuccessful()->assertJson(['restored' => 2]);

    expect(BypassIp::whereIn('ip', ['3.3.3.3', '5.5.5.5'])->count())->toBe(2);
    $restored = BypassIp::where('ip', '3.3.3.3')->first();
    expect($restored->label)->toBe('bad')->and($restored->response_status)->toBe(200);
});

it('returns nothing to restore when no cleanup has run', function () {
    $this->actingAs(cleanupActor(), 'sanctum')->postJson('/api/bypass-ips/cleanup/restore')
        ->assertSuccessful()->assertJson(['restored' => 0]);
});

it('skips IPs that were re-added before restore', function () {
    BypassIp::create(['label' => 'bad', 'ip' => '3.3.3.3', 'response_status' => 200, 'response_message' => null]);

    $actor = cleanupActor();
    $this->actingAs($actor, 'sanctum')->postJson('/api/bypass-ips/cleanup')->assertJson(['deleted' => 1]);

    BypassIp::create(['label' => 're-added', 'ip' => '3.3.3.3', 'response_status' => 500, 'response_message' => "Request method 'GET' is not supported"]);

    $this->actingAs($actor, 'sanctum')->postJson('/api/bypass-ips/cleanup/restore')->assertJson(['restored' => 0]);
    expect(BypassIp::where('ip', '3.3.3.3')->count())->toBe(1);
    expect(BypassIp::where('ip', '3.3.3.3')->first()->label)->toBe('re-added');
});
