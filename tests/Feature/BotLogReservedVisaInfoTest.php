<?php

use App\Models\AccountSession;
use App\Models\AgentSlot;

beforeEach(function () {
    $this->slot = AgentSlot::create([
        'name' => 'Test Slot',
        'api_key' => 'test-slot-key-'.uniqid(),
    ]);
});

function ingestLogs(string $apiKey, array $logs)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$apiKey])
        ->postJson('/api/slots/logs', ['logs' => $logs]);
}

it('captures reserved visa type and applicant count from a successful reserve-slot log', function () {
    $session = AccountSession::create(['phone' => '01700000001']);

    $response = ingestLogs($this->slot->api_key, [[
        'account_phone' => '01700000001',
        'method' => 'POST',
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'status_code' => 200,
        'response_body' => json_encode([
            'status' => 'OK_NEW',
            'reservationId' => '69375f68-bc69-4a86-a213-9b0988403e49',
            'appointmentDate' => '2026-06-23',
            'countByType' => ['MEDICAL' => 4],
            'reserveTtlSeconds' => 660,
            'message' => 'Reserved booking',
        ]),
        'logged_at' => now()->toDateTimeString(),
    ]]);

    $response->assertOk();
    $session->refresh();
    expect($session->reserved_visa_type)->toBe('MEDICAL')
        ->and($session->reserved_applicants)->toBe(4);
});

it('captures visa info from a FULL reserve-slot response with a null reservationId', function () {
    $session = AccountSession::create(['phone' => '01700000002']);

    ingestLogs($this->slot->api_key, [[
        'account_phone' => '01700000002',
        'method' => 'POST',
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'status_code' => 200,
        'response_body' => json_encode([
            'status' => 'FULL',
            'reservationId' => null,
            'countByType' => ['MISCELLANEOUS' => 2],
            'message' => 'Selected slot is completely booked for now.',
        ]),
        'logged_at' => now()->toDateTimeString(),
    ]])->assertOk();

    $session->refresh();
    expect($session->reserved_visa_type)->toBe('MISCELLANEOUS')
        ->and($session->reserved_applicants)->toBe(2);
});

it('ignores reserve-slot responses without countByType (e.g. 429/incident)', function () {
    $session = AccountSession::create(['phone' => '01700000004']);

    ingestLogs($this->slot->api_key, [[
        'account_phone' => '01700000004',
        'method' => 'POST',
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'status_code' => 200,
        'response_body' => json_encode([
            'code' => 429,
            'message' => 'Please wait a little longer before trying again.',
        ]),
        'logged_at' => now()->toDateTimeString(),
    ]])->assertOk();

    $session->refresh();
    expect($session->reserved_visa_type)->toBeNull()
        ->and($session->reserved_applicants)->toBeNull();
});

it('keeps the latest reservation per phone within a batch', function () {
    $session = AccountSession::create(['phone' => '01700000003']);

    ingestLogs($this->slot->api_key, [
        [
            'account_phone' => '01700000003',
            'method' => 'POST',
            'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
            'status_code' => 200,
            'response_body' => json_encode(['reservationId' => 'a', 'countByType' => ['MEDICAL' => 1]]),
            'logged_at' => now()->subSeconds(30)->toDateTimeString(),
        ],
        [
            'account_phone' => '01700000003',
            'method' => 'POST',
            'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
            'status_code' => 200,
            'response_body' => json_encode(['reservationId' => 'b', 'countByType' => ['TOURISM' => 3]]),
            'logged_at' => now()->toDateTimeString(),
        ],
    ])->assertOk();

    $session->refresh();
    expect($session->reserved_visa_type)->toBe('TOURISM')
        ->and($session->reserved_applicants)->toBe(3);
});
