<?php

use App\Models\AgentSlot;
use App\Models\OtpCode;

beforeEach(function () {
    $this->slot = AgentSlot::create([
        'name' => 'test-slot',
        'api_key' => 'test-key-123',
        'status' => 'online',
        'worker_state' => 'idle',
    ]);
    $this->bearer = ['Authorization' => 'Bearer test-key-123'];
});

function makeOtp(string $phone, string $code, ?string $gmailId, $fetchedAt = null): OtpCode
{
    return OtpCode::create([
        'phone' => $phone,
        'otp_code' => $code,
        'message' => "OTP: {$code}",
        'source_gmail_id' => $gmailId,
        'is_ivacbd' => true,
        'fetched_at' => $fetchedAt ?? now(),
    ]);
}

test('legacy no-params returns latest unconsumed and consumes it', function () {
    makeOtp('01711111111', '111111', null, now()->subSeconds(10));
    makeOtp('01711111111', '222222', null, now());

    $response = $this->withHeaders($this->bearer)->getJson('/api/otp/01711111111');

    $response->assertOk()
        ->assertJsonFragment(['otp_code' => '222222', 'channel' => 'sms']);

    $this->assertDatabaseHas('otp_codes', ['otp_code' => '222222'])
        ->assertNotNull(OtpCode::where('otp_code', '222222')->first()->consumed_at);
});

test('channel=sms excludes gmail-sourced rows', function () {
    makeOtp('01711111112', 'EMAIL1', 'gmail-id-1', now());
    makeOtp('01711111112', 'SMSCDE', null, now()->subSeconds(5));

    $response = $this->withHeaders($this->bearer)->getJson('/api/otp/01711111112?channel=sms');

    $response->assertOk()
        ->assertJsonFragment(['otp_code' => 'SMSCDE', 'channel' => 'sms']);
});

test('channel=email returns only gmail-sourced rows', function () {
    makeOtp('01711111113', 'SMSXX', null, now());
    makeOtp('01711111113', 'EMLXX', 'gmail-id-2', now()->subSeconds(5));

    $response = $this->withHeaders($this->bearer)->getJson('/api/otp/01711111113?channel=email');

    $response->assertOk()
        ->assertJsonFragment(['otp_code' => 'EMLXX', 'channel' => 'email']);
});

test('since filter excludes rows older than (since - tolerance)', function () {
    $oldOtp = makeOtp('01711111114', 'OLD000', null, now()->subSeconds(30));
    $sinceMs = now()->subSeconds(10)->valueOf();

    $response = $this->withHeaders($this->bearer)->getJson("/api/otp/01711111114?since={$sinceMs}");

    $response->assertStatus(404);
    expect($oldOtp->fresh()->consumed_at)->toBeNull();
});

test('since filter includes rows just before since (within tolerance)', function () {
    makeOtp('01711111115', 'JUSTI', null, now()->subSecond());
    $sinceMs = now()->valueOf();

    $response = $this->withHeaders($this->bearer)->getJson("/api/otp/01711111115?since={$sinceMs}");

    $response->assertOk()->assertJsonFragment(['otp_code' => 'JUSTI']);
});

test('consume=0 leaves consumed_at null', function () {
    $otp = makeOtp('01711111116', 'NOCONS', null, now());

    $response = $this->withHeaders($this->bearer)->getJson('/api/otp/01711111116?consume=0');

    $response->assertOk()->assertJsonFragment(['otp_code' => 'NOCONS']);
    expect($otp->fresh()->consumed_at)->toBeNull();
});

test('invalid bearer token returns 401', function () {
    makeOtp('01711111117', 'CODE99', null, now());

    $response = $this->withHeaders(['Authorization' => 'Bearer wrong'])
        ->getJson('/api/otp/01711111117');

    $response->assertStatus(401)->assertJsonFragment(['error' => 'invalid_api_key']);
});

test('404 when no matching otp exists', function () {
    $response = $this->withHeaders($this->bearer)->getJson('/api/otp/01799999999');

    $response->assertStatus(404)->assertJsonFragment(['error' => 'not_found']);
});
