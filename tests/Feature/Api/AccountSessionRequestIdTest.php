<?php

use App\Models\PreStagedSession;

test('returns request_id for phone with an existing pre-staged session', function () {
    PreStagedSession::create([
        'phone' => '01711111111',
        'request_id' => 'abc-123-request-id',
    ]);

    $response = $this->getJson('/api/account-sessions/request-id?phone=01711111111');

    $response->assertOk()
        ->assertJson(['request_id' => 'abc-123-request-id']);
});

test('returns null request_id when no pre-staged session exists for phone', function () {
    $response = $this->getJson('/api/account-sessions/request-id?phone=09999999999');

    $response->assertOk()
        ->assertJson(['request_id' => null]);
});

test('returns 400 when phone query param is missing', function () {
    $response = $this->getJson('/api/account-sessions/request-id');

    $response->assertStatus(400)
        ->assertJson(['error' => 'phone required']);
});

test('endpoint is accessible without authentication', function () {
    $response = $this->getJson('/api/account-sessions/request-id?phone=01744444444');

    $response->assertStatus(200);
});
