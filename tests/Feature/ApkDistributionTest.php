<?php

use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use App\Models\ApkScreenshot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makeApkRelease(array $overrides = []): ApkRelease
{
    Storage::fake('public');

    Storage::disk('public')->put('apk-releases/test.apk', 'fake-apk-bytes');

    return ApkRelease::create([
        'version_name' => '2.0',
        'version_code' => 20,
        'file_path' => 'apk-releases/test.apk',
        'file_name' => 'Duronto-v2.0.apk',
        'file_size' => 14,
        'checksum_sha256' => hash('sha256', 'fake-apk-bytes'),
        'is_active' => true,
        'released_at' => now(),
        ...$overrides,
    ]);
}

// ─── Public landing + download ───────────────────────────────────────────────

test('guest can view the apk landing page', function () {
    makeApkRelease();

    $this->get('/apk')->assertOk();
});

test('landing page returns 404 when unpublished', function () {
    ApkAppInfo::instance()->update(['is_published' => false]);

    $this->get('/apk')->assertNotFound();
});

test('guest can download the active apk and the counter increments', function () {
    $release = makeApkRelease();

    $response = $this->get('/apk/download');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.android.package-archive');
    expect($release->fresh()->download_count)->toBe(1);
});

test('download serves the bundled apk when no active release exists', function () {
    $this->get('/apk/download')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.android.package-archive');
});

// ─── Admin access control ────────────────────────────────────────────────────

test('regular user cannot access the apk manager page or api', function () {
    $user = User::factory()->create(['role' => 'user']);

    $this->actingAs($user)->get('/apk-manager')->assertForbidden();
    $this->actingAs($user)->getJson('/api/apk/overview')->assertForbidden();
});

test('guest cannot access the apk manager api', function () {
    $this->getJson('/api/apk/overview')->assertUnauthorized();
});

test('super admin can access the apk manager page and overview', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)->get('/apk-manager')->assertOk();
    $this->actingAs($admin)->getJson('/api/apk/overview')
        ->assertOk()
        ->assertJsonStructure(['info', 'releases', 'screenshots']);
});

// ─── App info management ─────────────────────────────────────────────────────

test('super admin can update app information with logo', function () {
    Storage::fake('public');
    $admin = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($admin)->post('/api/apk/info', [
        'app_title' => 'Duronto',
        'tagline' => 'Fast SMS forwarding',
        'description' => 'Forwards SMS in real time.',
        'developer_name' => 'Senda Japan Ltd',
        'features' => ['Instant forwarding', 'Boot persistence'],
        'is_published' => true,
        'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
    ]);

    $response->assertOk();
    $info = ApkAppInfo::instance();
    expect($info->app_title)->toBe('Duronto');
    expect($info->features)->toBe(['Instant forwarding', 'Boot persistence']);
    expect($info->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($info->logo_path);
});

// ─── Release management ──────────────────────────────────────────────────────

test('super admin can upload a release and it becomes active', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $previous = makeApkRelease();

    $response = $this->actingAs($admin)->post('/api/apk/releases', [
        'apk' => UploadedFile::fake()->create('Duronto-v2.1.apk', 2048),
        'version_name' => '2.1',
        'version_code' => 21,
        'changelog' => "New stuff\nBug fixes",
        'activate' => true,
    ]);

    $response->assertCreated();
    $newRelease = ApkRelease::where('version_name', '2.1')->first();
    expect($newRelease->is_active)->toBeTrue();
    expect($newRelease->checksum_sha256)->toHaveLength(64);
    expect($previous->fresh()->is_active)->toBeFalse();
    Storage::disk('public')->assertExists($newRelease->file_path);
});

test('release upload rejects non-apk files', function () {
    Storage::fake();
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)->postJson('/api/apk/releases', [
        'apk' => UploadedFile::fake()->create('notes.txt', 10),
        'version_name' => '2.1',
    ])->assertUnprocessable();
});

test('super admin can activate an archived release', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $active = makeApkRelease();
    $archived = makeApkRelease(['version_name' => '1.9', 'is_active' => false]);

    $this->actingAs($admin)->post("/api/apk/releases/{$archived->id}/activate")->assertOk();

    expect($archived->fresh()->is_active)->toBeTrue();
    expect($active->fresh()->is_active)->toBeFalse();
});

test('super admin can delete a release and its file', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $release = makeApkRelease();

    $this->actingAs($admin)->delete("/api/apk/releases/{$release->id}")->assertOk();

    expect(ApkRelease::find($release->id))->toBeNull();
    Storage::disk('public')->assertMissing('apk-releases/test.apk');
});

// ─── Screenshot management ───────────────────────────────────────────────────

test('super admin can upload, reorder and delete screenshots', function () {
    Storage::fake('public');
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)->post('/api/apk/screenshots', [
        'screenshots' => [
            UploadedFile::fake()->image('one.png', 400, 800),
            UploadedFile::fake()->image('two.png', 400, 800),
        ],
    ])->assertCreated();

    $shots = ApkScreenshot::orderBy('sort_order')->get();
    expect($shots)->toHaveCount(2);

    $this->actingAs($admin)->putJson('/api/apk/screenshots/reorder', [
        'ids' => [$shots[1]->id, $shots[0]->id],
    ])->assertOk();

    expect(ApkScreenshot::orderBy('sort_order')->first()->id)->toBe($shots[1]->id);

    $this->actingAs($admin)->delete("/api/apk/screenshots/{$shots[0]->id}")->assertOk();
    expect(ApkScreenshot::count())->toBe(1);
});
