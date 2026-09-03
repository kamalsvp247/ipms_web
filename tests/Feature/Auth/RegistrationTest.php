<?php

use App\Models\User;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

function makeManagerWithCode(): User
{
    return User::create([
        'name' => 'Referring Manager',
        'email' => 'manager@ipms.test',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_MANAGER,
    ]);
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register with a valid referral code', function () {
    $manager = makeManagerWithCode();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '01712345678',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => $manager->referral_code,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $created = User::where('email', 'test@example.com')->first();
    expect($created->role)->toBe(User::ROLE_AGENT);
    expect($created->parent_id)->toBe($manager->id);
    expect($created->phone)->toBe('01712345678');
    expect($created->is_approved)->toBeFalse();
});

test('registration fails without a phone number', function () {
    $manager = makeManagerWithCode();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => $manager->referral_code,
    ]);

    $response->assertSessionHasErrors('phone');
    $this->assertGuest();
});

test('registration fails without a referral code', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('referral_code');
    $this->assertGuest();
});

test('registration fails with an unknown referral code', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => 'DOES-NOT-EXIST',
    ]);

    $response->assertSessionHasErrors('referral_code');
    $this->assertGuest();
});

test('registration is rate limited', function () {
    RateLimiter::increment(md5('register'.'127.0.0.1'), amount: 5);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => 'DOES-NOT-EXIST',
    ]);

    $response->assertTooManyRequests();
    $this->assertGuest();
});

test('registration fails when captcha verification fails', function () {
    config(['turnstile.secret_key' => 'test-secret']);
    $this->mock(TurnstileVerifier::class)
        ->shouldReceive('verify')
        ->once()
        ->andReturn(false);

    $manager = makeManagerWithCode();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => $manager->referral_code,
        'cf-turnstile-response' => 'invalid-token',
    ]);

    $response->assertSessionHasErrors('cf-turnstile-response');
    $this->assertGuest();
});

test('registration fails with a referral code that belongs to a non-manager', function () {
    $agent = User::create([
        'name' => 'Some Agent',
        'email' => 'agent@ipms.test',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_AGENT,
        'referral_code' => 'AGENT-CODE',
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'referral_code' => $agent->referral_code,
    ]);

    $response->assertSessionHasErrors('referral_code');
    $this->assertGuest();
});
