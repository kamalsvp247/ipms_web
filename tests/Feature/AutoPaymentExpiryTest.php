<?php

use App\Jobs\AutoPaymentJob;
use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Services\Payment\AutoPaymentDispatcher;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

/**
 * Each account gets its own wallet unless a test says otherwise. Two accounts sharing one wallet
 * are serialised by PaymentWalletLock and deduped by the sweep, which is correct but would quietly
 * change what the link-selection tests here are measuring.
 */
function expiryAccount(string $phone = '01700000061', string $wallet = '01712345678'): Account
{
    return Account::factory()->create([
        'phone' => $phone,
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => $wallet,
        'auto_payment_pin' => '4321',
    ]);
}

function expiryLink(Account $account, array $overrides = []): PaymentLink
{
    return PaymentLink::factory()->create(array_merge([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-'.uniqid(),
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => false,
        'created_at' => now(),
    ], $overrides));
}

it('computes the five minute checkout window', function () {
    $link = expiryLink(expiryAccount(), ['created_at' => now()->subMinutes(2)]);

    expect(PaymentLink::EXPIRY_MINUTES)->toBe(5)
        ->and($link->isExpired())->toBeFalse()
        ->and($link->secondsUntilExpiry())->toBeGreaterThan(150)
        ->and($link->secondsUntilExpiry())->toBeLessThanOrEqual(180);
});

it('treats a link older than the window as expired', function () {
    $link = expiryLink(expiryAccount(), ['created_at' => now()->subMinutes(6)]);

    expect($link->isExpired())->toBeTrue()
        ->and($link->secondsUntilExpiry())->toBe(0);
});

it('does not dispatch an expired link', function () {
    $link = expiryLink(expiryAccount(), ['created_at' => now()->subMinutes(6)]);

    expect(app(AutoPaymentDispatcher::class)->dispatchFor($link))->toBeFalse();
    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('does not dispatch a link superseded by a newer one for the same account', function () {
    $account = expiryAccount();
    $older = expiryLink($account);
    $newer = expiryLink($account);

    expect($older->isSupersededForAccount())->toBeTrue()
        ->and($newer->isSupersededForAccount())->toBeFalse();

    expect(app(AutoPaymentDispatcher::class)->dispatchFor($older))->toBeFalse();
    expect(app(AutoPaymentDispatcher::class)->dispatchFor($newer))->toBeTrue();
});

it('sweeps only the newest live link per account', function () {
    $account = expiryAccount();
    // Insert order matches real life: ids and created_at both increase.
    expiryLink($account, ['created_at' => now()->subMinutes(9)]); // long expired
    expiryLink($account, ['created_at' => now()->subMinutes(1)]); // superseded by the next
    $newest = expiryLink($account);                              // the one to pay

    // A second account, on its own wallet, keeps its own newest link.
    $other = expiryAccount('01700000062', '01798765432');
    $otherNewest = expiryLink($other);

    artisan('payments:sweep-auto')->assertExitCode(0);

    $paid = PaymentAutomationAttempt::pluck('payment_link_id')->all();
    sort($paid);
    expect($paid)->toBe(collect([$newest->id, $otherNewest->id])->sort()->values()->all());
});

it('fails a run whose link expired before the worker picked it up', function () {
    $account = expiryAccount();
    $link = expiryLink($account);

    $attempt = PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_PENDING,
    ]);

    // The link ages out between dispatch and execution.
    $link->forceFill(['created_at' => now()->subMinutes(6)])->save();

    (new AutoPaymentJob($attempt->id))->handle(app(\App\Services\Payment\PaymentAutomationService::class));

    $attempt->refresh();
    expect($attempt->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('expired')
        ->and($attempt->last_error)->toContain('expired');
});

it('fails a run whose link was superseded before the worker picked it up', function () {
    $account = expiryAccount();
    $link = expiryLink($account);

    $attempt = PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_PENDING,
    ]);

    expiryLink($account); // a newer link arrives

    (new AutoPaymentJob($attempt->id))->handle(app(\App\Services\Payment\PaymentAutomationService::class));

    $attempt->refresh();
    expect($attempt->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('superseded');
});

it('exposes the countdown and expiry flags on the log page', function () {
    $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin']));
    $account = expiryAccount();
    $link = expiryLink($account, ['created_at' => now()->subMinutes(1)]);

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('link.expiry_minutes', 5)
            ->where('link.is_expired', false)
            ->where('link.is_superseded', false)
            ->whereNot('link.expires_at', null)
            ->where('eligibility.not_expired', true)
            ->where('eligibility.is_latest_for_account', true)
        );
});
