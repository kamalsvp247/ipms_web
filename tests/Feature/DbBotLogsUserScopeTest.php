<?php

use App\Models\Account;
use App\Models\BotLog;
use App\Models\User;

function makeScopedBotLog(array $overrides = []): BotLog
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

it('lets a non-admin see only logs for accounts they manage', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $otherUser = User::factory()->create(['role' => 'user']);

    $ownAccount = Account::factory()->create(['user_id' => $owner->id, 'phone' => '01711111111']);
    Account::factory()->create(['user_id' => $otherUser->id, 'phone' => '01722222222']);

    $ownLog = makeScopedBotLog(['account_phone' => $ownAccount->phone]);
    $otherLog = makeScopedBotLog(['account_phone' => '01722222222']);
    $unattachedLog = makeScopedBotLog(['account_phone' => null, 'method' => 'LOG']);

    $response = $this->actingAs($owner)->getJson('/api/db-bot-logs');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($ownLog->id)
        ->and($ids)->toContain($unattachedLog->id)
        ->and($ids)->not->toContain($otherLog->id);
});

it('lets an admin see logs for every account', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $owner = User::factory()->create(['role' => 'user']);

    Account::factory()->create(['user_id' => $owner->id, 'phone' => '01733333333']);
    $log = makeScopedBotLog(['account_phone' => '01733333333']);

    $response = $this->actingAs($admin)->getJson('/api/db-bot-logs');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($log->id);
});

it('scopes the phones endpoint to a non-admin user own accounts', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $otherUser = User::factory()->create(['role' => 'user']);

    Account::factory()->create(['user_id' => $owner->id, 'phone' => '01744444444']);
    Account::factory()->create(['user_id' => $otherUser->id, 'phone' => '01755555555']);

    makeScopedBotLog(['account_phone' => '01744444444']);
    makeScopedBotLog(['account_phone' => '01755555555']);

    $response = $this->actingAs($owner)->getJson('/api/db-bot-logs/phones');

    $response->assertOk();
    $phones = $response->json();

    expect($phones)->toContain('01744444444')
        ->and($phones)->not->toContain('01755555555');
});
