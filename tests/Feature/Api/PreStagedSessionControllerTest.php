<?php

use App\Models\PreStagedSession;
use App\Models\User;

// ─── init ─────────────────────────────────────────────────────────────────────

test('init creates a pre-staged session when none exists', function () {
    $response = $this->postJson('/api/pre-staged-sessions/init', [
        'phone' => '01711111111',
        'request_id' => 'req-abc-123',
    ]);

    $response->assertStatus(201)
        ->assertJsonFragment(['phone' => '01711111111', 'request_id' => 'req-abc-123']);

    $this->assertDatabaseHas('pre_staged_sessions', [
        'phone' => '01711111111',
        'request_id' => 'req-abc-123',
    ]);
});

test('init is a no-op when a row already exists for the phone', function () {
    PreStagedSession::create(['phone' => '01711111111', 'request_id' => 'original-req-id']);

    $response = $this->postJson('/api/pre-staged-sessions/init', [
        'phone' => '01711111111',
        'request_id' => 'new-req-id',
    ]);

    $response->assertStatus(200)
        ->assertJsonFragment(['request_id' => 'original-req-id']);

    $this->assertDatabaseHas('pre_staged_sessions', ['phone' => '01711111111', 'request_id' => 'original-req-id']);
    $this->assertDatabaseMissing('pre_staged_sessions', ['request_id' => 'new-req-id']);
});

test('init is unauthenticated', function () {
    $response = $this->postJson('/api/pre-staged-sessions/init', [
        'phone' => '01799999999',
        'request_id' => 'req-xyz',
    ]);

    $response->assertSuccessful();
});

// ─── updateOtp ────────────────────────────────────────────────────────────────

test('updateOtp sets otp_code and otp_captured_at', function () {
    PreStagedSession::create(['phone' => '01722222222', 'request_id' => 'req-abc']);

    $response = $this->patchJson('/api/pre-staged-sessions/otp', [
        'phone' => '01722222222',
        'otp_code' => '123456',
    ]);

    $response->assertOk()
        ->assertJsonFragment(['otp_code' => '123456']);

    $session = PreStagedSession::where('phone', '01722222222')->first();
    expect($session->otp_code)->toBe('123456');
    expect($session->otp_captured_at)->not->toBeNull();
});

test('updateOtp returns 404 when no pre-staged session exists', function () {
    $response = $this->patchJson('/api/pre-staged-sessions/otp', [
        'phone' => '01799999998',
        'otp_code' => '000000',
    ]);

    $response->assertStatus(404);
});

// ─── clearOtp ─────────────────────────────────────────────────────────────────

test('clearOtp nullifies otp_code and otp_captured_at but keeps request_id', function () {
    PreStagedSession::create([
        'phone' => '01733333333',
        'request_id' => 'keep-this-req-id',
        'otp_code' => '654321',
        'otp_captured_at' => now(),
    ]);

    $response = $this->patchJson('/api/pre-staged-sessions/clear-otp', [
        'phone' => '01733333333',
    ]);

    $response->assertOk();

    $session = PreStagedSession::where('phone', '01733333333')->first();
    expect($session->otp_code)->toBeNull();
    expect($session->otp_captured_at)->toBeNull();
    expect($session->request_id)->toBe('keep-this-req-id');
});

test('clearOtp returns 404 when no pre-staged session exists', function () {
    $response = $this->patchJson('/api/pre-staged-sessions/clear-otp', [
        'phone' => '01799999997',
    ]);

    $response->assertStatus(404);
});

// ─── index ────────────────────────────────────────────────────────────────────

test('index returns pre-staged sessions for authenticated super admin', function () {
    PreStagedSession::create(['phone' => '01744444444', 'request_id' => 'req-1']);
    PreStagedSession::create(['phone' => '01755555555', 'request_id' => 'req-2']);

    $admin = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($admin)->getJson('/api/pre-staged-sessions');

    $response->assertOk()
        ->assertJsonFragment(['phone' => '01744444444'])
        ->assertJsonFragment(['phone' => '01755555555']);
});

test('index requires authentication', function () {
    $response = $this->getJson('/api/pre-staged-sessions');

    $response->assertStatus(401);
});

// ─── destroy ─────────────────────────────────────────────────────────────────

test('destroy deletes the pre-staged session', function () {
    $session = PreStagedSession::create(['phone' => '01766666666', 'request_id' => 'req-del']);

    $admin = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($admin)->deleteJson("/api/pre-staged-sessions/{$session->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('pre_staged_sessions', ['id' => $session->id]);
});

test('destroy requires authentication', function () {
    $session = PreStagedSession::create(['phone' => '01777777777', 'request_id' => 'req-auth']);

    $response = $this->deleteJson("/api/pre-staged-sessions/{$session->id}");

    $response->assertStatus(401);
});
