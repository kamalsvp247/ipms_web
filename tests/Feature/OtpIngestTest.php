<?php

use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ingests ivacbd word-form otp and extracts digits', function () {
    $msg = '(IVACBD) For security, type the following sequence when prompted Six-Three-Four-Eight-Zero-Seven .';

    $this->get('/otp?phone=01352511773&msg='.urlencode($msg))
        ->assertOk()
        ->assertJson([
            'phone' => '01352511773',
            'otp_code' => '634807',
            'is_ivacbd' => true,
        ]);

    expect(OtpCode::count())->toBe(1);
    $row = OtpCode::first();
    expect($row->message)->toBe($msg);
    expect($row->is_ivacbd)->toBeTrue();
    expect($row->fetched_at)->not->toBeNull();
});

test('ingests ivacbd security-sequence message without (IVACBD) prefix', function () {
    $msg = 'For security, type the following sequence when prompted: Three-One-Four-One-Five-Nine .';

    $this->get('/otp?phone=01352511773&msg='.urlencode($msg))
        ->assertOk()
        ->assertJson([
            'phone' => '01352511773',
            'otp_code' => '314159',
            'is_ivacbd' => true,
        ]);
});

test('ingests ivacbd message prefixed with From : IVAC_BD header', function () {
    $msg = "From : IVAC_BD\n(IVACBD) For security, type the following sequence when prompted Four-Nine-Eight-One-Zero-Zero .";

    $this->get('/otp?phone=01744118006&msg='.urlencode($msg))
        ->assertOk()
        ->assertJson([
            'phone' => '01744118006',
            'otp_code' => '498100',
            'is_ivacbd' => true,
        ]);
});

test('ingests plain-digit ivac registration otp', function () {
    $msg = '511669 is your One-Time Password for IVAC registration.';

    $this->get('/otp?phone=01725774042&msg='.urlencode($msg))
        ->assertOk()
        ->assertJson([
            'phone' => '01725774042',
            'otp_code' => '511669',
            'is_ivacbd' => true,
        ]);
});

test('stores non-ivacbd message with null otp and flag false', function () {
    $msg = 'Hello, your bank balance is 5000 BDT';

    $this->get('/otp?phone=01640138206&msg='.urlencode($msg))
        ->assertOk()
        ->assertJson([
            'is_ivacbd' => false,
            'otp_code' => null,
        ]);

    $row = OtpCode::first();
    expect($row->otp_code)->toBeNull();
    expect($row->is_ivacbd)->toBeFalse();
});

test('one phone can store multiple otps', function () {
    $msg1 = '(IVACBD) code Six-Three-Four-Eight-Zero-Seven';
    $msg2 = '(IVACBD) code One-Two-Three-Four-Five-Six';

    $this->get('/otp?phone=01352511773&msg='.urlencode($msg1))->assertOk();
    $this->get('/otp?phone=01352511773&msg='.urlencode($msg2))->assertOk();

    expect(OtpCode::where('phone', '01352511773')->count())->toBe(2);
    expect(OtpCode::pluck('otp_code')->all())->toBe(['634807', '123456']);
});

test('rejects missing phone or msg', function () {
    $this->get('/otp?phone=01352511773')->assertStatus(302);
    $this->get('/otp?msg=hi')->assertStatus(302);
});

test('ingests otp from a json post body', function () {
    $msg = '(IVACBD) For security, type the following sequence when prompted Six-Three-Four-Eight-Zero-Seven .';

    $this->postJson('/otp', ['phone' => '01352511773', 'msg' => $msg])
        ->assertOk()
        ->assertJson([
            'phone' => '01352511773',
            'otp_code' => '634807',
            'is_ivacbd' => true,
        ]);

    expect(OtpCode::count())->toBe(1);
});

test('ingests otp from a form-encoded post body', function () {
    $msg = '511669 is your One-Time Password for IVAC registration.';

    $this->post('/otp', ['phone' => '01725774042', 'msg' => $msg])
        ->assertOk()
        ->assertJson([
            'phone' => '01725774042',
            'otp_code' => '511669',
            'is_ivacbd' => true,
        ]);
});

test('json post rejects missing phone or msg with 422', function () {
    $this->postJson('/otp', ['phone' => '01352511773'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('msg');

    $this->postJson('/otp', ['msg' => 'hi'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

test('consumeForPhone skips rows with null otp_code', function () {
    OtpCode::create([
        'phone' => '01640138206',
        'otp_code' => null,
        'message' => 'random sms',
        'is_ivacbd' => false,
        'fetched_at' => now(),
    ]);
    OtpCode::create([
        'phone' => '01640138206',
        'otp_code' => '111222',
        'message' => '(IVACBD) code',
        'is_ivacbd' => true,
        'fetched_at' => now()->subSecond(),
    ]);

    $consumed = OtpCode::consumeForPhone('01640138206');

    expect($consumed)->not->toBeNull();
    expect($consumed->otp_code)->toBe('111222');
});
