<?php

use App\Models\Account;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function logPageLink(array $linkOverrides = [], array $accountOverrides = []): PaymentLink
{
    $account = Account::factory()->create(array_merge([
        'phone' => '01700000031',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01865144147',
        'auto_payment_pin' => '4321',
    ], $accountOverrides));

    return PaymentLink::factory()->create(array_merge([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-log',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => false,
    ], $linkOverrides));
}

it('renders the attempt, its driver log and the payer for a link that ran', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = logPageLink();

    PaymentAutomationAttempt::create([
        'payment_link_id' => $link->id,
        'account_id' => $link->account_id,
        'method' => 'nagad',
        'status' => PaymentAutomationAttempt::STATUS_FAILED,
        'attempts' => 2,
        'stage' => 'await_otp',
        'last_error' => 'Timed out waiting for the wallet OTP.',
        'driver_log' => "[payment-driver] opening checkout for method=nagad\n[payment-driver] stop",
        'started_at' => now()->subSeconds(30),
        'finished_at' => now(),
    ]);

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentLinks/AutomationLog')
            ->where('attempt.status', 'failed')
            ->where('attempt.stage', 'await_otp')
            ->where('attempt.attempts', 2)
            ->where('attempt.last_error', 'Timed out waiting for the wallet OTP.')
            ->where('account.auto_payment_method_label', 'Nagad')
            ->whereNot('attempt.driver_log', null)
            ->where('eligibility', null)
        );
});

it('masks the payer wallet rather than printing the full number', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = logPageLink();

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('account.auto_payment_wallet', '018****4147'));
});

it('explains why nothing ran when there is no attempt', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    // Auto payment off — the dispatcher would have skipped this link.
    $link = logPageLink([], ['auto_payment' => false, 'auto_payment_pin' => null]);

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attempt', null)
            ->where('eligibility.is_dgepay', true)
            ->where('eligibility.account_found', true)
            ->where('eligibility.auto_payment_on', false)
            ->where('eligibility.credentials_complete', false)
        );
});

it('flags a non-dgepay link as ineligible', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = logPageLink(['gateway_page_url' => 'https://pay.sslcommerz.com/abc']);

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('eligibility.is_dgepay', false));
});

it('denies an agent the log for another agent\'s link', function () {
    $owner = User::factory()->create(['role' => 'agent']);
    $stranger = User::factory()->create(['role' => 'agent']);

    $account = Account::factory()->create(['user_id' => $owner->id, 'phone' => '01700000041']);
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-private',
    ]);

    $this->actingAs($stranger)->get("/payment-links/{$link->id}/automation-log")->assertForbidden();
});

it('lets the owning agent read their own link log', function () {
    $owner = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'phone' => '01700000051']);
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-own',
    ]);

    $this->actingAs($owner)->get("/payment-links/{$link->id}/automation-log")->assertOk();
});

it('requires authentication', function () {
    $link = logPageLink();

    $this->get("/payment-links/{$link->id}/automation-log")->assertRedirect('/login');
});

it('names the paid block as the reason nothing ran', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));

    $link = logPageLink();
    PaymentLink::factory()->create([
        'account_id' => $link->account_id,
        'account_phone' => $link->account_phone,
        'reservation_id' => 'res-paid',
        'gateway_page_url' => 'https://checkout.dgepay.net/check-out/paid',
        'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-paid',
        'is_fake' => false,
    ]);

    $this->get("/payment-links/{$link->id}/automation-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attempt', null)
            ->where('eligibility.not_already_paid', false)
            ->where('eligibility.credentials_complete', true)
        );
});
