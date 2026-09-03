<?php

use App\Models\AgentSlot;
use App\Models\Setting;

use function Pest\Laravel\getJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function endpointsSlot(): AgentSlot
{
    return AgentSlot::create([
        'name' => 'ep-slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
}

it('emits the stored IVAC endpoints object in /api/config', function () {
    $slot = endpointsSlot();
    $endpoints = [
        'signin' => '/auth/v23-sign-in',
        'sendOtp' => '/forgot-password/sendOtp',
        'verifyOtp' => '/otp/verifySigninOtp',
        'uploadFile' => '/file/upload_file_v23',
        'bookingConfig' => '/appointment/appointment-booking-config',
        'getBookingConfig' => '/appointment/get-booking-config',
        'reserveSlot' => '/slots/{reserveSlotId}/reserve-slot',
        'payment' => '/payment/{paymentConfigId}/dg-epay/initiate',
        'signinNavState' => '80d51dc5-af20-46fa-a7bb-e6a8f3f80065',
        'uploadRuntimeState' => 'v1.5a4c8831.9a53.47ed.b579.042a2c0cee5a',
    ];
    Setting::instance()->update(['ivac_endpoints' => $endpoints]);

    $response = getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        // order-independent containment (MySQL may normalize JSON key order)
        ->assertJson(['endpoints' => $endpoints]);

    // the two rotation-prone paths and the nav-state header must round-trip exactly
    $response->assertJsonPath('endpoints.signin', '/auth/v23-sign-in')
        ->assertJsonPath('endpoints.uploadFile', '/file/upload_file_v23')
        ->assertJsonPath('endpoints.signinNavState', '80d51dc5-af20-46fa-a7bb-e6a8f3f80065');
});

it('emits an empty endpoints object when settings has none (bot falls back to compiled-in defaults)', function () {
    $slot = endpointsSlot();
    Setting::instance()->update(['ivac_endpoints' => null]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('endpoints', []);
});
