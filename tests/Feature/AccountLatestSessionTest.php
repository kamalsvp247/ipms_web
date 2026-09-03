<?php

use App\Models\Account;
use App\Models\AccountSession;
use App\Models\AgentSlot;

use function Pest\Laravel\getJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A session row left behind by a phone the account used earlier carries a higher id than the
 * account's current one, so selecting by MAX(id) over account_id handed the config export an
 * expired token from the wrong phone. The bot then saw accessToken: null, signed in while holding
 * a live JWT, and ate IVAC's "You can log in after N minute(s)" 429.
 */
function sessionRow(?Account $account, string $phone, string $token, \Carbon\Carbon $expiresAt): AccountSession
{
    return AccountSession::create([
        'account_id' => $account?->id,
        'phone' => $phone,
        'jwt_token' => $token,
        'jwt_generated_at' => $expiresAt->copy()->subSeconds(899),
        'jwt_expires_at' => $expiresAt,
        'is_otp_verified' => true,
        'request_id' => 'req-'.$phone,
    ]);
}

it('ignores a session left by an old phone and picks the row for the account phone', function () {
    $account = Account::factory()->create(['phone' => '01628966818']);

    sessionRow($account, '01628966818', 'live-token', now()->addMinutes(10));
    // Written later (higher id) while the account was still on its previous number.
    sessionRow($account, '01894387735', 'stale-token', now()->subMinutes(90));

    $account->load('latestSession');

    expect($account->latestSession->phone)->toBe('01628966818')
        ->and($account->latestSession->jwt_token)->toBe('live-token');
});

it('exports the live JWT to the bot so it reuses the session instead of signing in', function () {
    $slot = AgentSlot::create([
        'name' => 'jwt-slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);

    $account = Account::factory()->create([
        'phone' => '01628966818',
        'is_active' => true,
        'status' => 'running',
        'agent_slot_id' => $slot->id,
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->toDateString(),
    ]);

    // MySQL datetime has no sub-second part, so the round-tripped value must be second-aligned
    // for the exported millisecond timestamp to compare equal.
    $expiresAt = now()->addMinutes(10)->startOfSecond();
    sessionRow($account, '01628966818', 'live-token', $expiresAt);
    sessionRow($account, '01894387735', 'stale-token', now()->subMinutes(90));

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('accounts.0.phone', '01628966818')
        ->assertJsonPath('accounts.0.accessToken', 'live-token')
        ->assertJsonPath('accounts.0.isOtpVerified', true)
        ->assertJsonPath('accounts.0.jwtExpiresAtMs', $expiresAt->getTimestampMs());
});

it('exports no token when the account phone has only an expired session', function () {
    $slot = AgentSlot::create([
        'name' => 'jwt-slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);

    $account = Account::factory()->create([
        'phone' => '01628966818',
        'is_active' => true,
        'status' => 'running',
        'agent_slot_id' => $slot->id,
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->toDateString(),
    ]);

    sessionRow($account, '01628966818', 'expired-token', now()->subMinutes(5));

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('accounts.0.accessToken', null)
        ->assertJsonPath('accounts.0.isOtpVerified', false);
});
