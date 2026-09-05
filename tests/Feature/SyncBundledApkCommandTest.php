<?php

use App\Models\ApkRelease;
use Illuminate\Support\Facades\Storage;

it('publishes the bundled apk as the active release', function () {
    Storage::fake('public');

    $this->artisan('apk:sync-bundled')
        ->assertSuccessful();

    $release = ApkRelease::active();

    expect($release)->not->toBeNull()
        ->and($release->file_path)->toBe('apk-releases/DURONTO.apk')
        ->and($release->file_name)->toBe('DURONTO.apk')
        ->and($release->checksum_sha256)->toHaveLength(64);

    Storage::disk('public')->assertExists('apk-releases/DURONTO.apk');
});

it('deactivates a previous release when publishing the bundled apk', function () {
    Storage::fake('public');
    $old = ApkRelease::create([
        'version_name' => 'legacy',
        'version_code' => 1,
        'file_path' => 'apk-releases/legacy.apk',
        'file_name' => 'legacy.apk',
        'file_size' => 1,
        'checksum_sha256' => str_repeat('a', 64),
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->artisan('apk:sync-bundled')->assertSuccessful();

    expect($old->fresh()->is_active)->toBeFalse()
        ->and(ApkRelease::active()->file_name)->toBe('DURONTO.apk');
});
