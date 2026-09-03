<?php

use App\Models\Notice;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function noticeUser(string $role): User
{
    return User::factory()->create(['role' => $role, 'email_verified_at' => now()]);
}

// ─── Authoring ───

it('lets a super admin create a notice', function () {
    actingAs(noticeUser('super_admin'))
        ->postJson('/api/notices', ['text' => 'সার্ভার রক্ষণাবেক্ষণ চলবে।', 'enabled' => true])
        ->assertCreated()
        ->assertJsonPath('text', 'সার্ভার রক্ষণাবেক্ষণ চলবে।')
        ->assertJsonPath('enabled', true);

    $notice = Notice::first();
    expect($notice->text)->toBe('সার্ভার রক্ষণাবেক্ষণ চলবে।')
        ->and($notice->is_enabled)->toBeTrue();
});

it('lets a manager create a notice', function () {
    actingAs(noticeUser('manager'))
        ->postJson('/api/notices', ['text' => 'নতুন নোটিশ', 'enabled' => true])
        ->assertCreated();

    expect(Notice::first()->text)->toBe('নতুন নোটিশ');
});

it('keeps several notices at once and orders new ones last', function () {
    $author = noticeUser('super_admin');

    actingAs($author)->postJson('/api/notices', ['text' => 'প্রথম', 'enabled' => true])->assertCreated();
    actingAs($author)->postJson('/api/notices', ['text' => 'দ্বিতীয়', 'enabled' => true])->assertCreated();

    actingAs($author)->getJson('/api/notices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.text', 'প্রথম')
        ->assertJsonPath('data.1.text', 'দ্বিতীয়');
});

it('toggles a single notice without touching the others', function () {
    $keep = Notice::create(['text' => 'চালু', 'is_enabled' => true, 'sort_order' => 0]);
    $off = Notice::create(['text' => 'বন্ধ', 'is_enabled' => true, 'sort_order' => 1]);

    actingAs(noticeUser('super_admin'))
        ->putJson("/api/notices/{$off->id}", ['text' => $off->text, 'enabled' => false])
        ->assertOk()
        ->assertJsonPath('enabled', false);

    expect($off->fresh()->is_enabled)->toBeFalse()
        ->and($keep->fresh()->is_enabled)->toBeTrue();
});

it('deletes a notice', function () {
    $notice = Notice::create(['text' => 'মুছে ফেলা হবে', 'is_enabled' => true, 'sort_order' => 0]);

    actingAs(noticeUser('super_admin'))->deleteJson("/api/notices/{$notice->id}")->assertOk();

    expect(Notice::count())->toBe(0);
});

it('rejects a blank notice', function () {
    actingAs(noticeUser('super_admin'))
        ->postJson('/api/notices', ['text' => '   ', 'enabled' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('rejects a notice longer than 2000 characters', function () {
    actingAs(noticeUser('super_admin'))
        ->postJson('/api/notices', ['text' => str_repeat('ক', 2001), 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

// ─── Authorization ───

it('forbids an agent from reading or writing notices', function () {
    $agent = noticeUser('agent');

    actingAs($agent)->getJson('/api/notices')->assertForbidden();
    actingAs($agent)->postJson('/api/notices', ['text' => 'nope', 'enabled' => true])->assertForbidden();
});

// ─── Delivery ───

it('shares every enabled notice with every signed-in user, in order', function () {
    Notice::create(['text' => 'দ্বিতীয় নোটিশ', 'is_enabled' => true, 'sort_order' => 2]);
    Notice::create(['text' => 'প্রথম নোটিশ', 'is_enabled' => true, 'sort_order' => 1]);
    Notice::create(['text' => 'লুকানো নোটিশ', 'is_enabled' => false, 'sort_order' => 0]);

    actingAs(noticeUser('agent'))->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notices', ['প্রথম নোটিশ', 'দ্বিতীয় নোটিশ']));
});

it('shares an empty list when no notice is enabled', function () {
    Notice::create(['text' => 'লুকানো নোটিশ', 'is_enabled' => false, 'sort_order' => 0]);

    actingAs(noticeUser('agent'))->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notices', []));
});
