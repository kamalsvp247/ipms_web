<?php

use App\Models\OtpCode;
use App\Support\MfsOtpParser;
use App\Support\PaymentOtpTicket;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('extracts the code from wallet OTP messages', function (string $message, string $expected, string $provider) {
    expect(MfsOtpParser::isMfs($message))->toBeTrue()
        ->and(MfsOtpParser::provider($message))->toBe($provider)
        ->and(MfsOtpParser::extractOtp($message))->toBe($expected);
})->with([
    ['Your Nagad OTP is 775293. Do not share it with anyone.', '775293', 'nagad'],
    ['123456 is your bKash verification code. Never share.', '123456', 'bkash'],
    ['Dear customer, your Rocket one-time password is 922301.', '922301', 'rocket'],
    ['DBBL: Your OTP for the transaction is 445566', '445566', 'rocket'],
    // Verbatim production formats. The Nagad one is the trap: its safety preamble says "OTP"
    // before the real code, and the tail carries a 5-digit helpline that a looser match would grab.
    [
        "NEVER share your OTP or PIN with anyone. Nagad will never ask for these.\nYour OTP for Nagad ECOM payment is 790204.\nValidity: 2 minutes.\nHelpline: 16167",
        '790204',
        'nagad',
    ],
    ['Your security code for Rocket transaction is 102408.', '102408', 'rocket'],
    // Verbatim live bKash format. Two traps at once: the safety preamble puts an "OTP" keyword
    // nowhere near the code, and a money amount sits between the real keyword and the digits — so
    // a pattern that anchors on the first keyword, or that treats any digit as a hard stop, finds
    // nothing at all. Every MFS row ingested before this was fixed has a null otp_code.
    [
        'Do NOT share your OTP or PIN with anyone. Your bKash OTP for PAYMENT of Tk.5,780.00 to bKash_ACS is 894251. Expires in 2 min.',
        '894251',
        'bkash',
    ],
    [
        'Do NOT share your OTP or PIN with anyone. Your bKash OTP for PAYMENT of Tk.121.20 to bKash_ACS is 794757. Expires in 2 min.',
        '794757',
        'bkash',
    ],
]);

it('finds a wallet OTP that arrived on the SIM behind a 12-digit Rocket account', function () {
    // The account stores the Rocket account number; the forwarder posts the 11-digit SIM that
    // received the SMS. Matching only the stored spelling never found a real provider message.
    OtpCode::create([
        'phone' => '01865144147',
        'otp_code' => '551234',
        'message' => 'Your Rocket OTP is 551234',
        'is_ivacbd' => false,
        'is_mfs' => true,
        'fetched_at' => now(),
    ]);

    $ticket = PaymentOtpTicket::issue('018651441477', 60);

    $this->getJson('/api/payment-otp/018651441477', ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', '551234');
});

it('will not serve a wallet OTP that predates the run asking for it', function () {
    OtpCode::create([
        'phone' => '01712345678',
        'otp_code' => '775293',
        'message' => 'Your Nagad OTP is 775293',
        'is_ivacbd' => false,
        'is_mfs' => true,
        'fetched_at' => now()->subSeconds(5),
    ]);

    $ticket = PaymentOtpTicket::issue('01712345678', 60);
    $since = now()->getTimestampMs();

    // No backward tolerance on the payment side: fetched_at is written by this same host, so a
    // window would only ever let a previous run's leftover code through as this run's.
    $this->getJson("/api/payment-otp/01712345678?since={$since}", ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', null);
});

it('does not classify an IVAC booking OTP as a payment OTP', function () {
    // The guard that keeps the booking flow's OTP stream intact.
    $message = '(IVACBD) Your One-Time Password for IVAC is 654321';

    expect(MfsOtpParser::isMfs($message))->toBeFalse();
});

it('ignores a wallet message that carries no code', function () {
    expect(MfsOtpParser::isMfs('You have received Tk 500.00 from bKash. Balance Tk 1,200.00'))->toBeFalse();
});

it('stores an ingested wallet OTP as MFS with its code parsed', function () {
    $this->postJson('/otp', [
        'phone' => '01712345678',
        'msg' => 'Your Nagad OTP is 775293. Do not share it.',
    ])->assertOk()->assertJsonPath('is_mfs', true)->assertJsonPath('otp_code', '775293');

    $row = OtpCode::firstOrFail();
    expect($row->is_mfs)->toBeTrue()->and($row->is_ivacbd)->toBeFalse();
});

it('still routes an IVAC OTP to the booking stream', function () {
    $this->postJson('/otp', [
        'phone' => '01712345678',
        'msg' => '(IVACBD) Your One-Time Password for IVAC is 654321',
    ])->assertOk()->assertJsonPath('is_ivacbd', true)->assertJsonPath('is_mfs', false);
});

it('serves a wallet OTP to a driver holding a valid ticket', function () {
    OtpCode::create([
        'phone' => '01712345678',
        'otp_code' => '775293',
        'message' => 'Your Nagad OTP is 775293',
        'is_ivacbd' => false,
        'is_mfs' => true,
        'fetched_at' => now(),
    ]);

    $ticket = PaymentOtpTicket::issue('01712345678', 60);

    $this->getJson('/api/payment-otp/01712345678', ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', '775293');

    // Consumed, so a second read cannot replay it.
    $this->getJson('/api/payment-otp/01712345678', ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', null);
});

it('refuses a ticket issued for a different wallet', function () {
    $ticket = PaymentOtpTicket::issue('01711111111', 60);

    $this->getJson('/api/payment-otp/01722222222', ['Authorization' => "Bearer {$ticket}"])
        ->assertStatus(403);
});

it('refuses a missing or unknown ticket', function () {
    $this->getJson('/api/payment-otp/01712345678')->assertStatus(401);
    $this->getJson('/api/payment-otp/01712345678', ['Authorization' => 'Bearer nope'])->assertStatus(401);
});

it('does not hand an IVAC booking OTP to the payment driver', function () {
    OtpCode::create([
        'phone' => '01712345678',
        'otp_code' => '654321',
        'message' => '(IVACBD) One-Time Password for IVAC',
        'is_ivacbd' => true,
        'is_mfs' => false,
        'fetched_at' => now(),
    ]);

    $ticket = PaymentOtpTicket::issue('01712345678', 60);

    $this->getJson('/api/payment-otp/01712345678', ['Authorization' => "Bearer {$ticket}"])
        ->assertOk()
        ->assertJsonPath('otp_code', null);
});
