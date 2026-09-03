<?php

use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Payment\AutoPaymentDispatcher;
use App\Services\Payment\PaymentAutomationService;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Counts driver invocations so a guard can be proven to stop a run before it spends anything. */
class PaidGuardCapturingService extends PaymentAutomationService
{
    public static int $driverCalls = 0;

    /**
     * @param  array<string, mixed>  $job
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function runDriver(PaymentAutomationAttempt $attempt, array $job): array
    {
        self::$driverCalls++;

        return [['ok' => false, 'stage' => 'confirm', 'error' => 'stopped'], ''];
    }
}

function paidGuardAccount(string $phone = '01700000051'): Account
{
    return Account::factory()->create([
        'phone' => $phone,
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '018651441477',
        'auto_payment_pin' => '4321',
    ]);
}

function paidGuardLink(Account $account, array $attributes = []): PaymentLink
{
    return PaymentLink::factory()->create(array_merge([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-'.uniqid(),
        'gateway_page_url' => 'https://checkout.dgepay.net/check-out/abc',
        'callback_url' => null,
        'is_fake' => false,
    ], $attributes));
}

beforeEach(function () {
    Cache::flush();
    PaidGuardCapturingService::$driverCalls = 0;
});

it('blocks a new link once a callback URL was captured for the account', function () {
    $account = paidGuardAccount();
    paidGuardLink($account, ['callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-old']);

    $fresh = paidGuardLink($account);

    expect($account->hasCompletedPayment())->toBeTrue()
        ->and(app(AutoPaymentDispatcher::class)->eligibleAttempt($fresh))->toBeNull()
        ->and(PaymentAutomationAttempt::count())->toBe(0);
});

it('blocks a new link once the bot confirmed the callback succeeded', function () {
    $account = paidGuardAccount('01700000052');
    // The bot reports success separately from the URL capture, so this half must stand alone.
    paidGuardLink($account, ['callback_url' => null, 'callback_status' => 'success']);

    expect($account->hasCompletedPayment())->toBeTrue()
        ->and(app(AutoPaymentDispatcher::class)->eligibleAttempt(paidGuardLink($account)))->toBeNull();
});

it('does not let a seeded fake link mark an account as paid', function () {
    $account = paidGuardAccount('01700000053');
    paidGuardLink($account, ['callback_url' => 'https://api.ivacbd.com/cb?tran_id=x', 'is_fake' => true]);

    expect($account->hasCompletedPayment())->toBeFalse()
        ->and(app(AutoPaymentDispatcher::class)->eligibleAttempt(paidGuardLink($account)))->not->toBeNull();
});

it('refuses to spend when the account paid between dispatch and execution', function () {
    $account = paidGuardAccount('01700000054');

    // An earlier link that has since been paid, so this run is blocked by the paid guard alone —
    // a newer paid link would trip the superseded guard first and prove nothing about this one.
    paidGuardLink($account, ['callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-other']);

    $link = paidGuardLink($account);

    $attempt = PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $account->id,
        'method' => 'rocket',
        'status' => PaymentAutomationAttempt::STATUS_RUNNING,
    ]);

    // A run deferred behind a busy wallet can execute minutes after it was queued.
    app(PaidGuardCapturingService::class)->run($attempt);

    expect(PaidGuardCapturingService::$driverCalls)->toBe(0)
        ->and($attempt->refresh()->status)->toBe(PaymentAutomationAttempt::STATUS_FAILED)
        ->and($attempt->stage)->toBe('already_paid');
});

it('re-arming clears the block for links created afterwards', function () {
    $account = paidGuardAccount('01700000055');
    paidGuardLink($account, [
        'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-old',
        'created_at' => now()->subDay(),
        'callback_submitted_at' => now()->subDay(),
    ]);

    expect($account->hasCompletedPayment())->toBeTrue();

    $account->rearmAutoPayment();

    expect($account->refresh()->hasCompletedPayment())->toBeFalse()
        ->and(app(AutoPaymentDispatcher::class)->eligibleAttempt(paidGuardLink($account)))->not->toBeNull();
});

it('re-blocks when a payment already in flight completes after the re-arm', function () {
    $account = paidGuardAccount('01700000056');
    // Created before the re-arm, so an age-only watermark would wrongly ignore it.
    $inFlight = paidGuardLink($account, ['created_at' => now()->subMinutes(2)]);

    $account->rearmAutoPayment();
    expect($account->refresh()->hasCompletedPayment())->toBeFalse();

    $inFlight->update([
        'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-inflight',
        'callback_submitted_at' => now()->addSecond(),
    ]);

    expect($account->refresh()->hasCompletedPayment())->toBeTrue();
});

it('exposes the paid state to the accounts list and re-arms in bulk', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $account = paidGuardAccount('01700000057');
    paidGuardLink($account, ['callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-old']);

    $this->actingAs($admin)
        ->getJson('/api/accounts')
        ->assertOk()
        ->assertJsonPath('data.0.auto_payment_paid', true);

    $this->actingAs($admin)
        ->putJson('/api/accounts/bulk-rearm-auto-payment', ['account_ids' => [$account->id]])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect($account->refresh()->auto_payment_rearmed_at)->not->toBeNull()
        ->and($account->hasCompletedPayment())->toBeFalse();
});

it('will not let an agent re-arm an account it cannot see', function () {
    $owner = User::factory()->create(['role' => 'agent']);
    $stranger = User::factory()->create(['role' => 'agent']);

    $account = paidGuardAccount('01700000058');
    $account->update(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->postJson("/api/accounts/{$account->id}/rearm-auto-payment")
        ->assertNotFound();

    expect($account->refresh()->auto_payment_rearmed_at)->toBeNull();
});
