<?php

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The daily window in which agents may not edit or delete accounts. Managers and super admins are
 * exempt, and creating accounts stays allowed for everyone.
 */
function lockWindow(string $start, string $end, bool $enabled = true): void
{
    Setting::instance()->update([
        'account_lock_enabled' => $enabled,
        'account_lock_start_time' => $start,
        'account_lock_end_time' => $end,
    ]);
}

/** Freezes the clock at the given Dhaka wall-clock time. */
function atDhaka(string $time): void
{
    Carbon::setTestNow(Carbon::parse("2026-08-13 {$time}", 'Asia/Dhaka'));
}

afterEach(function () {
    Carbon::setTestNow();
});

it('blocks an agent from deleting an account inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('23:30:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertForbidden();

    expect(Account::find($account->id))->not->toBeNull();
});

it('blocks an agent from editing an account inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('01:00:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create(['is_active' => true]);

    $this->actingAs($agent)
        ->putJson("/api/accounts/{$account->id}", ['is_active' => false])
        ->assertForbidden();

    expect($account->fresh()->is_active)->toBeTrue();
});

it('blocks an agent from the batch endpoints inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('23:30:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create(['pdf_uploaded' => false]);

    $this->actingAs($agent)
        ->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => [$account->id],
            'pdf_uploaded' => true,
        ])
        ->assertForbidden();

    expect($account->fresh()->pdf_uploaded)->toBeFalse();
});

it('lets an agent delete outside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('12:00:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();

    expect(Account::find($account->id))->toBeNull();
});

it('lets an agent create an account inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('23:30:00');

    $agent = User::factory()->create(['role' => 'agent']);

    $this->actingAs($agent)
        ->postJson('/api/accounts', [
            'phone' => '01711111111',
            'password' => 'secret',
        ])
        ->assertSuccessful();
});

it('lets a manager delete an agent account inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('23:30:00');

    $manager = User::factory()->create(['role' => 'manager']);
    $agent = User::factory()->create(['role' => 'agent', 'parent_id' => $manager->id]);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($manager)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();

    expect(Account::find($account->id))->toBeNull();
});

it('lets a super admin delete inside the lock window', function () {
    lockWindow('22:00:00', '02:00:00');
    atDhaka('23:30:00');

    $admin = User::factory()->create(['role' => 'super_admin']);
    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($admin)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();
});

it('locks nothing while the toggle is off', function () {
    lockWindow('22:00:00', '02:00:00', enabled: false);
    atDhaka('23:30:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();
});

it('locks nothing for a zero-length or unset window', function () {
    lockWindow('22:00:00', '22:00:00');
    atDhaka('22:00:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();

    Setting::instance()->update(['account_lock_start_time' => null, 'account_lock_end_time' => null]);

    $second = Account::factory()->for($agent)->create();

    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$second->id}")
        ->assertNoContent();
});

it('treats a same-day window as inclusive of start and exclusive of end', function () {
    lockWindow('09:00:00', '17:00:00');

    $agent = User::factory()->create(['role' => 'agent']);

    atDhaka('08:59:59');
    expect(App\Support\AccountLockWindow::blocks($agent))->toBeFalse();

    atDhaka('09:00:00');
    expect(App\Support\AccountLockWindow::blocks($agent))->toBeTrue();

    atDhaka('16:59:59');
    expect(App\Support\AccountLockWindow::blocks($agent))->toBeTrue();

    atDhaka('17:00:00');
    expect(App\Support\AccountLockWindow::blocks($agent))->toBeFalse();
});

it('wraps a window whose start is later than its end past midnight', function () {
    lockWindow('22:00:00', '02:00:00');

    $agent = User::factory()->create(['role' => 'agent']);

    foreach (['22:00:00', '23:59:59', '00:00:00', '01:59:59'] as $inside) {
        atDhaka($inside);
        expect(App\Support\AccountLockWindow::blocks($agent))->toBeTrue();
    }

    foreach (['02:00:00', '12:00:00', '21:59:59'] as $outside) {
        atDhaka($outside);
        expect(App\Support\AccountLockWindow::blocks($agent))->toBeFalse();
    }
});

it('defaults to an enabled 09:00-21:00 window', function () {
    $settings = Setting::instance();

    expect($settings->account_lock_enabled)->toBeTrue()
        ->and($settings->account_lock_start_time)->toBe('09:00:00')
        ->and($settings->account_lock_end_time)->toBe('21:00:00');

    $agent = User::factory()->create(['role' => 'agent']);
    $account = Account::factory()->for($agent)->create();

    atDhaka('12:00:00');
    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertForbidden();

    atDhaka('22:00:00');
    $this->actingAs($agent)
        ->deleteJson("/api/accounts/{$account->id}")
        ->assertNoContent();
});

it('persists the lock window through the settings endpoint', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->postJson('/api/settings', [
            'account_lock_enabled' => true,
            'account_lock_start_time' => '22:30:00',
            'account_lock_end_time' => '02:15:00',
        ])
        ->assertSuccessful()
        ->assertJson([
            'account_lock_enabled' => true,
            'account_lock_start_time' => '22:30:00',
            'account_lock_end_time' => '02:15:00',
        ]);
});
