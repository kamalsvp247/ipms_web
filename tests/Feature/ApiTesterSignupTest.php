<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ApiTesterController;
use Tests\TestCase;

class ApiTesterSignupTest extends TestCase
{
    public function test_signup_otp_sends_one_identifier_with_the_signup_channel_name(): void
    {
        $this->assertSame(
            ['phone' => '01711111111', 'otpChannel' => 'PHONE'],
            ApiTesterController::buildSignupOtpBody('phone', '01711111111', 'a@b.com'),
        );

        $this->assertSame(
            ['email' => 'a@b.com', 'otpChannel' => 'EMAIL'],
            ApiTesterController::buildSignupOtpBody('email', '01711111111', 'a@b.com'),
        );
    }

    public function test_signup_verify_otp_uses_code_field_and_matching_identifier(): void
    {
        $this->assertSame(
            ['requestId' => 'req-1', 'phone' => '01711111111', 'code' => '123456', 'otpChannel' => 'PHONE'],
            ApiTesterController::buildSignupVerifyOtpBody('phone', '01711111111', 'a@b.com', 'req-1', '123456'),
        );

        $this->assertSame(
            ['requestId' => 'req-2', 'email' => 'a@b.com', 'code' => '654321', 'otpChannel' => 'EMAIL'],
            ApiTesterController::buildSignupVerifyOtpBody('email', '01711111111', 'a@b.com', 'req-2', '654321'),
        );
    }

    public function test_signup_body_matches_the_bundle_key_names(): void
    {
        $body = ApiTesterController::buildSignupBody([
            'phone' => '01711111111',
            'email' => 'a@b.com',
            'given_name' => 'Rahim',
            'surname' => 'Uddin',
            'dob' => '1995-04-12',
            'nid' => '1234567890',
            'passport' => 'A01234567',
            'password' => 'Secret123!',
        ]);

        $this->assertSame([
            'phone' => '01711111111',
            'email' => 'a@b.com',
            'nid' => '1234567890',
            'passport' => 'A01234567',
            'givenName' => 'Rahim',
            'surName' => 'Uddin',
            'dob' => '1995-04-12',
            'password' => 'Secret123!',
        ], $body);
    }

    public function test_blank_nid_is_sent_as_null_not_an_empty_string(): void
    {
        $body = ApiTesterController::buildSignupBody([
            'phone' => '01711111111',
            'email' => 'a@b.com',
            'given_name' => 'Rahim',
            'surname' => 'Uddin',
            'dob' => '1995-04-12',
            'nid' => '   ',
            'passport' => null,
            'password' => 'Secret123!',
        ]);

        $this->assertNull($body['nid']);
        $this->assertSame('', $body['passport']);
    }
}
