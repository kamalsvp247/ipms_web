<?php

use App\Models\PaymentLink;
use App\Services\Payment\PaymentCallbackRouter;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function callbackUrlFor(string $tranId): string
{
    return "https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id={$tranId}&data=blob";
}

it('matches a callback URL to its link and marks it pending for the bot', function () {
    $link = PaymentLink::factory()->create(['reservation_id' => 'res-abc', 'callback_url' => null]);

    $routed = app(PaymentCallbackRouter::class)->route(callbackUrlFor('res-abc'));

    expect($routed['result'])->toBe(PaymentCallbackRouter::RESULT_OK);

    $link->refresh();
    expect($link->callback_url)->toBe(callbackUrlFor('res-abc'))
        ->and($link->callback_status)->toBe('pending')
        ->and($link->callback_submitted_at)->not->toBeNull()
        ->and($link->callback_completed_at)->toBeNull();
});

it('reports a URL with no tran_id', function () {
    $routed = app(PaymentCallbackRouter::class)->route('https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback');

    expect($routed['result'])->toBe(PaymentCallbackRouter::RESULT_NO_TRAN_ID);
});

it('reports a tran_id that matches no link', function () {
    $routed = app(PaymentCallbackRouter::class)->route(callbackUrlFor('unknown'));

    expect($routed['result'])->toBe(PaymentCallbackRouter::RESULT_NOT_FOUND)
        ->and($routed['tran_id'])->toBe('unknown');
});

it('restores a missing scheme so the bot can build a request from it', function () {
    // A hand-pasted, scheme-less URL used to be stored verbatim and left the account polling
    // forever, because OkHttp cannot parse it.
    $link = PaymentLink::factory()->create(['reservation_id' => 'res-bare', 'callback_url' => null]);

    app(PaymentCallbackRouter::class)->route('api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-bare');

    expect($link->refresh()->callback_url)->toStartWith('https://api.ivacbd.com/');
});

it('still serves the public ingest endpoint the extension posts to', function () {
    $link = PaymentLink::factory()->create(['reservation_id' => 'res-ext', 'callback_url' => null]);

    $this->postJson('/api/payment-links/redirect-url', ['url' => callbackUrlFor('res-ext')])
        ->assertOk()
        ->assertJsonPath('reservation_id', 'res-ext')
        ->assertJsonPath('callback_status', 'pending');

    expect($link->refresh()->callback_status)->toBe('pending');
});

it('returns 422 and 404 from the public endpoint as before', function () {
    $this->postJson('/api/payment-links/redirect-url', ['url' => 'https://example.com/no-tran'])
        ->assertStatus(422);

    $this->postJson('/api/payment-links/redirect-url', ['url' => callbackUrlFor('nope')])
        ->assertStatus(404);
});

it('lets the bot poll the callback it just stored', function () {
    PaymentLink::factory()->create(['reservation_id' => 'res-poll', 'callback_url' => null]);

    $this->postJson('/api/payment-links/redirect-url', ['url' => callbackUrlFor('res-poll')])->assertOk();

    $this->getJson('/api/payment-callback?reservation_id=res-poll')
        ->assertOk()
        ->assertJsonPath('callback_status', 'pending')
        ->assertJsonPath('callback_url', callbackUrlFor('res-poll'));
});
