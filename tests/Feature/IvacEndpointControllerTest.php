<?php

use App\Models\Setting;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function endpointAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

it('returns every endpoint merged over the defaults with metadata', function () {
    // Store only a partial override; the response must still carry every known key.
    Setting::instance()->update(['ivac_endpoints' => ['signin' => '/auth/v99-sign-in']]);

    $response = actingAs(endpointAdmin())->getJson('/api/ivac-endpoints')->assertOk();

    $defaults = Setting::defaultIvacEndpoints();
    foreach (array_keys($defaults) as $key) {
        $response->assertJsonPath("endpoints.{$key}", fn ($v) => is_string($v) && $v !== '');
    }
    $response->assertJsonPath('endpoints.signin', '/auth/v99-sign-in');
    $response->assertJsonPath('endpoints.uploadFile', $defaults['uploadFile']);
    $response->assertJsonPath('meta.signin.sync', 'auto');
    $response->assertJsonPath('meta.sendOtp.sync', 'manual');
});

it('persists valid endpoint overrides', function () {
    $payload = Setting::defaultIvacEndpoints();
    $payload['signin'] = '/auth/v42-sign-in';
    $payload['uploadFile'] = '/file/upload_file_v42';

    actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', ['endpoints' => $payload])
        ->assertOk()
        ->assertJsonPath('endpoints.signin', '/auth/v42-sign-in')
        ->assertJsonPath('endpoints.uploadFile', '/file/upload_file_v42');

    expect(Setting::instance()->fresh()->ivac_endpoints['signin'])->toBe('/auth/v42-sign-in');
});

it('rejects a path that does not start with a slash', function () {
    $payload = Setting::defaultIvacEndpoints();
    $payload['signin'] = 'auth/v42-sign-in';

    $errors = actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', ['endpoints' => $payload])
        ->assertStatus(422)
        ->json('errors');

    expect($errors)->toHaveKey('endpoints.signin');
    expect($errors['endpoints.signin'][0])->toContain('/');
    // A rejected value must not be persisted.
    expect(Setting::instance()->fresh()->ivac_endpoints['signin'] ?? null)->not->toBe('auth/v42-sign-in');
});

it('rejects a path missing its stable anchor', function () {
    $payload = Setting::defaultIvacEndpoints();
    $payload['verifyOtp'] = '/otp/somethingElse';

    $errors = actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', ['endpoints' => $payload])
        ->assertStatus(422)
        ->json('errors');

    expect($errors)->toHaveKey('endpoints.verifyOtp');
    expect($errors['endpoints.verifyOtp'][0])->toContain('verifySigninOtp');
});

it('returns the request constants with their metadata', function () {
    Setting::instance()->update([
        'reserve_slot_id' => '54ea9f13-f1e2-4cea-9e08-f525e8242ccf',
        'payment_config_id' => 'dcd59a95-d55e-41ed-b57c-60416e01617e',
    ]);

    actingAs(endpointAdmin())->getJson('/api/ivac-endpoints')->assertOk()
        ->assertJsonPath('constants.reserveSlotId', '54ea9f13-f1e2-4cea-9e08-f525e8242ccf')
        ->assertJsonPath('constants.paymentConfigId', 'dcd59a95-d55e-41ed-b57c-60416e01617e')
        ->assertJsonPath('constantsMeta.reserveSlotId.template', 'reserveSlot')
        ->assertJsonPath('constantsMeta.paymentConfigId.template', 'payment');
});

it('persists the request constants alongside the endpoints', function () {
    actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', [
        'endpoints' => Setting::defaultIvacEndpoints(),
        'constants' => [
            'reserveSlotId' => '  11111111-2222-3333-4444-555555555555  ',
            'paymentConfigId' => '66666666-7777-8888-9999-000000000000',
        ],
    ])->assertOk()->assertJsonPath('constants.reserveSlotId', '11111111-2222-3333-4444-555555555555');

    $setting = Setting::instance()->fresh();
    expect($setting->reserve_slot_id)->toBe('11111111-2222-3333-4444-555555555555');
    expect($setting->payment_config_id)->toBe('66666666-7777-8888-9999-000000000000');
});

it('skips a blank constant instead of storing an empty id', function () {
    // An empty reserve_slot_id would build "/slots//reserve-slot" — keep the last good value.
    Setting::instance()->update(['reserve_slot_id' => 'keep-me']);

    actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', [
        'endpoints' => Setting::defaultIvacEndpoints(),
        'constants' => ['reserveSlotId' => '', 'paymentConfigId' => '  '],
    ])->assertOk()->assertJsonPath('constants.reserveSlotId', 'keep-me');

    expect(Setting::instance()->fresh()->reserve_slot_id)->toBe('keep-me');
});

it('saves endpoints when no constants are sent at all', function () {
    Setting::instance()->update(['reserve_slot_id' => 'untouched']);

    $payload = Setting::defaultIvacEndpoints();
    $payload['signin'] = '/auth/v43-sign-in';

    actingAs(endpointAdmin())->postJson('/api/ivac-endpoints', ['endpoints' => $payload])
        ->assertOk()
        ->assertJsonPath('endpoints.signin', '/auth/v43-sign-in');

    expect(Setting::instance()->fresh()->reserve_slot_id)->toBe('untouched');
});

it('resets endpoints back to the compiled-in defaults', function () {
    Setting::instance()->update(['ivac_endpoints' => ['signin' => '/auth/v99-sign-in']]);

    actingAs(endpointAdmin())->postJson('/api/ivac-endpoints/reset')
        ->assertOk()
        ->assertJsonPath('endpoints.signin', Setting::defaultIvacEndpoints()['signin']);

    expect(Setting::instance()->fresh()->ivac_endpoints)->toEqual(Setting::defaultIvacEndpoints());
});

it('forbids a regular user', function () {
    $user = User::factory()->create(['role' => 'user']);

    actingAs($user)->getJson('/api/ivac-endpoints')->assertForbidden();
    actingAs($user)->postJson('/api/ivac-endpoints', ['endpoints' => []])->assertForbidden();
});
