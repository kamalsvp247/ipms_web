<?php

namespace App\Services\Captcha;

use App\Models\CaptchaBundleVersion;
use App\Models\CaptchaTransformSeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registry + activation for downloaded IVAC captcha bundles.
 *
 * A "version" pairs the downloaded JS file (kept at bundles/<sha256>.js) with its
 * encrypt_meta. The active version is mirrored to the two fixed paths the live-bundle
 * sidecar reads (ivac-bundle.js + encrypt_meta.json), so activating an older version
 * is all it takes to roll the encryption back — no sidecar contract change.
 */
class CaptchaBundleVersionService
{
    private const RETENTION = 10;

    private const LOCK_KEY = 'captcha:bundle-activate';

    public function __construct(private readonly LiveBundleClient $sidecar) {}

    private function storageDir(): string
    {
        return rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/');
    }

    private function bundlesDir(): string
    {
        return $this->storageDir().'/bundles';
    }

    private function activeBundlePath(): string
    {
        return $this->storageDir().'/ivac-bundle.js';
    }

    private function activeMetaPath(): string
    {
        return $this->storageDir().'/encrypt_meta.json';
    }

    private function versionFilePath(string $hash): string
    {
        return $this->bundlesDir().'/'.$hash.'.js';
    }

    /**
     * Register a downloaded bundle as a version (dedup by content hash). Does not activate.
     *
     * @param  string  $bundleSrcPath  path to the freshly downloaded bundle file
     * @param  array<string, mixed>  $metaDoc  the encrypt_meta document (may be partial)
     * @param  array{download_started_at?: ?string, download_duration_ms?: ?int, processing_completed_at?: ?\Carbon\CarbonInterface, processing_duration_ms?: ?int}  $timing
     */
    public function register(string $bundleSrcPath, array $metaDoc, ?string $bundleUrl, bool $extractionOk, array $timing = []): CaptchaBundleVersion
    {
        $hash = hash_file('sha256', $bundleSrcPath);

        if (! is_dir($this->bundlesDir())) {
            mkdir($this->bundlesDir(), 0775, true);
        }

        $destFile = $this->versionFilePath($hash);
        if (! is_file($destFile)) {
            $this->atomicCopy($bundleSrcPath, $destFile);
        }

        $attrs = array_merge($this->denormalize($metaDoc), [
            'bundle_filename' => $bundleUrl ? basename(parse_url($bundleUrl, PHP_URL_PATH) ?? '') : ($metaDoc['bundle_filename'] ?? null),
            'bundle_url' => $bundleUrl,
            'meta_json' => $metaDoc,
            'extraction_ok' => $extractionOk,
            'download_started_at' => $timing['download_started_at'] ?? null,
            'download_duration_ms' => $timing['download_duration_ms'] ?? null,
            'processing_completed_at' => $timing['processing_completed_at'] ?? null,
            'processing_duration_ms' => $timing['processing_duration_ms'] ?? null,
        ]);

        $existing = CaptchaBundleVersion::where('bundle_hash', $hash)->first();
        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing;
        }

        return CaptchaBundleVersion::create(array_merge($attrs, ['bundle_hash' => $hash]));
    }

    /**
     * Make a stored version the active one: mirror its file + meta to the sidecar paths,
     * flip is_active, restore the seed rows, and reload the sidecar.
     *
     * @return array{success: bool, message: string, version_id?: int, sidecar_reloaded?: bool}
     */
    public function activate(CaptchaBundleVersion $version): array
    {
        $file = $this->versionFilePath($version->bundle_hash);
        if (! is_file($file)) {
            return ['success' => false, 'message' => 'Bundle file is missing on disk; cannot activate this version.'];
        }

        $lock = Cache::lock(self::LOCK_KEY, 15);
        if (! $lock->get()) {
            return ['success' => false, 'message' => 'Another activation is in progress; try again.'];
        }

        try {
            // Bundle first, then meta: meta is the commit marker the sidecar keys its
            // reload on, so it never observes a new meta against a stale bundle.
            $this->atomicCopy($file, $this->activeBundlePath());
            $this->atomicWrite($this->activeMetaPath(), json_encode($version->meta_json ?? [], JSON_UNESCAPED_SLASHES));

            $version->activate();

            $this->restoreSeed('login', $version->meta_json['login'] ?? null);
            $this->restoreSeed('reserve', $version->meta_json['reserve'] ?? null);

            // A failed reload means the new bundle+meta are on disk but the sidecar is
            // still serving the previous bundle. The mtime poller will pick it up within
            // ~5s, but surface the failure so a stuck sidecar does not silently keep
            // encrypting with the old bundle. (The poller is the safety net, not the alarm.)
            // The sidecar synchronously re-evaluates the ~2 MB bundle on reload, which is
            // the dominant cost of the "processing -> healthy" gap. Time it precisely so the
            // monitor can attribute that ~1 s instead of it hiding inside a rounded total.
            // Promote the pre-staged bundle for an instant swap (no re-eval). When staging
            // did not land (miss/failure) the sidecar falls back to a full reload, so this is
            // never slower than the old reload path — only faster when staging succeeded. The
            // measured duration therefore reflects an instant promote (~ms) on the fast path.
            $reloadStart = hrtime(true);
            $promote = $this->sidecar->promote($version->bundle_hash);
            $reloaded = $promote['ok'];
            if ($reloaded) {
                $version->reload_duration_ms = (int) round((hrtime(true) - $reloadStart) / 1_000_000);
                $version->healthy_at = now();
                $version->save();
            } else {
                Log::error('Captcha sidecar promote/reload failed after bundle activation', ['version_id' => $version->id]);
            }

            return [
                'success' => true,
                'sidecar_reloaded' => $reloaded,
                'message' => $reloaded
                    ? 'Bundle version activated.'
                    : 'Bundle version activated, but the sidecar reload failed — encryption may serve the previous bundle until it reloads.',
                'version_id' => $version->id,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Restore the active version's file + meta to the sidecar paths and reload.
     *
     * Used after an unclean analysis (python rewrites ivac-bundle.js even on a bad run
     * but keeps last-known-good meta, leaving a mismatched pair) and as crash recovery.
     *
     * @return bool true when the active pair was restored AND the sidecar reloaded
     */
    public function reconcileActiveFiles(): bool
    {
        $active = CaptchaBundleVersion::current();
        if (! $active) {
            return false;
        }

        $file = $this->versionFilePath($active->bundle_hash);
        if (! is_file($file)) {
            Log::warning('Captcha bundle reconcile: active version file missing', ['hash' => $active->bundle_hash]);

            return false;
        }

        $this->atomicCopy($file, $this->activeBundlePath());
        $this->atomicWrite($this->activeMetaPath(), json_encode($active->meta_json ?? [], JSON_UNESCAPED_SLASHES));
        $reloaded = $this->sidecar->reload();
        if (! $reloaded) {
            Log::error('Captcha sidecar reload failed during reconcile', ['hash' => $active->bundle_hash]);
        }

        return $reloaded;
    }

    /**
     * Prune oldest versions beyond the retention limit. Never removes the active version
     * or any labeled (annotated) version.
     *
     * @return int number of versions deleted
     */
    public function pruneToLimit(int $keep = self::RETENTION): int
    {
        $all = CaptchaBundleVersion::orderByDesc('created_at')->orderByDesc('id')->get();
        if ($all->count() <= $keep) {
            return 0;
        }

        $deleted = 0;
        foreach ($all->slice($keep) as $version) {
            if ($version->is_active || $version->label !== null) {
                continue;
            }

            $hash = $version->bundle_hash;
            $version->delete();
            $deleted++;

            // Remove the file only when no surviving row still references that hash.
            if (! CaptchaBundleVersion::where('bundle_hash', $hash)->exists()) {
                $file = $this->versionFilePath($hash);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete a version row and its bundle file (when no other row references the hash).
     */
    public function delete(CaptchaBundleVersion $version): void
    {
        $hash = $version->bundle_hash;
        $version->delete();

        if (! CaptchaBundleVersion::where('bundle_hash', $hash)->exists()) {
            $file = $this->versionFilePath((string) $hash);
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Backfill the current on-disk bundle as the initial active version. Idempotent:
     * does nothing once any version exists.
     */
    public function importCurrent(): ?CaptchaBundleVersion
    {
        if (CaptchaBundleVersion::query()->exists()) {
            return CaptchaBundleVersion::current();
        }

        $bundle = $this->activeBundlePath();
        $metaPath = $this->activeMetaPath();
        if (! is_file($bundle) || ! is_file($metaPath)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (! is_array($meta)) {
            return null;
        }

        $version = $this->register($bundle, $meta, null, true);
        $version->activate();

        return $version;
    }

    /**
     * Active version + recent version list for the monitor UI.
     *
     * @return array{active: array<string, mixed>|null, versions: array<int, array<string, mixed>>}
     */
    public function summary(int $limit = 30): array
    {
        $active = CaptchaBundleVersion::current();

        return [
            'active' => $active ? $this->serialize($active) : null,
            'versions' => CaptchaBundleVersion::orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (CaptchaBundleVersion $v) => $this->serialize($v))
                ->all(),
        ];
    }

    /**
     * Serialize a version for JSON output (secrets omitted).
     *
     * @return array<string, mixed>
     */
    public function serialize(CaptchaBundleVersion $v): array
    {
        // Three phases: download -> process -> reload(become healthy). Prefer the precise
        // in-memory millisecond captures; fall back to the second-resolution timestamp
        // diffs for rows created before those columns existed. Total is the sum of the
        // phases when precise values are present, so download + process + reload always
        // reconciles with the total shown (no rounding drift, no hidden phase).
        $downloadCompletedAt = $v->download_started_at && $v->download_duration_ms !== null
            ? $v->download_started_at->copy()->addMilliseconds($v->download_duration_ms)
            : null;

        $processingDurationMs = $v->processing_duration_ms ?? (
            $downloadCompletedAt && $v->processing_completed_at
                ? (int) $downloadCompletedAt->diffInMilliseconds($v->processing_completed_at, false)
                : null
        );

        $reloadDurationMs = $v->reload_duration_ms ?? (
            $v->processing_completed_at && $v->healthy_at
                ? (int) $v->processing_completed_at->diffInMilliseconds($v->healthy_at, false)
                : null
        );

        $phases = array_filter(
            [$v->download_duration_ms, $processingDurationMs, $reloadDurationMs],
            static fn ($ms) => $ms !== null,
        );
        $totalReadyMs = ! empty($phases)
            ? array_sum($phases)
            : ($v->download_started_at && $v->healthy_at
                ? (int) $v->download_started_at->diffInMilliseconds($v->healthy_at, false)
                : null);

        return [
            'id' => $v->id,
            'bundle_filename' => $v->bundle_filename,
            'bundle_url' => $v->bundle_url,
            'bundle_hash' => $v->bundle_hash,
            'hash_short' => substr((string) $v->bundle_hash, 0, 12),
            'login_version' => $v->login_version,
            'login_skip' => $v->login_skip,
            'login_enc_len' => $v->login_enc_len,
            'reserve_version' => $v->reserve_version,
            'reserve_skip' => $v->reserve_skip,
            'reserve_enc_len' => $v->reserve_enc_len,
            'charset' => $v->charset,
            'extraction_ok' => $v->extraction_ok,
            'is_active' => $v->is_active,
            'label' => $v->label,
            'file_exists' => is_file($this->versionFilePath((string) $v->bundle_hash)),
            // Lifecycle timing: download -> process -> healthy
            'download_started_at' => $v->download_started_at?->toISOString(),
            'download_started_at_human' => $v->download_started_at?->diffForHumans(),
            'download_duration_ms' => $v->download_duration_ms,
            'download_completed_at' => $downloadCompletedAt?->toISOString(),
            'processing_completed_at' => $v->processing_completed_at?->toISOString(),
            'processing_duration_ms' => $processingDurationMs,
            'healthy_at' => $v->healthy_at?->toISOString(),
            'healthy_at_human' => $v->healthy_at?->diffForHumans(),
            'reload_duration_ms' => $reloadDurationMs,
            'total_ready_ms' => $totalReadyMs,
            'activated_at' => $v->activated_at?->toISOString(),
            'activated_at_human' => $v->activated_at?->diffForHumans(),
            'created_at' => $v->created_at?->toISOString(),
            'created_at_human' => $v->created_at?->diffForHumans(),
        ];
    }

    /**
     * Build denormalized columns from an encrypt_meta document.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function denormalize(array $meta): array
    {
        $login = $meta['login'] ?? [];
        $reserve = $meta['reserve'] ?? [];

        return [
            'login_version' => $login['version'] ?? null,
            'login_skip' => $login['skip'] ?? null,
            'login_enc_len' => $login['enc_len'] ?? null,
            'login_secret' => $login['secret'] ?? null,
            'reserve_version' => $reserve['version'] ?? null,
            'reserve_skip' => $reserve['skip'] ?? null,
            'reserve_enc_len' => $reserve['enc_len'] ?? null,
            'reserve_secret' => $reserve['secret'] ?? null,
            'charset' => $meta['charset'] ?? null,
        ];
    }

    /**
     * Create-or-activate the captcha_transform_seed row matching a version's meta so the
     * monitor's seed comparison stays coherent with the active bundle.
     *
     * @param  array<string, mixed>|null  $m  the per-type meta block
     */
    private function restoreSeed(string $type, ?array $m): void
    {
        if (! $m || empty($m['secret'])) {
            return;
        }

        $existing = CaptchaTransformSeed::where('token_type', $type)->where('seed', $m['secret'])->first();
        if ($existing) {
            if (isset($m['skip'])) {
                $existing->offset = $m['skip'];
            }
            if (isset($m['enc_len'])) {
                $existing->length = $m['enc_len'];
            }
            $existing->save();
            $row = $existing;
        } else {
            $row = CaptchaTransformSeed::create([
                'token_type' => $type,
                'seed' => $m['secret'],
                'offset' => $m['skip'] ?? ($type === 'login' ? 4 : 2),
                'length' => $m['enc_len'] ?? ($type === 'login' ? 19 : 23),
                'is_active' => false,
            ]);
        }

        $row->activate();
    }

    private function atomicWrite(string $dest, string $contents): void
    {
        $tmp = $dest.'.tmp.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents);
        rename($tmp, $dest);
    }

    private function atomicCopy(string $src, string $dest): void
    {
        $tmp = $dest.'.tmp.'.bin2hex(random_bytes(4));
        copy($src, $tmp);
        rename($tmp, $dest);
    }
}
