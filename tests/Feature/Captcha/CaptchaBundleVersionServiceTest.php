<?php

use App\Models\CaptchaBundleVersion;
use App\Models\CaptchaTransformSeed;
use App\Services\Captcha\CaptchaBundleVersionService;
use App\Services\Captcha\LiveBundleClient;
use Illuminate\Support\Facades\File;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The service reads and writes the captcha storage directory. The global feature-test
 * isolation in tests/Pest.php points captcha.storage_path at a throwaway temp dir (and
 * removes it afterwards), so these tests exercise the real filesystem paths without ever
 * touching the developer's live storage/app/captcha.
 *
 * Resolve a path inside that isolated captcha directory.
 */
function captchaPath(string $sub = ''): string
{
    $base = rtrim((string) config('captcha.storage_path'), '/');

    return $sub === '' ? $base : $base.'/'.$sub;
}

/**
 * Sample encrypt_meta document used across the tests.
 *
 * @return array<string, mixed>
 */
function sampleMeta(): array
{
    return [
        'login' => ['module' => 'g1', 'version' => 8, 'skip' => 2, 'enc_len' => 25, 'secret' => 'loginSecretAAA'],
        'reserve' => ['module' => '$0', 'version' => 7, 'skip' => 4, 'enc_len' => 29, 'secret' => 'reserveSecretBBB'],
        'charset' => null,
    ];
}

/**
 * Write a throwaway bundle file with known bytes and return its absolute path.
 * The content drives the sha256 hash the service dedups on.
 */
function tempBundle(string $contents): string
{
    $path = sys_get_temp_dir().'/ipms_captcha_src_'.bin2hex(random_bytes(6)).'.js';
    File::put($path, $contents);

    return $path;
}

/**
 * Resolve the service with a Mockery mock of the sidecar bound into the container.
 *
 * @return array{0: CaptchaBundleVersionService, 1: \Mockery\MockInterface}
 */
function serviceWithMockSidecar(): array
{
    $sidecar = test()->mock(LiveBundleClient::class);
    $service = app(CaptchaBundleVersionService::class);

    return [$service, $sidecar];
}

describe('register', function () {
    it('dedups by content hash, copies the file to bundles/<hash>.js, and does not activate', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldNotReceive('reload');

        $srcA = tempBundle('bundle-content-A');
        $srcB = tempBundle('bundle-content-B');
        $srcADuplicate = tempBundle('bundle-content-A');

        $first = $service->register($srcA, sampleMeta(), 'https://ivac.example/static/mq94-AbC.js', true);
        $second = $service->register($srcADuplicate, sampleMeta(), 'https://ivac.example/static/mq94-AbC.js', true);
        $third = $service->register($srcB, sampleMeta(), 'https://ivac.example/static/other.js', true);

        // Same bytes collapse onto one row; different bytes make a second.
        expect($first->id)->toBe($second->id);
        expect($third->id)->not->toBe($first->id);
        expect(CaptchaBundleVersion::count())->toBe(2);

        // Denormalized columns are filled from the meta document.
        expect($first->login_version)->toBe(8);
        expect($first->reserve_enc_len)->toBe(29);

        // The file lands at bundles/<sha256>.js with the original bytes.
        $expectedHash = hash('sha256', 'bundle-content-A');
        expect($first->bundle_hash)->toBe($expectedHash);
        $stored = captchaPath('bundles/'.$expectedHash.'.js');
        expect(File::exists($stored))->toBeTrue();
        expect(File::get($stored))->toBe('bundle-content-A');

        // register never activates.
        expect($first->fresh()->is_active)->toBeFalse();
        expect(CaptchaBundleVersion::where('is_active', true)->exists())->toBeFalse();

        File::delete([$srcA, $srcB, $srcADuplicate]);
    });
});

describe('activate', function () {
    it('mirrors bundle and meta to the active paths, flips is_active, restores seeds, and promotes once', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldReceive('promote')->once()->andReturn(['ok' => true, 'promoted' => true]);

        // A previously-active version that should be deactivated by the new activation.
        $oldSrc = tempBundle('old-active-bundle');
        $old = $service->register($oldSrc, sampleMeta(), null, true);
        $old->activate();
        expect($old->fresh()->is_active)->toBeTrue();

        $newSrc = tempBundle('new-bundle-bytes');
        $version = $service->register($newSrc, sampleMeta(), 'https://ivac.example/static/new.js', true);

        $result = $service->activate($version);

        expect($result['success'])->toBeTrue();
        expect($result['version_id'])->toBe($version->id);

        // The new version is active; the old one is no longer.
        expect($version->fresh()->is_active)->toBeTrue();
        expect($old->fresh()->is_active)->toBeFalse();
        expect(CaptchaBundleVersion::where('is_active', true)->count())->toBe(1);

        // Active bundle file and meta mirror the activated version.
        $activeBundle = captchaPath('ivac-bundle.js');
        $activeMeta = captchaPath('encrypt_meta.json');
        expect(File::get($activeBundle))->toBe('new-bundle-bytes');
        $writtenMeta = json_decode(File::get($activeMeta), true);
        expect($writtenMeta['login']['secret'])->toBe('loginSecretAAA');
        expect($writtenMeta['reserve']['secret'])->toBe('reserveSecretBBB');

        // login + reserve seeds are restored as the active rows from the meta.
        $loginSeed = CaptchaTransformSeed::activeForType('login');
        $reserveSeed = CaptchaTransformSeed::activeForType('reserve');
        expect($loginSeed)->not->toBeNull();
        expect($loginSeed->seed)->toBe('loginSecretAAA');
        expect($loginSeed->offset)->toBe(2);
        expect($loginSeed->length)->toBe(25);
        expect($reserveSeed)->not->toBeNull();
        expect($reserveSeed->seed)->toBe('reserveSecretBBB');
        expect($reserveSeed->offset)->toBe(4);
        expect($reserveSeed->length)->toBe(29);

        File::delete([$oldSrc, $newSrc]);
    });

    it('reports a degraded result when the sidecar reload fails after activation', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldReceive('promote')->once()->andReturn(['ok' => false, 'promoted' => false]);

        $src = tempBundle('reload-fail-bundle');
        $version = $service->register($src, sampleMeta(), null, true);

        $result = $service->activate($version);

        // Activation still commits to disk (the mtime poller is the safety net), but the
        // failed reload is surfaced rather than reported as a clean success.
        expect($result['success'])->toBeTrue();
        expect($result['sidecar_reloaded'])->toBeFalse();
        expect($result['message'])->toContain('sidecar reload failed');
        expect($version->fresh()->is_active)->toBeTrue();

        File::delete($src);
    });

    it('fails without changing state and does not reload when the bundle file is missing', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldNotReceive('promote');
        $sidecar->shouldNotReceive('reload');

        // An existing active version that must stay active when the new activation fails.
        $activeSrc = tempBundle('still-active-bundle');
        $active = $service->register($activeSrc, sampleMeta(), null, true);
        $active->activate();

        // A registered version whose backing file has been removed from disk.
        $orphanSrc = tempBundle('orphan-bundle-bytes');
        $orphan = $service->register($orphanSrc, sampleMeta(), null, true);
        File::delete(captchaPath('bundles/'.$orphan->bundle_hash.'.js'));

        $result = $service->activate($orphan);

        expect($result['success'])->toBeFalse();
        expect($orphan->fresh()->is_active)->toBeFalse();
        expect($active->fresh()->is_active)->toBeTrue();

        File::delete([$activeSrc, $orphanSrc]);
    });
});

describe('pruneToLimit', function () {
    it('deletes the oldest versions beyond the keep limit but never the active or labeled ones, removing orphan files', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldNotReceive('reload');

        // Six versions, oldest first, each with distinct bytes (so distinct hashes/files).
        $versions = [];
        $sources = [];
        for ($i = 0; $i < 6; $i++) {
            $src = tempBundle("prune-bundle-{$i}");
            $sources[] = $src;
            $v = $service->register($src, sampleMeta(), null, true);
            // Force a strictly increasing created_at so ordering is deterministic.
            $v->forceFill(['created_at' => now()->addSeconds($i)])->save();
            $versions[] = $v;
        }

        // versions[0] = oldest. Keep the oldest one active and label another old one.
        $versions[0]->activate();
        $versions[1]->forceFill(['label' => 'pinned'])->save();

        // Keep the newest 2 by created_at; that leaves versions[0..3] as prune candidates.
        $deleted = $service->pruneToLimit(2);

        // Of the 4 candidates, versions[0] (active) and versions[1] (labeled) survive,
        // so exactly 2 are deleted.
        expect($deleted)->toBe(2);
        expect(CaptchaBundleVersion::count())->toBe(4);

        // Active and labeled rows are intact.
        expect(CaptchaBundleVersion::find($versions[0]->id))->not->toBeNull();
        expect(CaptchaBundleVersion::find($versions[1]->id))->not->toBeNull();

        // The two deleted rows (versions[2], versions[3]) are gone with their files.
        expect(CaptchaBundleVersion::find($versions[2]->id))->toBeNull();
        expect(CaptchaBundleVersion::find($versions[3]->id))->toBeNull();
        expect(File::exists(captchaPath('bundles/'.$versions[2]->bundle_hash.'.js')))->toBeFalse();
        expect(File::exists(captchaPath('bundles/'.$versions[3]->bundle_hash.'.js')))->toBeFalse();

        // A surviving row's file is untouched.
        expect(File::exists(captchaPath('bundles/'.$versions[0]->bundle_hash.'.js')))->toBeTrue();

        File::delete($sources);
    });
});

describe('importCurrent', function () {
    it('backfills one active version from the on-disk bundle and meta and is idempotent', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldNotReceive('reload');

        // Seed the active on-disk pair the import reads from.
        File::ensureDirectoryExists(captchaPath());
        File::put(captchaPath('ivac-bundle.js'), 'on-disk-active-bundle');
        File::put(captchaPath('encrypt_meta.json'), json_encode(sampleMeta()));

        $imported = $service->importCurrent();

        expect($imported)->not->toBeNull();
        expect($imported->is_active)->toBeTrue();
        expect($imported->bundle_hash)->toBe(hash('sha256', 'on-disk-active-bundle'));
        expect($imported->login_version)->toBe(8);
        expect(CaptchaBundleVersion::count())->toBe(1);

        // The bundle was copied into the versioned store.
        expect(File::exists(captchaPath('bundles/'.$imported->bundle_hash.'.js')))->toBeTrue();

        // Second call creates nothing and returns the existing active version.
        $again = $service->importCurrent();

        expect(CaptchaBundleVersion::count())->toBe(1);
        expect($again->id)->toBe($imported->id);
    });
});

describe('delete', function () {
    it('removes the version row and its orphan bundle file', function () {
        [$service, $sidecar] = serviceWithMockSidecar();
        $sidecar->shouldNotReceive('reload');

        $src = tempBundle('delete-me-bundle');
        $version = $service->register($src, sampleMeta(), null, true);

        $file = captchaPath('bundles/'.$version->bundle_hash.'.js');
        expect(File::exists($file))->toBeTrue();

        $service->delete($version);

        expect(CaptchaBundleVersion::find($version->id))->toBeNull();
        expect(File::exists($file))->toBeFalse();

        File::delete($src);
    });
});
