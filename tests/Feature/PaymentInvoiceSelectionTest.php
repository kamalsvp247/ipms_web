<?php

use App\Models\AccountSession;
use App\Models\PaymentLink;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Build a link in the shape the callback router leaves behind.
 */
function callbackLink(string $phone, ?string $redirect, array $overrides = []): PaymentLink
{
    return PaymentLink::factory()->create(array_merge([
        'account_phone' => $phone,
        'reservation_id' => 'res-'.$phone,
        'callback_status' => 'success',
        'callback_status_code' => 302,
        'callback_response' => $redirect === null ? null : 'HTTP 302 Location: '.$redirect,
        'is_fake' => false,
    ], $overrides));
}

it('keeps confirmed payments out of the declined set', function () {
    $confirmed = callbackLink('01700000001', 'https://appointment.ivacbd.com/appointment/confirm-payment');
    $declined = callbackLink('01700000002', 'https://appointment.ivacbd.com/payment/fail');

    expect($confirmed->wasDeclinedByIvac())->toBeFalse()
        ->and($declined->wasDeclinedByIvac())->toBeTrue();
});

it('excludes IVAC-declined payments from the invoiceable set', function () {
    // callback_status is 'success' on both: it reports that our POST of the callback got a 2xx,
    // not that the payment was accepted. Link 1295 read exactly this way while IVAC answered
    // /payment/fail, and its invoice fetch then 500'd with an incident ID.
    $confirmed = callbackLink('01700000001', 'https://appointment.ivacbd.com/appointment/confirm-payment');
    $declined = callbackLink('01700000002', 'https://appointment.ivacbd.com/payment/fail');

    $ids = PaymentLink::invoiceable()->pluck('id');

    expect($ids)->toContain($confirmed->id)
        ->and($ids)->not->toContain($declined->id);
});

it('keeps a link whose verdict was never recorded', function () {
    // Ambiguous, not declined. Dropping it would make its invoice vanish from the ZIP silently;
    // attempting it costs one visible failed fetch instead.
    $unknown = callbackLink('01700000003', null);

    expect(PaymentLink::invoiceable()->pluck('id'))->toContain($unknown->id);
});

it('excludes seeded fakes and links with no reservation', function () {
    $fake = callbackLink('01700000004', 'https://appointment.ivacbd.com/appointment/confirm-payment', ['is_fake' => true]);
    $noRes = callbackLink('01700000005', 'https://appointment.ivacbd.com/appointment/confirm-payment', ['reservation_id' => null]);

    $ids = PaymentLink::invoiceable()->pluck('id');

    expect($ids)->not->toContain($fake->id)
        ->and($ids)->not->toContain($noRes->id);
});

it('does not count an IVAC-declined payment as money moved', function () {
    // Both halves of scopeCompleted() are true of a decline — the driver stored a callback URL and
    // the bot reported the 302 as success — so counting it as paid made the account's paid-guard
    // permanently block auto payment on the replacement link IVAC issues (account 988, link 1295).
    $declined = callbackLink('01700000002', 'https://appointment.ivacbd.com/payment/fail', [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=x',
    ]);

    expect(PaymentLink::completed()->pluck('id'))->not->toContain($declined->id);
});

it('counts a confirmed payment as money moved', function () {
    $paid = callbackLink('01700000001', 'https://appointment.ivacbd.com/appointment/confirm-payment', [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=y',
    ]);

    expect(PaymentLink::completed()->pluck('id'))->toContain($paid->id);
});

it('still counts a payment whose verdict was never recorded', function () {
    // Ambiguity stays on the safe side here, unlike the invoice set: guessing wrong in this
    // direction charges a wallet a second time.
    $unknown = callbackLink('01700000003', null, [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=z',
    ]);

    expect(PaymentLink::completed()->pluck('id'))->toContain($unknown->id);
});

it('unblocks auto payment for an account whose only payment was declined', function () {
    $account = \App\Models\Account::factory()->create([
        'phone' => '01700000002',
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '018651441477',
        'auto_payment_pin' => '1234',
    ]);
    callbackLink($account->phone, 'https://appointment.ivacbd.com/payment/fail', [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=x',
    ]);

    expect($account->hasCompletedPayment())->toBeFalse();
});

it('keeps blocking an account that genuinely paid', function () {
    $account = \App\Models\Account::factory()->create([
        'phone' => '01700000001',
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '018651441477',
        'auto_payment_pin' => '1234',
    ]);
    callbackLink($account->phone, 'https://appointment.ivacbd.com/appointment/confirm-payment', [
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=y',
    ]);

    expect($account->hasCompletedPayment())->toBeTrue();
});

it('fetches an invoice with the reservation owner\'s own session token', function () {
    // Owner-first is a preference, not a requirement: IVAC scopes the invoice to the txrId in the
    // URL, so any live token downloads it (verified Aug 13 2026 across five reservations whose own
    // sessions had expired). The 500 + incident ID that looked like an ownership mismatch was a
    // missing raw Turnstile x-token header.
    AccountSession::factory()->create([
        'phone' => '01700000001',
        'jwt_token' => 'owner-token',
        'jwt_expires_at' => now()->addMinutes(10),
    ]);
    AccountSession::factory()->create([
        'phone' => '01700000009',
        'jwt_token' => 'stranger-token',
        'jwt_expires_at' => now()->addHour(),
    ]);

    $controller = new \App\Http\Controllers\Api\PaymentLinkController;
    $method = new ReflectionMethod($controller, 'jwtForPhone');
    $method->setAccessible(true);

    expect($method->invoke($controller, '01700000001'))->toBe('owner-token');
});

it('falls back to any live token when the owner has no session', function () {
    AccountSession::factory()->create([
        'phone' => '01700000009',
        'jwt_token' => 'stranger-token',
        'jwt_expires_at' => now()->addHour(),
    ]);

    $controller = new \App\Http\Controllers\Api\PaymentLinkController;
    $method = new ReflectionMethod($controller, 'jwtForPhone');
    $method->setAccessible(true);

    expect($method->invoke($controller, '01700000001'))->toBe('stranger-token');
});

it('does not hand back an expired token', function () {
    AccountSession::factory()->create([
        'phone' => '01700000001',
        'jwt_token' => 'stale-token',
        'jwt_expires_at' => now()->subMinute(),
    ]);

    $controller = new \App\Http\Controllers\Api\PaymentLinkController;
    $method = new ReflectionMethod($controller, 'jwtForPhone');
    $method->setAccessible(true);

    expect($method->invoke($controller, '01700000001'))->toBeNull();
});
