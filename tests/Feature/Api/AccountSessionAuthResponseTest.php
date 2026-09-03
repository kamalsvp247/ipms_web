<?php

use App\Models\Account;
use App\Models\AccountSession;

test('stores auth response data from java bot signin', function () {
    $account = Account::factory()->create(['phone' => '01712345678']);

    $payload = [
        'phone' => '01712345678',
        'signed_in_server_time' => '2026-03-10T03:01:44.300495591Z',
        'jwt_token' => 'eyJraWQiOiJpYW0tand0LXJzYS0xIiwidHlwIjoiSldUIiwiYWxnIjoiUlMyNTYifQ...',
        'token_type' => 'Bearer',
        'expires_at' => 899,
        'user_id' => 'ffdab628-491a-4762-843d-c3db3b592b97',
        'request_id' => 'c37b3c00-62af-4cd2-8fea-fedd11fe82d5',
        'roles' => [],
        'status_code' => 200,
        'message' => 'Success',
        'success_flag' => true,
        'server_time' => '2026-03-10T03:01:44.300495591Z',
    ];

    $response = $this->postJson('/api/account-sessions', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('account_sessions', [
        'phone' => '01712345678',
        'jwt_token' => $payload['jwt_token'],
        'token_type' => 'Bearer',
        'expires_at' => 899,
        'user_id' => 'ffdab628-491a-4762-843d-c3db3b592b97',
        'request_id' => 'c37b3c00-62af-4cd2-8fea-fedd11fe82d5',
        'status_code' => 200,
        'success_flag' => true,
    ]);

    $session = AccountSession::where('phone', '01712345678')->first();
    expect($session->token_type)->toBe('Bearer');
    expect($session->expires_at)->toBe(899);
    expect($session->status_code)->toBe(200);
    expect($session->success_flag)->toBeTrue();
    expect($session->roles)->toBe([]);
});

test('stores auth response when creating fresh session with jwt token', function () {
    $account = Account::factory()->create(['phone' => '01798765432']);

    $payload = [
        'phone' => '01798765432',
        'signed_in_server_time' => '2026-03-10T05:30:00Z',
        'jwt_token' => 'new_jwt_token_here',
        'token_type' => 'Bearer',
        'expires_at' => 300,
        'user_id' => 'user-123',
        'request_id' => 'req-456',
        'status_code' => 200,
        'message' => 'Success',
        'success_flag' => true,
        'server_time' => '2026-03-10T05:30:00Z',
    ];

    $response = $this->postJson('/api/account-sessions', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('account_sessions', [
        'account_id' => $account->id,
        'phone' => '01798765432',
        'jwt_token' => 'new_jwt_token_here',
        'token_type' => 'Bearer',
        'expires_at' => 300,
    ]);
});

test('uses jwt iat/exp claims as the source of truth over bot-supplied ms values', function () {
    Account::factory()->create(['phone' => '01700000001']);

    // iat and exp are Unix epoch seconds — IVAC issues a 899-second JWT.
    $iat = 1777306532;
    $exp = 1777307432;

    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['iat' => $iat, 'exp' => $exp])), '+/', '-_'), '=');
    $jwt = "$header.$payload.fakesig";

    $this->postJson('/api/account-sessions', [
        'phone' => '01700000001',
        'jwt_token' => $jwt,
        // Deliberately wrong wall-clock values from the bot — JWT claims must win.
        'jwt_generated_at_ms' => 1000,
        'jwt_expires_at_ms' => 2000,
    ])->assertCreated();

    $session = AccountSession::where('phone', '01700000001')->first();
    expect($session->jwt_generated_at->getTimestamp())->toBe($iat);
    expect($session->jwt_expires_at->getTimestamp())->toBe($exp);
});

test('falls back to bot-supplied ms values when jwt cannot be decoded', function () {
    Account::factory()->create(['phone' => '01700000002']);

    $iatMs = 1777306532000;
    $expMs = 1777307432000;

    $this->postJson('/api/account-sessions', [
        'phone' => '01700000002',
        'jwt_token' => 'malformed_no_dots',
        'jwt_generated_at_ms' => $iatMs,
        'jwt_expires_at_ms' => $expMs,
    ])->assertCreated();

    $session = AccountSession::where('phone', '01700000002')->first();
    expect($session->jwt_generated_at->getTimestamp())->toBe((int) ($iatMs / 1000));
    expect($session->jwt_expires_at->getTimestamp())->toBe((int) ($expMs / 1000));
});

test('updates auth response when adding otp to existing session', function () {
    $account = Account::factory()->create(['phone' => '01715555555']);
    $session = AccountSession::factory()->create([
        'account_id' => $account->id,
        'phone' => '01715555555',
        'jwt_token' => 'existing_token',
        'token_type' => 'Bearer',
        'expires_at' => 300,
    ]);

    $payload = [
        'phone' => '01715555555',
        'otp_code' => '123456',
        'otp_verified_server_time' => '2026-03-10T05:35:00Z',
    ];

    $response = $this->postJson('/api/account-sessions', $payload);

    $response->assertOk();
    $this->assertDatabaseHas('account_sessions', [
        'id' => $session->id,
        'phone' => '01715555555',
        'otp_code' => '123456',
        'jwt_token' => 'existing_token',
        'token_type' => 'Bearer',
    ]);
});

test('stores signin request_id and signed_in_server_time alongside jwt', function () {
    Account::factory()->create(['phone' => '01730000001']);

    $this->postJson('/api/account-sessions', [
        'phone' => '01730000001',
        'jwt_token' => 'eyJhbGciOiJSUzI1NiJ9.e30.sig',
        'request_id' => 'req-signin-abc123',
        'signed_in_server_time' => '2026-06-16T07:29:15.000Z',
    ])->assertCreated();

    $session = AccountSession::where('phone', '01730000001')->first();
    expect($session->request_id)->toBe('req-signin-abc123');
    expect($session->signed_in_server_time)->toBe('2026-06-16T07:29:15.000Z');
});

test('is_otp_verified is false after jwt upsert', function () {
    Account::factory()->create(['phone' => '01720000001']);

    $this->postJson('/api/account-sessions', [
        'phone' => '01720000001',
        'jwt_token' => 'eyJhbGciOiJSUzI1NiJ9.e30.sig',
    ])->assertCreated();

    $session = AccountSession::where('phone', '01720000001')->first();
    expect($session->is_otp_verified)->toBeFalse();
});

test('is_otp_verified is true after partial otp update', function () {
    $account = Account::factory()->create(['phone' => '01720000002']);
    AccountSession::factory()->create([
        'account_id' => $account->id,
        'phone' => '01720000002',
        'jwt_token' => 'some_jwt',
        'is_otp_verified' => false,
    ]);

    $this->postJson('/api/account-sessions', [
        'phone' => '01720000002',
        'otp_code' => '654321',
        'otp_verified_server_time' => '2026-06-16T07:30:01Z',
    ])->assertOk();

    $session = AccountSession::where('phone', '01720000002')->first();
    expect($session->is_otp_verified)->toBeTrue();
    expect($session->otp_code)->toBe('654321');
    expect($session->jwt_token)->toBe('some_jwt');
});

test('is_otp_verified resets to false when new jwt arrives', function () {
    $account = Account::factory()->create(['phone' => '01720000003']);
    AccountSession::factory()->create([
        'account_id' => $account->id,
        'phone' => '01720000003',
        'jwt_token' => 'old_jwt',
        'is_otp_verified' => true,
        'otp_code' => '111111',
    ]);

    $this->postJson('/api/account-sessions', [
        'phone' => '01720000003',
        'jwt_token' => 'new_jwt_after_restart',
    ])->assertOk();

    $session = AccountSession::where('phone', '01720000003')->first();
    expect($session->is_otp_verified)->toBeFalse();
    expect($session->jwt_token)->toBe('new_jwt_after_restart');
});

test('is_otp_verified stays false after slot-only update', function () {
    $account = Account::factory()->create(['phone' => '01720000004']);
    AccountSession::factory()->create([
        'account_id' => $account->id,
        'phone' => '01720000004',
        'jwt_token' => 'some_jwt',
        'is_otp_verified' => false,
    ]);

    $this->postJson('/api/account-sessions', [
        'phone' => '01720000004',
        'slot_id' => 'slot-abc-123',
    ])->assertOk();

    $session = AccountSession::where('phone', '01720000004')->first();
    expect($session->is_otp_verified)->toBeFalse();
    expect($session->slot_id)->toBe('slot-abc-123');
});
