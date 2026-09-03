<?php

use App\Models\Account;
use App\Models\OtpCode;
use App\Models\PaymentLink;
use App\Models\User;
use App\Support\PaymentOtpTicket;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function manualOtpLink(array $accountOverrides = []): PaymentLink
{
    $account = Account::factory()->create(array_merge([
        'phone' => '01700000071',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01865144147',
        'auto_payment_pin' => '4321',
    ], $accountOverrides));

    return PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-manual',
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data=abc',
        'callback_url' => null,
        'is_fake' => false,
    ]);
}

it('injects a manual OTP into the channel the driver polls', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = manualOtpLink();

    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '775293'])
        ->assertRedirect();

    $row = OtpCode::firstOrFail();
    expect($row->phone)->toBe('01865144147')
        ->and($row->otp_code)->toBe('775293')
        ->and($row->is_mfs)->toBeTrue()
        ->and($row->is_ivacbd)->toBeFalse()
        ->and($row->consumed_at)->toBeNull();
});

it('serves the manual OTP to the driver through the normal ticket endpoint', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = manualOtpLink();

    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '112233']);

    // Exactly what the running driver does next.
    $ticket = PaymentOtpTicket::issue('01865144147', 60);
    $this->getJson('/api/payment-otp/01865144147', ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', '112233');
});

it('rejects a malformed code', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = manualOtpLink();

    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '12'])
        ->assertSessionHasErrors('otp');
    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => 'abcdef'])
        ->assertSessionHasErrors('otp');

    expect(OtpCode::count())->toBe(0);
});

it('refuses when the account has no wallet configured', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = manualOtpLink(['auto_payment' => false, 'auto_payment_wallet' => null, 'auto_payment_pin' => null]);

    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '775293'])
        ->assertSessionHasErrors('otp');

    expect(OtpCode::count())->toBe(0);
});

it('denies an agent submitting an OTP for another agent\'s link', function () {
    $owner = User::factory()->create(['role' => 'agent']);
    $stranger = User::factory()->create(['role' => 'agent']);

    $account = Account::factory()->create([
        'user_id' => $owner->id,
        'phone' => '01700000081',
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);
    $link = PaymentLink::factory()->create([
        'account_id' => $account->id,
        'account_phone' => $account->phone,
        'reservation_id' => 'res-private-otp',
    ]);

    $this->actingAs($stranger)
        ->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '775293'])
        ->assertForbidden();

    expect(OtpCode::count())->toBe(0);
});

it('does not let a payment OTP leak into the booking OTP stream', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    $link = manualOtpLink();

    $this->post("/payment-links/{$link->id}/automation-log/otp", ['otp' => '445566']);

    // The bot's sign-in poll must not see a payment code, even on a shared SIM — otherwise it
    // both fails its own verify and steals the code the payment driver is waiting for.
    expect(OtpCode::consumeForPhone('01865144147', 'sms'))->toBeNull();

    // ...and the payment driver still gets it.
    expect(OtpCode::consumeMfsForPhone('01865144147')?->otp_code)->toBe('445566');
});

it('still serves a genuine booking OTP on a SIM that also handles payments', function () {
    OtpCode::create([
        'phone' => '01865144147',
        'otp_code' => '999888',
        'message' => '(IVACBD) One-Time Password for IVAC',
        'is_ivacbd' => true,
        'is_mfs' => false,
        'fetched_at' => now(),
    ]);

    expect(OtpCode::consumeForPhone('01865144147', 'sms')?->otp_code)->toBe('999888');
});
