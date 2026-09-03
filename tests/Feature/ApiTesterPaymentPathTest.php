<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ApiTesterController;
use Tests\TestCase;

class ApiTesterPaymentPathTest extends TestCase
{
    public function test_dg_epay_embeds_payment_slot_uuid_from_bundle(): void
    {
        $this->assertSame(
            '/payment/f2a2fcd1-4019-4291-ba2c-ea94a60ea54f/dg-epay/initiate',
            ApiTesterController::buildPaymentInitiatePath('dg-epay', 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f'),
        );
    }

    public function test_dg_epay_without_uuid_falls_back_to_plain_path(): void
    {
        $this->assertSame(
            '/payment/dg-epay/initiate',
            ApiTesterController::buildPaymentInitiatePath('dg-epay', null),
        );

        $this->assertSame(
            '/payment/dg-epay/initiate',
            ApiTesterController::buildPaymentInitiatePath('dg-epay', '   '),
        );
    }

    public function test_ssl_never_embeds_a_uuid(): void
    {
        $this->assertSame(
            '/payment/ssl/initiate',
            ApiTesterController::buildPaymentInitiatePath('ssl', 'f2a2fcd1-4019-4291-ba2c-ea94a60ea54f'),
        );
    }

    public function test_uuid_is_url_encoded(): void
    {
        $this->assertSame(
            '/payment/a%2Fb/dg-epay/initiate',
            ApiTesterController::buildPaymentInitiatePath('dg-epay', 'a/b'),
        );
    }
}
