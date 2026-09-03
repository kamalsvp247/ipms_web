<?php

use App\Models\Account;
use App\Models\AccountLiveState;
use App\Models\AgentSlot;
use App\Models\User;

const RESERVE_SLOT_URL = 'https://api.ivacbd.com/iams/api/v1/slots/779fd4d2-77b9-4558-a529-368582e830be/reserve-slot';
const SIGN_IN_URL = 'https://api.ivacbd.com/iams/api/v1/auth/v2677-sign-in';

beforeEach(function () {
    $this->slot = AgentSlot::create([
        'name' => 'Live State Slot',
        'api_key' => 'live-state-key-'.uniqid(),
    ]);
});

function ingestLiveStateLogs(string $apiKey, array $logs)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$apiKey])
        ->postJson('/api/slots/logs', ['logs' => $logs]);
}

function liveStateLog(array $overrides = []): array
{
    return array_merge([
        'account_phone' => '01700000001',
        'method' => 'POST',
        'url' => RESERVE_SLOT_URL,
        'status_code' => 200,
        'duration_ms' => 180,
        'response_body' => json_encode([
            'status' => 'FULL',
            'reservationId' => null,
            'appointmentDate' => '2026-08-19',
            'countByType' => ['MISCELLANEOUS' => 2],
            'message' => 'Selected slot is completely booked for now.',
        ]),
        'logged_at' => now()->format('Y-m-d H:i:s.v'),
    ], $overrides);
}

it('records the phase, status code and a short message from an ingested call', function () {
    ingestLiveStateLogs($this->slot->api_key, [liveStateLog()])->assertOk();

    $state = AccountLiveState::where('phone', '01700000001')->first();

    expect($state)->not->toBeNull()
        ->and($state->phase)->toBe('Reserve slot')
        ->and($state->status_code)->toBe(200)
        ->and($state->message)->toBe('FULL · 2026-08-19')
        ->and($state->agent_slot_id)->toBe($this->slot->id);
});

it('surfaces the reservation id as RESERVED when a slot is actually won', function () {
    ingestLiveStateLogs($this->slot->api_key, [liveStateLog([
        'response_body' => json_encode([
            'status' => 'OK_NEW',
            'reservationId' => '69375f68-bc69-4a86-a213-9b0988403e49',
            'appointmentDate' => '2026-08-21',
        ]),
    ])])->assertOk();

    expect(AccountLiveState::where('phone', '01700000001')->value('message'))->toBe('RESERVED · 2026-08-21');
});

it('keeps only the newest call per phone within one batch', function () {
    ingestLiveStateLogs($this->slot->api_key, [
        liveStateLog(['logged_at' => now()->subSeconds(30)->format('Y-m-d H:i:s.v')]),
        liveStateLog([
            'url' => SIGN_IN_URL,
            'status_code' => 429,
            'response_body' => json_encode(['http_status' => 429, 'message' => 'You can log in after 0 minute(s) and 18 second(s)']),
        ]),
    ])->assertOk();

    $state = AccountLiveState::where('phone', '01700000001')->first();

    expect($state->phase)->toBe('Sign in')
        ->and($state->status_code)->toBe(429)
        ->and($state->message)->toBe('You can log in after 0 minute(s) and 18 second(s)');
});

it('never lets a late batch overwrite a newer state', function () {
    ingestLiveStateLogs($this->slot->api_key, [liveStateLog()])->assertOk();

    ingestLiveStateLogs($this->slot->api_key, [liveStateLog([
        'url' => SIGN_IN_URL,
        'status_code' => 400,
        'response_body' => json_encode(['status' => 400, 'error' => 'Captcha verification failed. Please try again']),
        'logged_at' => now()->subMinutes(5)->format('Y-m-d H:i:s.v'),
    ])])->assertOk();

    $state = AccountLiveState::where('phone', '01700000001')->first();

    expect($state->phase)->toBe('Reserve slot')
        ->and($state->status_code)->toBe(200);
});

it('survives the scheduled purge that strips 4xx rows out of bot_logs', function () {
    ingestLiveStateLogs($this->slot->api_key, [liveStateLog([
        'url' => SIGN_IN_URL,
        'status_code' => 400,
        'response_body' => json_encode(['status' => 400, 'error' => 'Captcha verification failed. Please try again']),
    ])])->assertOk();

    $this->artisan('schedule:run');

    $state = AccountLiveState::where('phone', '01700000001')->first();

    expect($state->status_code)->toBe(400)
        ->and($state->message)->toBe('Captcha verification failed. Please try again');
});

it('records a transport failure that never reached a status code', function () {
    ingestLiveStateLogs($this->slot->api_key, [liveStateLog([
        'status_code' => null,
        'response_body' => null,
        'error_type' => 'SocketTimeoutException',
    ])])->assertOk();

    $state = AccountLiveState::where('phone', '01700000001')->first();

    expect($state->status_code)->toBeNull()
        ->and($state->error_type)->toBe('SocketTimeoutException')
        ->and($state->message)->toBe('SocketTimeoutException');
});

it('ignores console log lines and OTP polling noise', function () {
    ingestLiveStateLogs($this->slot->api_key, [
        liveStateLog(),
        liveStateLog([
            'method' => 'LOG',
            'url' => 'console',
            'status_code' => null,
            'response_body' => null,
            'logged_at' => now()->addSeconds(10)->format('Y-m-d H:i:s.v'),
        ]),
        liveStateLog([
            'url' => 'https://ipms.senda.fit/api/otp/01700000001',
            'status_code' => 404,
            'response_body' => null,
            'logged_at' => now()->addSeconds(20)->format('Y-m-d H:i:s.v'),
        ]),
    ])->assertOk();

    expect(AccountLiveState::where('phone', '01700000001')->value('phase'))->toBe('Reserve slot');
});

it('returns live states for accounts the agent can see and hides the rest', function () {
    $owner = User::factory()->create(['role' => 'agent']);
    $otherUser = User::factory()->create(['role' => 'agent']);

    Account::factory()->create(['user_id' => $owner->id, 'phone' => '01711111111']);
    Account::factory()->create(['user_id' => $otherUser->id, 'phone' => '01722222222']);

    ingestLiveStateLogs($this->slot->api_key, [
        liveStateLog(['account_phone' => '01711111111']),
        liveStateLog(['account_phone' => '01722222222']),
    ])->assertOk();

    $response = $this->actingAs($owner)->getJson('/api/accounts/live-states');

    $response->assertOk();
    $phones = collect($response->json())->pluck('phone')->all();

    expect($phones)->toContain('01711111111')
        ->and($phones)->not->toContain('01722222222');
});

it('does not let the live-states route be swallowed by the account show route', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($admin)->getJson('/api/accounts/live-states');

    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('never returns a response body on the live-states endpoint', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    Account::factory()->create(['user_id' => $admin->id, 'phone' => '01733333333']);

    ingestLiveStateLogs($this->slot->api_key, [liveStateLog(['account_phone' => '01733333333'])])->assertOk();

    $row = collect($this->actingAs($admin)->getJson('/api/accounts/live-states')->json())
        ->firstWhere('phone', '01733333333');

    expect($row)->not->toBeNull()
        ->and($row)->not->toHaveKey('response_body')
        ->and($row)->not->toHaveKey('request_body');
});
