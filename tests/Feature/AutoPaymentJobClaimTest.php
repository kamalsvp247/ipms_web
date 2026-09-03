<?php

use App\Jobs\AutoPaymentJob;
use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Services\Payment\PaymentAutomationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Stand-in for the real service so these tests exercise the claim mutex without launching Chrome.
 */
class RecordingAutomationService extends PaymentAutomationService
{
    public static int $runs = 0;

    public function __construct()
    {
        // No parent call on purpose — this double has no collaborators.
    }

    public function run(PaymentAutomationAttempt $attempt): void
    {
        self::$runs++;
    }
}

beforeEach(function () {
    RecordingAutomationService::$runs = 0;
});

function claimableAttempt(array $overrides = []): PaymentAutomationAttempt
{
    $account = Account::factory()->create([
        'phone' => '01700000021',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-claim',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
    ]);

    return PaymentAutomationAttempt::create(array_merge([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_PENDING,
    ], $overrides));
}

it('runs the attempt exactly once when two jobs race the same link', function () {
    $attempt = claimableAttempt();
    $service = new RecordingAutomationService;

    (new AutoPaymentJob($attempt->id))->handle($service);
    // The second job finds the row already running and returns without doing the work.
    (new AutoPaymentJob($attempt->id))->handle($service);

    expect(RecordingAutomationService::$runs)->toBe(1)
        ->and($attempt->refresh()->attempts)->toBe(1);
});

it('does not run an attempt that already succeeded', function () {
    $attempt = claimableAttempt(['status' => PaymentAutomationAttempt::STATUS_SUCCEEDED]);

    (new AutoPaymentJob($attempt->id))->handle(new RecordingAutomationService);

    expect(RecordingAutomationService::$runs)->toBe(0);
});

it('does not run an attempt that has exhausted its tries', function () {
    $attempt = claimableAttempt([
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => PaymentAutomationAttempt::MAX_ATTEMPTS,
    ]);

    (new AutoPaymentJob($attempt->id))->handle(new RecordingAutomationService);

    expect(RecordingAutomationService::$runs)->toBe(0);
});

it('retries a failed attempt that still has tries left', function () {
    $attempt = claimableAttempt([
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => 1,
    ]);

    (new AutoPaymentJob($attempt->id))->handle(new RecordingAutomationService);

    expect(RecordingAutomationService::$runs)->toBe(1)
        ->and($attempt->refresh()->attempts)->toBe(2);
});

it('does not retry once a run has opened the gateway checkout', function () {
    // dg-epay binds a checkout to the browser that opened it, and that browser is gone. Retrying
    // can only reach SESSION ACTIVE ELSEWHERE, which spends the remaining tries and overwrites the
    // error that explained the real failure (link 1293: a Pay button that never became pressable).
    $attempt = claimableAttempt([
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => 1,
        'stage' => 'authorize',
        'last_error' => 'The checkout stayed on dg-epay — Pay was never pressable',
    ]);

    (new AutoPaymentJob($attempt->id))->handle(new RecordingAutomationService);

    expect(RecordingAutomationService::$runs)->toBe(0)
        ->and($attempt->refresh()->last_error)->toContain('never pressable');
});

it('still retries a run that failed before the checkout was opened', function () {
    // Deferred for a busy wallet or a full slot: nothing was opened, so nothing is bound yet.
    $attempt = claimableAttempt([
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => 1,
        'stage' => 'wallet_busy',
    ]);

    (new AutoPaymentJob($attempt->id))->handle(new RecordingAutomationService);

    expect(RecordingAutomationService::$runs)->toBe(1);
});

it('releases a running attempt back to failed when the worker dies', function () {
    $attempt = claimableAttempt(['status' => PaymentAutomationAttempt::STATUS_RUNNING]);

    (new AutoPaymentJob($attempt->id))->failed(new RuntimeException('worker killed'));

    $attempt->refresh();
    expect($attempt->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->last_error)->toBe('worker killed')
        ->and($attempt->finished_at)->not->toBeNull();
});
