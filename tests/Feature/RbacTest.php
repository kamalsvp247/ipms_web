<?php

use App\Models\Account;
use App\Models\CaptchaProvider;
use App\Models\PaymentLink;
use App\Models\User;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function rbacUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function rbacSuperAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function rbacManager(): User
{
    return User::factory()->create(['role' => 'manager']);
}

// ─── Web route gates ─────────────────────────────────────────────────────────

test('regular user can access accounts page', function () {
    $this->actingAs(rbacUser())->get('/accounts')->assertOk();
});

test('regular user can access payment-links page', function () {
    $this->actingAs(rbacUser())->get('/payment-links')->assertOk();
});

test('regular user is forbidden from bot-control page', function () {
    $this->actingAs(rbacUser())->get('/bot-control')->assertForbidden();
});

test('super admin can access bot-control page', function () {
    $this->actingAs(rbacSuperAdmin())->get('/bot-control')->assertOk();
});

test('manager can access bot-control page', function () {
    $this->actingAs(rbacManager())->get('/bot-control')->assertOk();
});

test('regular user is forbidden from captcha-algorithm-monitor page', function () {
    $this->actingAs(rbacUser())->get('/captcha-algorithm-monitor')->assertRedirect();
});

test('super admin can access captcha-algorithm-monitor page', function () {
    $this->actingAs(rbacSuperAdmin())->get('/captcha-algorithm-monitor')->assertOk();
});

test('manager is forbidden from captcha-algorithm-monitor page', function () {
    $this->actingAs(rbacManager())->get('/captcha-algorithm-monitor')->assertRedirect();
});

test('super admin can access in-house-captcha page', function () {
    $this->actingAs(rbacSuperAdmin())->get('/in-house-captcha')->assertOk();
});

test('regular user cannot access in-house-captcha page', function () {
    $this->actingAs(rbacUser())->get('/in-house-captcha')->assertRedirect();
});

test('regular user is redirected home from admin-only pages', function () {
    $user = rbacUser();

    foreach (['/dashboard', '/users', '/gmail', '/log-analysis', '/vps-manager'] as $path) {
        $this->actingAs($user)->get($path)->assertRedirect('/accounts');
    }
});

test('regular user can access api-tester and otps pages', function () {
    $user = rbacUser();

    foreach (['/api-tester', '/otps'] as $path) {
        $this->actingAs($user)->get($path)->assertOk();
    }
});

test('super admin can access admin-only pages', function () {
    $admin = rbacSuperAdmin();

    foreach (['/dashboard', '/users', '/otps', '/api-tester', '/log-analysis'] as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});

test('regular user can access their three allowed pages', function () {
    $user = rbacUser();

    $this->actingAs($user)->get('/accounts')->assertOk();
    $this->actingAs($user)->get('/captcha-providers')->assertOk();
    $this->actingAs($user)->get('/payment-links')->assertOk();
});

test('regular user can access otps api routes', function () {
    $this->actingAs(rbacUser())->getJson('/api/otps')->assertOk();
});

test('guest cannot access otps api routes', function () {
    $this->getJson('/api/otps')->assertUnauthorized();
});

test('guest cannot access captcha-algorithm api routes', function () {
    $this->getJson('/api/captcha-algorithm/history')->assertUnauthorized();
    $this->getJson('/api/captcha-algorithm/engine')->assertUnauthorized();
    $this->getJson('/api/captcha-algorithm/progress')->assertUnauthorized();
    $this->postJson('/api/captcha-algorithm/analyze')->assertUnauthorized();
});

test('regular user cannot access captcha-algorithm api routes', function () {
    $user = rbacUser();
    $this->actingAs($user)->getJson('/api/captcha-algorithm/history')->assertForbidden();
    $this->actingAs($user)->getJson('/api/captcha-algorithm/engine')->assertForbidden();
    $this->actingAs($user)->getJson('/api/captcha-algorithm/progress')->assertForbidden();
    $this->actingAs($user)->postJson('/api/captcha-algorithm/analyze')->assertForbidden();
});

test('manager cannot access captcha-algorithm api routes', function () {
    $manager = rbacManager();
    $this->actingAs($manager)->getJson('/api/captcha-algorithm/history')->assertForbidden();
    $this->actingAs($manager)->getJson('/api/captcha-algorithm/engine')->assertForbidden();
    $this->actingAs($manager)->getJson('/api/captcha-algorithm/progress')->assertForbidden();
    $this->actingAs($manager)->postJson('/api/captcha-algorithm/analyze')->assertForbidden();
    $this->actingAs($manager)->getJson('/api/captcha-algorithm/versions')->assertForbidden();
});

test('super admin can access captcha-algorithm read api routes', function () {
    $admin = rbacSuperAdmin();
    $this->actingAs($admin)->getJson('/api/captcha-algorithm/history')->assertOk();
    $this->actingAs($admin)->getJson('/api/captcha-algorithm/engine')->assertOk();
});

// ─── CaptchaProvider scoping ──────────────────────────────────────────────────

test('regular user only sees their own captcha providers', function () {
    $user = rbacUser();
    $other = rbacUser();

    CaptchaProvider::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
    CaptchaProvider::factory()->create(['user_id' => $other->id, 'name' => 'Not Mine']);

    $this->actingAs($user)
        ->getJson('/api/captcha-providers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine');
});

test('super admin sees all captcha providers', function () {
    $admin = rbacSuperAdmin();
    $user = rbacUser();

    CaptchaProvider::factory()->create(['user_id' => $admin->id]);
    CaptchaProvider::factory()->create(['user_id' => $user->id]);

    $this->actingAs($admin)
        ->getJson('/api/captcha-providers')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('store sets user_id to authenticated user', function () {
    $user = rbacUser();

    $this->actingAs($user)->postJson('/api/captcha-providers', [
        'name' => 'New Provider',
        'type' => 'capmonster',
        'enabled' => false,
    ])->assertCreated();

    $this->assertDatabaseHas('captcha_providers', [
        'name' => 'New Provider',
        'user_id' => $user->id,
    ]);
});

test('regular user cannot update another user\'s captcha provider', function () {
    $user = rbacUser();
    $other = rbacUser();
    $provider = CaptchaProvider::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->putJson("/api/captcha-providers/{$provider->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

test('regular user can update their own captcha provider', function () {
    $user = rbacUser();
    $provider = CaptchaProvider::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/captcha-providers/{$provider->id}", ['name' => 'Updated'])
        ->assertOk();
});

test('regular user cannot delete another user\'s captcha provider', function () {
    $user = rbacUser();
    $other = rbacUser();
    $provider = CaptchaProvider::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->deleteJson("/api/captcha-providers/{$provider->id}")
        ->assertForbidden();
});

// ─── PaymentLink scoping ──────────────────────────────────────────────────────

test('regular user only sees payment links for their own accounts', function () {
    $user = rbacUser();
    $other = rbacUser();

    $myAccount = Account::factory()->create(['user_id' => $user->id]);
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);

    PaymentLink::factory()->create(['account_id' => $myAccount->id, 'account_phone' => $myAccount->phone]);
    PaymentLink::factory()->create(['account_id' => $otherAccount->id, 'account_phone' => $otherAccount->phone]);

    $this->actingAs($user)
        ->getJson('/api/payment-links')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('super admin sees all payment links', function () {
    $admin = rbacSuperAdmin();
    $user = rbacUser();

    $myAccount = Account::factory()->create(['user_id' => $admin->id]);
    $otherAccount = Account::factory()->create(['user_id' => $user->id]);

    PaymentLink::factory()->create(['account_id' => $myAccount->id, 'account_phone' => $myAccount->phone]);
    PaymentLink::factory()->create(['account_id' => $otherAccount->id, 'account_phone' => $otherAccount->phone]);

    $this->actingAs($admin)
        ->getJson('/api/payment-links')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('regular user cannot delete a payment link', function () {
    $user = rbacUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $link = PaymentLink::factory()->create(['account_id' => $account->id]);

    $this->actingAs($user)
        ->deleteJson("/api/payment-links/{$link->id}")
        ->assertForbidden();
});

test('super admin can delete a payment link', function () {
    $admin = rbacSuperAdmin();
    $link = PaymentLink::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/payment-links/{$link->id}")
        ->assertNoContent();

    $this->assertModelMissing($link);
});

// ─── Inertia permissions shared data ─────────────────────────────────────────

test('inertia shared data includes permissions for authenticated user', function () {
    $user = rbacUser();

    $response = $this->actingAs($user)->get('/accounts');

    $response->assertInertia(fn ($page) => $page
        ->has('auth.permissions')
        ->where('auth.permissions', fn ($perms) => $perms['accounts.read'] === true && $perms['bot.manage'] === false)
    );
});

test('super admin has bot.manage permission in shared data', function () {
    $admin = rbacSuperAdmin();

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('auth.permissions', fn ($perms) => $perms['bot.manage'] === true)
    );
});
