<?php

use App\Models\BotLog;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
});

function makeBotLog(array $overrides = []): BotLog
{
    return BotLog::create(array_merge([
        'account_phone' => '01700000000',
        'method' => 'POST',
        'url' => 'https://api.ivacbd.com/iams/api/v1/signin',
        'status_code' => 200,
        'response_body' => '{"ok":true}',
        'logged_at' => now(),
    ], $overrides));
}

it('keeps non-reserve-slot rows and only drops reserve-slot rows without a slot id', function () {
    // Reserve-slot row WITH a slot id — should be shown
    $reservedOk = makeBotLog([
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'response_body' => '{"reservationId":"ABC123"}',
    ]);

    // Reserve-slot row WITHOUT a slot id — should be hidden
    $reservedFail = makeBotLog([
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'response_body' => '{"error":"slot taken"}',
    ]);

    // Other endpoint row — should always be shown
    $signin = makeBotLog([
        'url' => 'https://api.ivacbd.com/iams/api/v1/signin',
        'response_body' => '{"token":"jwt"}',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?has_reservation_id=1');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($reservedOk->id)
        ->and($ids)->toContain($signin->id)
        ->and($ids)->not->toContain($reservedFail->id);
});

it('returns all rows when the filter is off', function () {
    $reservedFail = makeBotLog([
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/ccd3dd63-e781-48ba-a48d-c65eaa4fc663/reserve-slot',
        'response_body' => '{"error":"slot taken"}',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($reservedFail->id);
});

it('sorts by duration ascending and descending', function () {
    $slow = makeBotLog(['duration_ms' => 900, 'logged_at' => now()->subMinutes(2)]);
    $fast = makeBotLog(['duration_ms' => 100, 'logged_at' => now()->subMinute()]);
    $mid = makeBotLog(['duration_ms' => 500, 'logged_at' => now()]);

    $asc = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?paginate=1&sort_by=duration_ms&sort_dir=asc');
    $asc->assertOk();
    expect(collect($asc->json('data'))->pluck('id')->all())
        ->toBe([$fast->id, $mid->id, $slow->id]);

    $desc = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?paginate=1&sort_by=duration_ms&sort_dir=desc');
    $desc->assertOk();
    expect(collect($desc->json('data'))->pluck('id')->all())
        ->toBe([$slow->id, $mid->id, $fast->id]);
});

it('filters by multiple phones', function () {
    $a = makeBotLog(['account_phone' => '01711111111']);
    $b = makeBotLog(['account_phone' => '01722222222']);
    $c = makeBotLog(['account_phone' => '01733333333']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?'.http_build_query([
            'phones' => ['01711111111', '01733333333'],
        ]));

    $response->assertOk();
    $phones = collect($response->json('data'))->pluck('account_phone')->unique()->values()->all();

    expect($phones)->toContain('01711111111')
        ->and($phones)->toContain('01733333333')
        ->and($phones)->not->toContain('01722222222');
});

it('returns only proxy-routed rows when proxy_only is set', function () {
    $proxy = makeBotLog(['remote_ip' => 'proxy:pr.oxylabs.io']);
    $direct = makeBotLog(['remote_ip' => 'api.ivacbd.com']);
    $noIp = makeBotLog(['remote_ip' => null]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?proxy_only=1');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($proxy->id)
        ->and($ids)->not->toContain($direct->id)
        ->and($ids)->not->toContain($noIp->id);
});

it('returns all rows when proxy_only is off', function () {
    $proxy = makeBotLog(['remote_ip' => 'proxy:pr.oxylabs.io']);
    $direct = makeBotLog(['remote_ip' => 'api.ivacbd.com']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($proxy->id)
        ->and($ids)->toContain($direct->id);
});

it('sorts by logged_at ascending', function () {
    $oldest = makeBotLog(['logged_at' => now()->subHours(2)]);
    $newest = makeBotLog(['logged_at' => now()]);

    $asc = $this->actingAs($this->admin)
        ->getJson('/api/db-bot-logs?paginate=1&sort_by=logged_at&sort_dir=asc');
    $asc->assertOk();
    $ids = collect($asc->json('data'))->pluck('id')->all();

    expect(array_search($oldest->id, $ids))->toBeLessThan(array_search($newest->id, $ids));
});
