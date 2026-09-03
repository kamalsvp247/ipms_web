<?php

use App\Models\Account;
use App\Models\PaymentLink;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function paidCompletionLink(Account $account, array $attributes = []): PaymentLink
{
    return PaymentLink::factory()->create(array_merge([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-'.uniqid(),
        'is_fake' => false,
        'review_status' => 'unread',
    ], $attributes));
}

it('completes the account when a link is marked paid', function () {
    $account = Account::factory()->create(['phone' => '01700000061', 'status' => 'running']);
    $link = paidCompletionLink($account);

    $link->update(['review_status' => 'succeeded']);

    expect($account->fresh()->status)->toBe('completed');
});

it('backfills accounts paid before the rule existed', function () {
    $account = Account::factory()->create(['phone' => '01700000062', 'status' => 'running']);

    PaymentLink::withoutEvents(fn () => paidCompletionLink($account, ['review_status' => 'succeeded']));

    $this->artisan('accounts:complete-paid')->assertSuccessful();

    expect($account->fresh()->status)->toBe('completed');
});
