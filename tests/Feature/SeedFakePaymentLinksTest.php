<?php

use App\Models\PaymentLink;

use function Pest\Laravel\artisan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('doubles each dated set of links with fake declined copies', function () {
    // Two dates: 3 links on one, 1 on the other.
    PaymentLink::factory()->count(3)->create(['account_phone' => '111', 'review_status' => 'unread', 'created_at' => '2026-07-16 10:00:00']);
    PaymentLink::factory()->count(1)->create(['account_phone' => '222', 'review_status' => 'succeeded', 'created_at' => '2026-07-23 10:00:00']);

    artisan('payment-links:seed-fakes')->assertExitCode(0);

    // Each date is doubled.
    expect(PaymentLink::whereDate('created_at', '2026-07-16')->count())->toBe(6);
    expect(PaymentLink::whereDate('created_at', '2026-07-23')->count())->toBe(2);

    // Fakes are all declined + flagged, real links untouched.
    $fakes = PaymentLink::where('is_fake', true)->get();
    expect($fakes)->toHaveCount(4);
    expect($fakes->every(fn ($l) => $l->review_status === 'declined'))->toBeTrue();
    expect(PaymentLink::where('is_fake', false)->count())->toBe(4);

    // A fake stays on its source's date/phone so it groups together in the UI.
    $fake16 = PaymentLink::where('is_fake', true)->whereDate('created_at', '2026-07-16')->first();
    expect($fake16->account_phone)->toBe('111');

    // Each fake gets its own distinct checkout URL (not a copy of a real link's URL).
    $realUrls = PaymentLink::where('is_fake', false)->pluck('gateway_page_url')->all();
    foreach ($fakes as $fake) {
        expect($fake->gateway_page_url)->not->toBeNull()
            ->and($realUrls)->not->toContain($fake->gateway_page_url);
    }
    expect($fakes->pluck('gateway_page_url')->unique())->toHaveCount($fakes->count());
});

it('mints fake checkout URLs in the same gateway format as their source link', function () {
    $dgePay = PaymentLink::factory()->create([
        'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data='.base64_encode(random_bytes(320)),
        'redirect_gateway_url' => null,
        'account_phone' => '01700000001',
        'created_at' => '2026-07-16 10:00:00',
    ]);
    $sslCommerz = PaymentLink::factory()->create([
        'gateway_page_url' => 'https://pay.sslcommerz.com/'.bin2hex(random_bytes(20)),
        'redirect_gateway_url' => 'https://securepay.sslcommerz.com/gwprocess/v4/gw.php?Q=REDIRECT&SESSIONKEY=ABC&cardname=',
        'account_phone' => '01700000002',
        'created_at' => '2026-07-16 10:00:00',
    ]);

    artisan('payment-links:seed-fakes')->assertExitCode(0);

    $fakeDgePay = PaymentLink::where('is_fake', true)->where('account_phone', $dgePay->account_phone)->firstOrFail();
    $fakeSsl = PaymentLink::where('is_fake', true)->where('account_phone', $sslCommerz->account_phone)->firstOrFail();

    // The dg-epay clone keeps the real checkout shape: host, shared ciphertext prefix, blob length.
    expect($fakeDgePay->gateway_page_url)->toStartWith('https://checkout.dgepay.net/payment/payment-methods?data=ngtso1MSQhZi2D6q4pBZz')
        ->and($fakeDgePay->gateway_page_url)->not->toBe($dgePay->gateway_page_url)
        ->and($fakeDgePay->redirect_gateway_url)->toBeNull();

    $blob = str($fakeDgePay->gateway_page_url)->after('?data=')->toString();
    expect(strlen($blob))->toBe(strlen(str($dgePay->gateway_page_url)->after('?data=')->toString()))
        ->and($blob)->toEndWith('=')
        ->and(base64_decode($blob, true))->not->toBeFalse();

    // A legacy SSLCommerz link still clones to an SSLCommerz-shaped pair.
    expect($fakeSsl->gateway_page_url)->toStartWith('https://pay.sslcommerz.com/')
        ->and($fakeSsl->redirect_gateway_url)->toStartWith('https://securepay.sslcommerz.com/gwprocess/v4/gw.php?Q=REDIRECT&SESSIONKEY=');
});

it('refuses to reseed while fakes exist and clears them on --clear', function () {
    PaymentLink::factory()->count(2)->create(['created_at' => '2026-07-16 10:00:00']);

    artisan('payment-links:seed-fakes')->assertExitCode(0);
    expect(PaymentLink::where('is_fake', true)->count())->toBe(2);

    // Second seed is refused (no duplicate fakes).
    artisan('payment-links:seed-fakes')->assertExitCode(1);
    expect(PaymentLink::where('is_fake', true)->count())->toBe(2);

    // --clear removes only the fakes.
    artisan('payment-links:seed-fakes', ['--clear' => true])->assertExitCode(0);
    expect(PaymentLink::where('is_fake', true)->count())->toBe(0);
    expect(PaymentLink::where('is_fake', false)->count())->toBe(2);
});
