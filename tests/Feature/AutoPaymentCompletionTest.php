<?php

use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Services\Payment\PaymentAutomationService;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Captures the job handed to the Node driver and returns a canned result, so the completion wiring
 * can be asserted without launching Chrome or contacting dgepay.
 */
class CompletionCapturingService extends PaymentAutomationService
{
    /** @var array<string, mixed> */
    public static array $job = [];

    /** @var array<string, mixed> */
    public static array $result = [];

    /**
     * @param  array<string, mixed>  $job
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function runDriver(PaymentAutomationAttempt $attempt, array $job): array
    {
        self::$job = $job;

        return [self::$result, 'captured driver log'];
    }
}

function completionAttempt(): PaymentAutomationAttempt
{
    $account = Account::factory()->create([
        'phone' => '01700000031',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-complete',
        'gateway_page_url' => 'https://checkout.dgepay.net/check-out/abc123',
        'callback_url' => null,
        'is_fake' => false,
    ]);

    return PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_RUNNING,
    ]);
}

beforeEach(function () {
    // The browser cap is a set of named locks now, not a counter; a leaked one would starve the
    // next test of a slot and fail it as "concurrency limit reached".
    Cache::flush();
    CompletionCapturingService::$job = [];
    CompletionCapturingService::$result = [];
});

it('frees its browser slot and its wallet after a successful run', function () {
    CompletionCapturingService::$result = [
        'ok' => true,
        'stage' => 'callback',
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-complete',
    ];

    app(CompletionCapturingService::class)->run(completionAttempt());

    expect(Cache::lock('payment:automation:slot:1', 5)->get())->toBeTrue()
        ->and(Cache::lock(App\Support\PaymentWalletLock::key('01712345678'), 5)->get())->toBeTrue();
});

it('frees its browser slot and its wallet after a failed run', function () {
    CompletionCapturingService::$result = ['ok' => false, 'stage' => 'otp', 'error' => 'rejected'];

    app(CompletionCapturingService::class)->run(completionAttempt());

    expect(Cache::lock('payment:automation:slot:1', 5)->get())->toBeTrue()
        ->and(Cache::lock(App\Support\PaymentWalletLock::key('01712345678'), 5)->get())->toBeTrue();
});

it('hands the driver the real PIN so the checkout can be completed', function () {
    CompletionCapturingService::$result = [
        'ok' => true,
        'stage' => 'callback',
        'callback_url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?status=success',
    ];

    app(CompletionCapturingService::class)->run(completionAttempt());

    // The PIN is the step that charges; withholding it was the dry-run brake that is now gone.
    expect(CompletionCapturingService::$job['pin'])->toBe('4321')
        ->and(CompletionCapturingService::$job)->not->toHaveKey('stop_at_pin')
        ->and(CompletionCapturingService::$job)->not->toHaveKey('stop_at_otp');
});

it('records a completed checkout as succeeded and delivers the callback to the bot', function () {
    $callback = 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?status=success&tran_id=res-complete';
    CompletionCapturingService::$result = ['ok' => true, 'stage' => 'callback', 'callback_url' => $callback];

    $attempt = completionAttempt();
    app(CompletionCapturingService::class)->run($attempt);

    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_SUCCEEDED)
        ->and($attempt->stage)->toBe('callback')
        ->and($attempt->callback_url)->toBe($callback)
        ->and($attempt->last_error)->toBeNull()
        // The whole point of the feature: the field the Java bot polls is now filled.
        ->and($attempt->paymentLink->refresh()->callback_url)->toBe($callback);
});

it('fails the run when the gateway never sent an OTP', function () {
    CompletionCapturingService::$result = [
        'ok' => false,
        'stage' => 'submit_wallet',
        'error' => 'Wallet submitted but no OTP field appeared — the gateway did not send a code.',
    ];

    $attempt = completionAttempt();
    app(CompletionCapturingService::class)->run($attempt);

    // Must not be blamed on the OTP wait: the gateway refused the wallet before any SMS was due.
    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('submit_wallet')
        ->and($attempt->last_error)->toContain('no OTP field appeared')
        ->and($attempt->paymentLink->refresh()->callback_url)->toBeNull();
});

it('fails the run when the OTP was rejected, before the PIN is ever submitted', function () {
    CompletionCapturingService::$result = [
        'ok' => false,
        'stage' => 'otp',
        'error' => 'OTP submitted but no PIN field appeared — the code was likely rejected or expired.',
    ];

    $attempt = completionAttempt();
    app(CompletionCapturingService::class)->run($attempt);

    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('otp')
        ->and($attempt->last_error)->toContain('no PIN field appeared');
});

it('never records success without a callback URL', function () {
    // A driver that claims ok but produces nothing must not leave the link looking settled.
    CompletionCapturingService::$result = ['ok' => true, 'stage' => 'callback'];

    $attempt = completionAttempt();
    app(CompletionCapturingService::class)->run($attempt);

    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->last_error)->toContain('without a callback URL')
        ->and($attempt->paymentLink->refresh()->callback_url)->toBeNull();
});
