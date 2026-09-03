<?php

use App\Jobs\AutoPaymentJob;
use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function sweepAccount(): Account
{
    return Account::factory()->create([
        'phone' => '01700000009',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);
}

function sweepLink(Account $account, array $overrides = []): PaymentLink
{
    return PaymentLink::factory()->create(array_merge([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-'.uniqid(),
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => false,
        'created_at' => now()->subMinutes(2),
    ], $overrides));
}

it('re-dispatches a link whose inline dispatch never landed', function () {
    $link = sweepLink(sweepAccount());

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertPushed(AutoPaymentJob::class);
    expect(PaymentAutomationAttempt::where('payment_link_id', $link->id)->count())->toBe(1);
});

it('retries a failed attempt that still has tries left', function () {
    $link = sweepLink(sweepAccount());
    PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $link->account_id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => 1,
    ]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertPushed(AutoPaymentJob::class);
});

it('leaves a running attempt alone so two browsers never race one link', function () {
    $link = sweepLink(sweepAccount());
    PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $link->account_id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_RUNNING,
        'attempts' => 1,
    ]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('stops retrying once the attempt cap is reached', function () {
    $link = sweepLink(sweepAccount());
    PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $link->account_id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => PaymentAutomationAttempt::MAX_ATTEMPTS,
    ]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('ignores links that already have a callback URL', function () {
    sweepLink(sweepAccount(), [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=x',
    ]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('ignores links older than the lookback window', function () {
    sweepLink(sweepAccount(), ['created_at' => now()->subHours(4)]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertNotPushed(AutoPaymentJob::class);
});

/** A second account sharing the first one's wallet number — the collision this guard exists for. */
function sweepWalletTwin(string $phone, string $wallet): Account
{
    return Account::factory()->create([
        'phone' => $phone,
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => $wallet,
        'auto_payment_pin' => '4321',
    ]);
}

it('dispatches only one link per shared wallet, choosing the one closest to expiry', function () {
    $wallet = '018651441477';
    $older = sweepLink(sweepWalletTwin('01700000061', $wallet), ['created_at' => now()->subMinutes(3)]);
    sweepLink(sweepWalletTwin('01700000062', $wallet), ['created_at' => now()->subMinutes(1)]);

    artisan('payments:sweep-auto')->assertExitCode(0);

    // The fresher link can afford to wait another minute; the older one cannot.
    expect(PaymentAutomationAttempt::count())->toBe(1)
        ->and(PaymentAutomationAttempt::first()->payment_link_id)->toBe($older->id);
});

it('skips a wallet that already has a run in flight', function () {
    $wallet = '018651441477';
    $busyLink = sweepLink(sweepWalletTwin('01700000063', $wallet));
    PaymentAutomationAttempt::create([
        'payment_link_id' => $busyLink->id,
        'account_id' => $busyLink->account_id,
        'method' => 'rocket',
        'status' => PaymentAutomationAttempt::STATUS_RUNNING,
        'attempts' => 1,
    ]);

    sweepLink(sweepWalletTwin('01700000064', $wallet));

    artisan('payments:sweep-auto')->assertExitCode(0);

    // Dispatching the twin would only produce a deferral, so it is not dispatched at all.
    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('never sweeps a link for an account that already paid', function () {
    $account = sweepAccount();
    sweepLink($account, ['callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-old']);
    sweepLink($account, ['reservation_id' => 'res-new']);

    artisan('payments:sweep-auto')->assertExitCode(0);

    Queue::assertNotPushed(AutoPaymentJob::class);
});
