<?php

use App\Jobs\AutoPaymentJob;
use App\Models\Account;
use App\Models\OtpCode;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Services\Payment\PaymentAutomationService;
use App\Support\PaymentWalletLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Stands in for the Node driver so a run can be observed without launching Chrome. The stage
 * callback lets a test replay the markers the real driver streams, which is how the early wallet
 * release is exercised.
 */
class WalletLockCapturingService extends PaymentAutomationService
{
    public static int $driverCalls = 0;

    /** @var array<string, mixed> */
    public static array $result = [];

    /** @var (\Closure(PaymentAutomationService, PaymentAutomationAttempt): void)|null */
    public static ?Closure $duringDriver = null;

    public static bool $throwFromDriver = false;

    /**
     * @param  array<string, mixed>  $job
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function runDriver(PaymentAutomationAttempt $attempt, array $job): array
    {
        self::$driverCalls++;

        if (self::$duringDriver !== null) {
            (self::$duringDriver)($this, $attempt);
        }

        if (self::$throwFromDriver) {
            throw new RuntimeException('driver exploded');
        }

        return [self::$result, 'captured driver log'];
    }

    /** Replay a stage marker exactly as the streamed driver output would deliver it. */
    public function emitStage(PaymentAutomationAttempt $attempt, string $stage): void
    {
        $this->absorbStageMarkers($attempt, "<<<STAGE>>>{$stage}<<</STAGE>>>");
    }
}

function walletLockAttempt(string $phone, string $wallet, ?Carbon\CarbonInterface $createdAt = null): PaymentAutomationAttempt
{
    $account = Account::factory()->create([
        'phone' => $phone,
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => $wallet,
        'auto_payment_pin' => '4321',
    ]);

    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-'.$phone,
        'gateway_page_url' => 'https://checkout.dgepay.net/check-out/'.$phone,
        'callback_url' => null,
        'is_fake' => false,
        'created_at' => $createdAt ?? now(),
    ]);

    return PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'rocket',
        'status' => PaymentAutomationAttempt::STATUS_RUNNING,
        'attempts' => 1,
    ]);
}

beforeEach(function () {
    Cache::flush();
    WalletLockCapturingService::$driverCalls = 0;
    WalletLockCapturingService::$result = ['ok' => false, 'stage' => 'confirm', 'error' => 'stopped'];
    WalletLockCapturingService::$duringDriver = null;
    WalletLockCapturingService::$throwFromDriver = false;
});

it('collapses every spelling of one handset onto a single lock key', function () {
    $key = PaymentWalletLock::key('01865144147');

    // The 12-digit Rocket account is the mobile plus a check digit — same SIM, same SMS stream.
    expect(PaymentWalletLock::key('018651441477'))->toBe($key)
        ->and(PaymentWalletLock::key('018651441471'))->toBe($key)
        ->and(PaymentWalletLock::key('8801865144147'))->toBe($key)
        ->and(PaymentWalletLock::key('+880 1865-144147'))->toBe($key)
        ->and(PaymentWalletLock::key('013030409605'))->not->toBe($key);
});

it('offers both the stored and the SMS spelling when looking an OTP up', function () {
    // The account stores 12 digits; the forwarder posts the 11-digit SIM that received the message.
    expect(PaymentWalletLock::candidatePhones('018651441477'))
        ->toContain('01865144147')
        ->toContain('018651441477');
});

it('defers a second run on the same wallet without ever opening the checkout', function () {
    Queue::fake();

    $held = Cache::lock(PaymentWalletLock::key('018651441477'), 60);
    expect($held->get())->toBeTrue();

    $attempt = walletLockAttempt('01700000041', '018651441477');
    app(WalletLockCapturingService::class)->run($attempt);

    // Never launching Chrome is the point: a killed run would leave dg-epay serving SESSION ACTIVE
    // ELSEWHERE for that link, which is terminal.
    expect(WalletLockCapturingService::$driverCalls)->toBe(0)
        ->and($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_PENDING)
        ->and($attempt->stage)->toBe('wallet_busy')
        // Contention is not a failure, so the retry is refunded.
        ->and($attempt->attempts)->toBe(0)
        ->and($attempt->last_error)->toContain('busy')
        // No wallet digits: last_error is rendered verbatim on a page that masks the wallet.
        ->and($attempt->last_error)->not->toContain('018651441477');

    Queue::assertPushed(AutoPaymentJob::class);
});

it('lets two different wallets run at the same time', function () {
    $first = Cache::lock(PaymentWalletLock::key('018651441477'), 60);
    expect($first->get())->toBeTrue();

    $attempt = walletLockAttempt('01700000042', '013030409605');
    app(WalletLockCapturingService::class)->run($attempt);

    expect(WalletLockCapturingService::$driverCalls)->toBe(1);
});

it('hands the wallet over as soon as its own OTP is consumed', function () {
    $wallet = '018651441477';
    $freeMidRun = null;

    WalletLockCapturingService::$duringDriver = function (
        WalletLockCapturingService $service,
        PaymentAutomationAttempt $attempt,
    ) use ($wallet, &$freeMidRun): void {
        expect(Cache::lock(PaymentWalletLock::key($wallet), 5)->get())->toBeFalse();

        // The driver reaches this stage only after its own code has been read and consumed.
        $service->emitStage($attempt, 'otp');

        // Still mid-run (PIN and confirm are yet to come) but the wallet is already free.
        $freeMidRun = Cache::lock(PaymentWalletLock::key($wallet), 5)->get();
    };

    app(WalletLockCapturingService::class)->run(walletLockAttempt('01700000043', $wallet));

    expect($freeMidRun)->toBeTrue();
});

it('does not release the wallet twice when the run ends after an early release', function () {
    $wallet = '018651441477';

    WalletLockCapturingService::$duringDriver = function (
        WalletLockCapturingService $service,
        PaymentAutomationAttempt $attempt,
    ): void {
        $service->emitStage($attempt, 'otp');
    };

    app(WalletLockCapturingService::class)->run(walletLockAttempt('01700000044', $wallet));

    // A second holder took the wallet after the early release; the finishing run must not have
    // stolen it back on its way out.
    $next = Cache::lock(PaymentWalletLock::key($wallet), 60);
    expect($next->get())->toBeTrue()
        ->and(Cache::lock(PaymentWalletLock::key($wallet), 5)->get())->toBeFalse();
});

it('frees the wallet when the driver throws', function () {
    WalletLockCapturingService::$throwFromDriver = true;

    $attempt = walletLockAttempt('01700000045', '018651441477');
    app(WalletLockCapturingService::class)->run($attempt);

    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and(Cache::lock(PaymentWalletLock::key('018651441477'), 5)->get())->toBeTrue();
});

it('stops requeueing a blocked run once the checkout window is nearly gone', function () {
    Queue::fake();

    $held = Cache::lock(PaymentWalletLock::key('018651441477'), 60);
    $held->get();

    // Four and a half minutes into a five-minute window: not enough left to pay.
    $attempt = walletLockAttempt('01700000046', '018651441477', now()->subSeconds(280));
    app(WalletLockCapturingService::class)->run($attempt);

    expect($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('wallet_busy')
        ->and($attempt->last_error)->toContain('No usable time left');

    Queue::assertNotPushed(AutoPaymentJob::class);
});

it('drains leftover wallet OTPs at release but leaves booking codes alone', function () {
    $wallet = '018651441477';

    $leftover = OtpCode::create([
        'phone' => '01865144147',
        'otp_code' => '123456',
        'message' => 'Rocket OTP 123456',
        'is_ivacbd' => false,
        'is_mfs' => true,
        'fetched_at' => now(),
    ]);

    // Same SIM, but an IVAC booking code — the sign-in flow owns this one.
    $booking = OtpCode::create([
        'phone' => '01865144147',
        'otp_code' => '654321',
        'message' => 'IVAC OTP 654321',
        'is_ivacbd' => true,
        'is_mfs' => false,
        'fetched_at' => now(),
    ]);

    app(WalletLockCapturingService::class)->run(walletLockAttempt('01700000047', $wallet));

    expect($leftover->refresh()->consumed_at)->not->toBeNull()
        ->and($booking->refresh()->consumed_at)->toBeNull();
});
