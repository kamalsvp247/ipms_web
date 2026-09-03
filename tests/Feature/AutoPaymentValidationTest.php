<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
});

/**
 * @return array<string, mixed>
 */
function accountPayload(array $overrides = []): array
{
    return array_merge([
        'phone' => '01700000001',
        'password' => 'secret-pass',
        'pdfs' => [['name' => 'a.pdf', 'base64' => 'Zm9v', 'is_primary' => true]],
    ], $overrides);
}

it('rejects enabling auto payment without the credential set', function () {
    $response = $this->postJson('/api/accounts', accountPayload([
        'auto_payment' => true,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['auto_payment_method', 'auto_payment_wallet', 'auto_payment_pin']);
});

it('rejects an unsupported payment method', function (string $method) {
    $response = $this->postJson('/api/accounts', accountPayload([
        'auto_payment' => true,
        'auto_payment_method' => $method,
        'auto_payment_wallet' => '012345678901',
        'auto_payment_pin' => '1234',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['auto_payment_method']);
})->with(['paypal', 'nagad', 'bkash']);

it('stores the credential set and encrypts the pin at rest', function () {
    $this->postJson('/api/accounts', accountPayload([
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '012345678901',
        'auto_payment_pin' => '4321',
    ]))->assertCreated();

    $account = Account::where('phone', '01700000001')->firstOrFail();

    expect($account->auto_payment)->toBeTrue()
        ->and($account->auto_payment_method)->toBe('rocket')
        ->and($account->auto_payment_wallet)->toBe('012345678901')
        // Accessor decrypts...
        ->and($account->auto_payment_pin)->toBe('4321');

    // ...but the column itself never holds the plaintext.
    $raw = DB::table('accounts')->where('id', $account->id)->value('auto_payment_pin');
    expect($raw)->not->toBe('4321')->and($raw)->not->toBeNull();
});

it('keeps the stored pin when an update sends an empty one', function () {
    $account = Account::factory()->create([
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);

    $this->putJson("/api/accounts/{$account->id}", [
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '012345678901',
        'auto_payment_pin' => '',
    ])->assertOk();

    $account->refresh();
    expect($account->auto_payment_method)->toBe('rocket')
        ->and($account->auto_payment_pin)->toBe('4321');
});

it('refuses to arm auto payment when no pin is stored or supplied', function () {
    $account = Account::factory()->create(['auto_payment' => false]);

    $this->putJson("/api/accounts/{$account->id}", [
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '012345678901',
        'auto_payment_pin' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['auto_payment_pin']);
});

it('never exposes the pin on the account list but serves it on show', function () {
    $account = Account::factory()->create([
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);

    $list = $this->getJson('/api/accounts')->assertOk()->json('data');
    expect(json_encode($list))->not->toContain('4321');

    $this->getJson("/api/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('auto_payment_pin', '4321');
});

it('reports an account as auto-payment ready only when fully credentialed', function () {
    $ready = Account::factory()->create([
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => '01712345678',
        'auto_payment_pin' => '4321',
    ]);
    $halfConfigured = Account::factory()->create([
        'auto_payment' => true,
        'auto_payment_method' => 'nagad',
        'auto_payment_wallet' => null,
        'auto_payment_pin' => null,
    ]);

    expect($ready->isAutoPaymentReady())->toBeTrue()
        ->and($halfConfigured->isAutoPaymentReady())->toBeFalse()
        ->and(Account::autoPaymentReady()->pluck('id')->all())->toBe([$ready->id]);
});

it('will not let the paid-block watermark be set through an ordinary account update', function () {
    $account = Account::factory()->create([
        'phone' => '01700000081',
        'auto_payment' => true,
        'auto_payment_method' => 'rocket',
        'auto_payment_wallet' => '018651441477',
        'auto_payment_pin' => '4321',
    ]);

    $this->putJson("/api/accounts/{$account->id}", [
        'auto_payment_rearmed_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    // Re-arming authorises spending, so it must only ever go through its own endpoint.
    expect($account->refresh()->auto_payment_rearmed_at)->toBeNull();
});
