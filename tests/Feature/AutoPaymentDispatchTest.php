<?php

use App\Jobs\AutoPaymentJob;
use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function autoPayAccount(array $overrides = []): Account
{
    return Account::factory()->create(array_merge([
        'phone' => '01700000001',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ], $overrides));
}

/**
 * The shape the Java bot posts after a successful dg-epay initiate.
 */
function ingestPayload(string $phone, array $overrides = []): array
{
    return array_merge([
        'data' => ['webview_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc123'],
        'statusCode' => 201,
        'message' => 'Initiated',
        'successFlag' => true,
        'account_phone' => $phone,
        'reservation_id' => 'res-'.$phone,
    ], $overrides);
}

it('dispatches auto payment when the bot posts a link for an enabled account', function () {
    $account = autoPayAccount();

    $this->postJson('/api/payment-links', ingestPayload($account->phone))->assertCreated();

    Queue::assertPushed(AutoPaymentJob::class);

    $attempt = PaymentAutomationAttempt::firstOrFail();
    expect($attempt->account_id)->toBe($account->id)
        ->and($attempt->method)->toBe('nagad')
        ->and($attempt->status)->toBe(PaymentAutomationAttempt::STATUS_PENDING);
});

it('does not dispatch when auto payment is off', function () {
    $account = autoPayAccount(['auto_payment' => false]);

    $this->postJson('/api/payment-links', ingestPayload($account->phone))->assertCreated();

    Queue::assertNotPushed(AutoPaymentJob::class);
    expect(PaymentAutomationAttempt::count())->toBe(0);
});

it('does not dispatch when the credential set is incomplete', function () {
    $account = autoPayAccount(['auto_payment_pin' => null]);

    $this->postJson('/api/payment-links', ingestPayload($account->phone))->assertCreated();

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('does not dispatch for a non-dgepay checkout URL', function () {
    $account = autoPayAccount();

    $this->postJson('/api/payment-links', ingestPayload($account->phone, [
        'data' => ['GatewayPageURL' => 'https://pay.sslcommerz.com/abc123'],
    ]))->assertCreated();

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('does not dispatch when no account matches the phone', function () {
    $this->postJson('/api/payment-links', ingestPayload('01799999999'))->assertCreated();

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('creates at most one attempt per payment link', function () {
    $account = autoPayAccount();
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-1',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => false,
    ]);

    $dispatcher = app(\App\Services\Payment\AutoPaymentDispatcher::class);
    expect($dispatcher->dispatchFor($link))->toBeTrue();
    // Second call reuses the pending row rather than creating a duplicate.
    $dispatcher->dispatchFor($link);

    expect(PaymentAutomationAttempt::where('payment_link_id', $link->id)->count())->toBe(1);
});

it('skips a link that already has a callback URL', function () {
    $account = autoPayAccount();
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-2',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-2',
        'is_fake' => false,
    ]);

    expect(app(\App\Services\Payment\AutoPaymentDispatcher::class)->dispatchFor($link))->toBeFalse();
});

it('skips seeded fake links so decoys never spend money', function () {
    $account = autoPayAccount();
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-3',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => true,
    ]);

    expect(app(\App\Services\Payment\AutoPaymentDispatcher::class)->dispatchFor($link))->toBeFalse();
});

it('does not dispatch a link for an account that already paid', function () {
    $account = autoPayAccount(['phone' => '01700000071']);
    PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-old',
        'gateway_page_url' => 'https://checkout.dgepay.net/check-out/old',
        'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-old',
        'is_fake' => false,
    ]);

    $this->postJson('/api/payment-links', ingestPayload($account->phone))->assertCreated();

    // IVAC reissues links after a payment lands; paying another would charge the wallet twice.
    Queue::assertNotPushed(AutoPaymentJob::class);
    expect(PaymentAutomationAttempt::count())->toBe(0);
});
