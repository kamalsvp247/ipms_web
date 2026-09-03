<?php

use App\Models\OtpCode;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function makeOtpForCount(string $phone, ?string $code, bool $isIvacbd, $fetchedAt = null): OtpCode
{
    return OtpCode::create([
        'phone' => $phone,
        'otp_code' => $code,
        'message' => "OTP: {$code}",
        'is_ivacbd' => $isIvacbd,
        'fetched_at' => $fetchedAt ?? now('Asia/Dhaka'),
    ]);
}

test('count only includes ivacbd rows with a 6-digit otp code for today', function () {
    makeOtpForCount('01711111111', '123456', true);
    makeOtpForCount('01711111111', '654321', true);
    makeOtpForCount('01711111111', '99999', true); // 5 digits — excluded
    makeOtpForCount('01711111111', '1234567', true); // 7 digits — excluded
    makeOtpForCount('01711111111', '111222', false); // not ivacbd — excluded
    makeOtpForCount('01722222222', '222222', true, now('Asia/Dhaka')->subDay()); // wrong date — excluded

    $response = $this->getJson('/api/otps/count');

    $response->assertOk()->assertJson(['01711111111' => 2]);
    expect($response->json())->not->toHaveKey('01722222222');
});
