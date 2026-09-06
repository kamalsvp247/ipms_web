<?php

namespace App\Services;

use App\Models\CaptchaAlgorithmSnapshot;
use App\Models\CaptchaBundleVersion;
use App\Models\CaptchaTransformSeed;
use App\Models\Setting;
use App\Services\Captcha\CaptchaBundleVersionService;
use App\Services\Captcha\LiveBundleClient;
use App\Services\IvacEdgeBundleFetcher;
use App\Support\CaptchaTokenTransformer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class CaptchaAlgorithmService
{
    private const PHP_FILE = 'app/Support/CaptchaTokenTransformer.php';

    /** Persisted flag surfaced as a banner on the monitor when auto-extraction is unsafe. */
    public const NEEDS_ATTENTION_CACHE_KEY = 'captcha:needs_attention';

    /**
     * Last bundle asset filename successfully extracted-and-applied. The scheduled
     * auto-refresh uses it as the cheap redeploy marker; advanced here (not by the
     * caller) so a manual "Run Analysis" and the cron share one source of truth and
     * a clean manual heal does not leave the cron seeing a stale "changed" asset.
     */
    public const BUNDLE_ASSET_CACHE_KEY = 'captcha:last_bundle_asset';

    /**
     * Cache-key version for the request-constants disk cache. Bump only when the cached SHAPE
     * changes — the key already carries the extractor script's own hash, so editing
     * extract_request_constants.cjs invalidates its entries without a bump here.
     */
    private const REQUEST_CONSTANTS_CACHE_VERSION = 'v1';

    public function __construct(
        private readonly LiveBundleClient $sidecar,
        private readonly CaptchaBundleVersionService $bundleVersions,
        private readonly IvacEdgeBundleFetcher $edgeFetcher,
    ) {}

    /**
     * Per-edge-IP discover/download attempts from the most recent runScript() call,
     * surfaced to the monitor UI as a small "which CF edge IP was fastest" log block.
     * Null when the edge-IP race was never attempted (script not found).
     *
     * @var array{ok: bool, notice_active: bool, message: ?string, edge_ip: ?string, discover_log: array, download_log: array}|null
     */
    private ?array $lastEdgeRaceResult = null;

    /**
     * Wall-clock timing for the edge-IP race download, captured in PHP because the
     * Python script skips its own network fetch when handed an already-downloaded
     * bundle via --bundle (the primary/fast fetch path). Null when that path was
     * not used (Python did its own download and reported its own timing instead).
     */
    private ?\Carbon\CarbonInterface $lastEdgeDownloadStartedAt = null;

    private ?int $lastEdgeDownloadDurationMs = null;

    /**
     * Memoized decoded output of app/Scripts/extract_request_constants.cjs (one Node run per
     * analyze() call), shared by syncRequestConstants() and syncEndpoints(). The ran-flag lets a
     * null result (extraction failed) be cached too, so the process is never spawned twice.
     * Across calls the same output is served from the content-addressed disk cache — see
     * extractBundleRequestData().
     *
     * @var array<string, mixed>|null
     */
    private ?array $extractedBundleData = null;

    private bool $extractedBundleDataRan = false;

    /**
     * Real https URL (with the content-hashed asset filename) of the bundle fetched by
     * the edge-IP race. The Python script only sees the local --bundle path and reports
     * a file:// URL, which would surface as "ivac-bundle.js" in the version list — so the
     * real asset name discovered by the edge fetcher is carried here and applied instead.
     */
    private ?string $lastEdgeBundleUrl = null;

    /**
     * Analyze the live IVAC site and compare with the PHP transformer + active seed.
     *
     * @param  string  $proxy  HTTP proxy URL (e.g. "http://host:port")
     * @return array Result containing bundle URL, algorithm signature, detected/local constants, matches
     */
    public function analyze(?string $proxy = null, ?callable $progressCallback = null): array
    {
        $proxy = $proxy ?? '';
        $phpConstants = $this->readPhpConstants();
        $reserveRow = CaptchaTransformSeed::activeForType('reserve');
        $loginRow = CaptchaTransformSeed::activeForType('login');

        // If the sidecar is already healthy and its loaded bundle matches the active DB
        // version, any existing needs-attention alarm is stale (the sidecar self-healed via
        // its mtime poller after a previous reload call timed out). Clear it now so the
        // operator is not shown a false alarm while analysis re-runs.
        $this->clearNeedsAttentionIfSidecarHealthy();

        $scriptResult = $this->runScript($proxy, $progressCallback);

        // The primary edge-IP fetch path downloads the bundle in PHP and hands it to
        // Python via --bundle, so Python never times its own (skipped) download. Fill
        // it in from the PHP-side timer so the monitor always has download stats
        // regardless of which path actually fetched the bundle.
        if (empty($scriptResult['download_started_at']) && $this->lastEdgeDownloadStartedAt !== null) {
            $scriptResult['download_started_at'] = $this->lastEdgeDownloadStartedAt->toIso8601String();
            $scriptResult['download_duration_ms'] = $this->lastEdgeDownloadDurationMs;
        }

        // On the edge-IP path Python only sees the local --bundle file, so it reports a
        // file:// URL that surfaces as "ivac-bundle.js". Restore the real content-hashed
        // asset URL discovered by the edge fetcher so the version list shows the true
        // filename (e.g. mr1q1yvy-CEp5vZQi.js) like every other row.
        if ($this->lastEdgeBundleUrl !== null
            && (empty($scriptResult['bundle_url']) || str_starts_with((string) $scriptResult['bundle_url'], 'file://'))) {
            $scriptResult['bundle_url'] = $this->lastEdgeBundleUrl;
        }

        if ($scriptResult['error']) {
            return array_merge($scriptResult, [
                'edge_ip_race' => $this->lastEdgeRaceResult,
                'php_offset' => $reserveRow?->offset ?? $phpConstants['reserve_skip'],
                'php_length' => $reserveRow?->length ?? $phpConstants['reserve_enc_len'],
                'php_charset' => $phpConstants['charset'],
                'active_seed' => $reserveRow?->seed,
                'active_login_seed' => $loginRow?->seed,
            ]);
        }

        // The Python script resolves every encrypt call site
        // (encrypt(token, SECRET, skip, encLen)) and returns the real secret
        // value for each. Attribute each site to login vs reserve by matching its
        // (skip, encLen) against the active DB rows.
        $callSites = $scriptResult['call_sites'] ?? [];
        $attributed = $this->attributeCallSites($callSites, $loginRow, $reserveRow);
        $reserveSite = $attributed['reserve'];
        $loginSite = $attributed['login'];

        // Fall back to the script's encrypt_meta when the secondary call-site
        // attribution cannot label a type. encrypt_meta is the ground-truth
        // attribution the Python analyzer already resolved (via _attribute_for_live)
        // and is exactly what the Live-JS sidecar loads and encrypts with. In the
        // identical-module case (login and reserve share module + (skip, enc_len)
        // and differ only by secret) the raw call-site labels can be missing, which
        // would otherwise raise a false "no call site attributed" structural alarm
        // even though the sidecar encrypts both types correctly. Trusting encrypt_meta
        // keeps PHP's view consistent with what actually performs encryption.
        $loginSite = $loginSite ?? $this->siteFromEncryptMeta($scriptResult, 'login');
        $reserveSite = $reserveSite ?? $this->siteFromEncryptMeta($scriptResult, 'reserve');

        // PHP vs JS reference cross-validation
        $implCheck = $scriptResult['impl_check'] ?? null;
        $testToken = $implCheck['test_token'] ?? null;
        $loginImplMatch = null;
        $reserveImplMatch = null;

        if ($testToken && $loginSite && isset($loginSite['secret']) && isset($implCheck['login'])) {
            $phpLoginOutput = CaptchaTokenTransformer::transformLogin(
                $testToken, $loginSite['secret'], $loginSite['skip'], $loginSite['enc_len']
            );
            $loginImplMatch = ($phpLoginOutput === $implCheck['login']);
        }

        if ($testToken && $reserveSite && isset($reserveSite['secret']) && isset($implCheck['reserve'])) {
            $phpReserveOutput = CaptchaTokenTransformer::transformReserve(
                $testToken, $reserveSite['secret'], $reserveSite['skip'], $reserveSite['enc_len']
            );
            $reserveImplMatch = ($phpReserveOutput === $implCheck['reserve']);
        }

        // RESERVE algorithm constants (LCG + Feistel)
        $detectedReserveSecret = $reserveSite['secret'] ?? null;
        $detectedCharset = $scriptResult['captcha_constants']['charset'] ?? null;
        $detectedOffset = $reserveSite['skip'] ?? $scriptResult['detected_offset'] ?? null;
        $detectedLength = $reserveSite['enc_len'] ?? $scriptResult['detected_length'] ?? null;

        // LOGIN algorithm constants (polynomial % 67)
        $detectedLoginSecret = $loginSite['secret'] ?? null;
        $detectedLoginSkip = $loginSite['skip'] ?? null;
        $detectedLoginEncLen = $loginSite['enc_len'] ?? null;

        // RESERVE comparisons
        $dbOffset = $reserveRow?->offset ?? $phpConstants['reserve_skip'];
        $dbLength = $reserveRow?->length ?? $phpConstants['reserve_enc_len'];
        $offsetMatch = $detectedOffset === null ? null : $dbOffset === $detectedOffset;
        $lengthMatch = $detectedLength === null ? null : $dbLength === $detectedLength;
        $charsetMatch = $detectedCharset === null ? null : $phpConstants['charset'] === $detectedCharset;
        $reserveSeedMatch = $detectedReserveSecret === null ? null : $reserveRow?->seed === $detectedReserveSecret;
        $reserveMagicMatch = $scriptResult['magic_numbers_match'] === true;

        // LOGIN comparisons (login shares the same CHARSET as reserve)
        $dbLoginSkip = $loginRow?->offset ?? $phpConstants['login_skip'];
        $dbLoginLength = $loginRow?->length ?? $phpConstants['login_enc_len'];
        $loginOffsetMatch = $detectedLoginSkip === null ? null : $dbLoginSkip === $detectedLoginSkip;
        $loginLengthMatch = $detectedLoginEncLen === null ? null : $dbLoginLength === $detectedLoginEncLen;
        $loginSeedMatch = $detectedLoginSecret === null ? null : $loginRow?->seed === $detectedLoginSecret;
        $loginMagicMatch = $scriptResult['login_magic_match'] ?? false;
        $loginFullyMatched = $loginOffsetMatch === true
            && $loginLengthMatch === true
            && $charsetMatch === true
            && $loginSeedMatch === true
            && $loginMagicMatch
            && $loginImplMatch !== false;

        // Fully matched only when both algorithms are confirmed correct
        $fullyMatched = $offsetMatch === true
            && $lengthMatch === true
            && $charsetMatch === true
            && $reserveSeedMatch === true
            && $reserveMagicMatch
            && $loginSeedMatch === true
            && $loginMagicMatch
            && $loginImplMatch !== false
            && $reserveImplMatch !== false;

        $snapshotData = [
            'bundle_url' => $scriptResult['bundle_url'],
            'bundle_filename' => basename(parse_url($scriptResult['bundle_url'] ?? '', PHP_URL_PATH) ?? ''),
            'offset' => $detectedOffset,
            'length' => $detectedLength,
            'magic_numbers_match' => $reserveMagicMatch,
            'login_magic_match' => $loginMagicMatch,
            'charset' => $detectedCharset,
            'secret' => $detectedReserveSecret,
            'login_secret' => $detectedLoginSecret,
            'skip' => $detectedOffset,
            'encrypt_len' => $detectedLength,
            'login_skip' => $detectedLoginSkip,
            'login_enc_len' => $detectedLoginEncLen,
            'js_function' => $scriptResult['js_function'] ?? null,
            'captcha_encryption' => $scriptResult['captcha_encryption'] ?? null,
        ];

        $snapshotResult = $this->saveSnapshot($snapshotData);

        $extractionAlarm = $this->detectExtractionAlarm(
            $callSites,
            $scriptResult['live_modules'] ?? null,
            $scriptResult['encrypt_meta'] ?? null,
            $loginSite,
            $reserveSite,
            $loginImplMatch,
            $reserveImplMatch,
            $scriptResult['dispatch_versions'] ?? [],
            $scriptResult['wellformed'] ?? null,
            $scriptResult['distinct_modules'] ?? null,
        );

        // Keep the DB seed auto-synced to the live bundle so encryption self-heals and
        // never needs a manual "Apply Seed". Only do this on a clean extraction (Python
        // published encrypt_meta for both types, no alarm, all detected values present);
        // otherwise keep the last-known-good config and raise a needs-attention alarm.
        $autoApply = $this->autoApplySeeds(
            $scriptResult,
            $extractionAlarm,
            $loginSite,
            $reserveSite,
            $detectedLoginSecret,
            $detectedLoginSkip,
            $detectedLoginEncLen,
            $detectedReserveSecret,
            $detectedOffset,
            $detectedLength,
        );

        // Advance the redeploy marker only on a clean apply, so the next scheduled run
        // short-circuits instead of redundantly re-analyzing what we just healed. An
        // unclean run intentionally leaves the marker stale so the cron retries it.
        if (($autoApply['applied'] ?? false) === true && ! empty($scriptResult['bundle_url'])) {
            Cache::forever(self::BUNDLE_ASSET_CACHE_KEY, basename((string) $scriptResult['bundle_url']));
        }

        // Register the downloaded bundle as an activatable version. A clean extraction
        // becomes the active version (newest auto-activates, as before); an unclean one
        // is stored for inspection but the last-good active pair is restored on disk so
        // python's bundle-overwrite never leaves a new-bundle/old-meta mismatch live.
        $this->registerBundleVersion($scriptResult, ($autoApply['applied'] ?? false) === true);

        // The reserve-slot ID is a deployment constant IVAC bakes into the bundle
        // (/slots/{uuid}/reserve-slot). Extract it and keep settings.reserve_slot_id in
        // sync so the bot always reserves against the current ID after a redeploy.
        $reserveSlotId = $this->syncReserveSlotId();

        // Payment config ID + reserve x-v-request-meta header value are also deployment constants
        // baked into the bundle, but obfuscated (not plain literals), so they are extracted by
        // executing the bundle rather than regex — best-effort, keeps last-known-good on failure.
        $requestConstants = $this->syncRequestConstants();

        // IVAC endpoint paths + sign-in nav-state header are also bundle-baked and rotate on redeploy;
        // sync them into settings.ivac_endpoints so the bot adopts a rotation via /api/config with no
        // Java edit or JAR rebuild. Reuses the same (memoized) extractor run as syncRequestConstants.
        $endpoints = $this->syncEndpoints();

        return [
            'bundle_url' => $scriptResult['bundle_url'],
            'reserve_slot_id' => $reserveSlotId,
            'request_constants' => $requestConstants,
            'endpoints' => $endpoints,
            // Bundle download timing (UTC ISO start + duration in ms; null on local recovery)
            'download_started_at' => $scriptResult['download_started_at'] ?? null,
            'download_duration_ms' => $scriptResult['download_duration_ms'] ?? null,
            // Algorithm presence
            'magic_numbers_match' => $reserveMagicMatch,
            'login_magic_match' => $loginMagicMatch,
            // RESERVE active DB values
            'php_offset' => $dbOffset,
            'php_length' => $dbLength,
            'php_charset' => $phpConstants['charset'],
            'active_seed' => $reserveRow?->seed,
            // LOGIN active DB values
            'active_login_seed' => $loginRow?->seed,
            'db_login_skip' => $loginRow?->offset,
            'db_login_enc_len' => $loginRow?->length,
            // RESERVE detected values
            'detected_offset' => $detectedOffset,
            'detected_length' => $detectedLength,
            'detected_charset' => $detectedCharset,
            'detected_secret' => $detectedReserveSecret,
            // LOGIN detected values
            'detected_login_secret' => $detectedLoginSecret,
            'detected_login_skip' => $detectedLoginSkip,
            'detected_login_enc_len' => $detectedLoginEncLen,
            // Code extract
            'js_function' => $scriptResult['js_function'] ?? null,
            'captcha_encryption' => $scriptResult['captcha_encryption'] ?? null,
            'captcha_constants' => [
                'charset' => $detectedCharset,
                'secret' => $detectedReserveSecret,
                'skip' => $detectedOffset,
                'encrypt_len' => $detectedLength,
            ],
            'call_sites' => $callSites,
            // RESERVE matches
            'offset_match' => $offsetMatch,
            'length_match' => $lengthMatch,
            'charset_match' => $charsetMatch,
            'seed_match' => $reserveSeedMatch,
            // LOGIN matches
            'login_offset_match' => $loginOffsetMatch,
            'login_length_match' => $loginLengthMatch,
            'login_charset_match' => $charsetMatch,
            'login_seed_match' => $loginSeedMatch,
            'login_fully_matched' => $loginFullyMatched,
            'fully_matched' => $fullyMatched,
            // Implementation cross-validation (PHP output vs LIVE bundle ground truth)
            'login_impl_match' => $loginImplMatch,
            'reserve_impl_match' => $reserveImplMatch,
            // Live ground truth + encryption engine status
            'live_login_output' => $implCheck['login'] ?? null,
            'live_reserve_output' => $implCheck['reserve'] ?? null,
            'encrypt_meta' => $scriptResult['encrypt_meta'] ?? null,
            'live_modules' => $scriptResult['live_modules'] ?? null,
            'engine' => $this->engineStatus(),
            // Stored bundle versions + currently active one (for the rollback UI).
            'bundle_versions' => $this->bundleVersions->summary(),
            // Extraction-failure alarm: distinguishes "bundle structure broke our
            // extraction" from a benign no-op so a redeploy can't look like nothing changed.
            'extraction_alarm' => $extractionAlarm,
            // Auto-apply outcome: whether the DB seed was synced to the live bundle, and
            // the persisted needs-attention flag for the monitor banner.
            'auto_applied' => $autoApply,
            'needs_attention' => $this->needsAttention(),
            'error' => null,
            'logs' => $scriptResult['logs'] ?? [],
            // Per-CF-edge-IP discover/download timings from the fastest-wins race, so the
            // UI can show which IP served the live bundle quickest on this run.
            'edge_ip_race' => $this->lastEdgeRaceResult,
            // History / change tracking
            'snapshot_status' => $snapshotResult['status'],
            'snapshot_id' => $snapshotResult['id'],
            'previous_snapshot' => $snapshotResult['previous'] ?? null,
            'changes' => $snapshotResult['changes'] ?? [],
            'history' => $snapshotResult['history'],
        ];
    }

    /**
     * Attribute resolved encrypt call sites to the login and reserve algorithms.
     *
     * Each call site is {var, skip, enc_len, secret}. Login and reserve share the
     * same call shape but differ in (skip, enc_len). Matching strategy:
     *   0. Component-context label set by the Python script ('dateLabel' marks reserve
     *      in 6/6 corpus bundles) — the only reliable signal.
     *   1. Exact (skip, enc_len) match against the active DB rows (belt-and-suspenders).
     *   2. Elimination only when exactly one type is labelled and one group remains.
     *
     * There is deliberately NO "larger enc_len = reserve" guess: it is provably wrong
     * (login enc_len has exceeded reserve's, and 9/19 has been login in one bundle and
     * reserve in others). When neither can be labelled both stay null so the caller
     * fails loud (needs-attention) rather than shipping a mis-encrypted token.
     *
     * @param  array<int, array{var: string, skip: int, enc_len: int, secret: string}>  $callSites
     * @return array{login: ?array, reserve: ?array}
     */
    private function attributeCallSites(array $callSites, ?CaptchaTransformSeed $loginRow, ?CaptchaTransformSeed $reserveRow): array
    {
        // Pass 0: collect labelled sites BEFORE deduplication so login and reserve can
        // share the same (skip, enc_len) pair (e.g. when IVAC uses the same algorithm
        // for both but with different secrets). Unlabelled sites are deduped by
        // (skip, enc_len) and form the elimination pool for passes 1 and 2.
        $login = null;
        $reserve = null;
        $unlabGroups = [];
        $unlabSeen = [];

        foreach ($callSites as $cs) {
            $alg = $cs['algorithm'] ?? null;
            if ($alg === 'login' && $login === null) {
                $login = $cs;
            } elseif ($alg === 'reserve' && $reserve === null) {
                $reserve = $cs;
            } else {
                $key = $cs['skip'].':'.$cs['enc_len'];
                if (! isset($unlabSeen[$key])) {
                    $unlabSeen[$key] = true;
                    $unlabGroups[] = $cs;
                }
            }
        }
        $groups = $unlabGroups;

        // Pass 1: exact (skip, enc_len) match against the DB rows
        foreach ($groups as $i => $g) {
            if ($reserve === null && $reserveRow && $g['skip'] === $reserveRow->offset && $g['enc_len'] === $reserveRow->length) {
                $reserve = $g;
                unset($groups[$i]);

                continue;
            }
            if ($login === null && $loginRow && $g['skip'] === $loginRow->offset && $g['enc_len'] === $loginRow->length) {
                $login = $g;
                unset($groups[$i]);
            }
        }
        $groups = array_values($groups);

        // Pass 2: elimination — safe ONLY when exactly one type is labelled and a
        // single unlabelled group remains (it is unambiguously the other type). If
        // neither is labelled we do NOT guess; both stay null and the caller alarms.
        if (count($groups) === 1) {
            if ($reserve === null && $login !== null) {
                $reserve = $groups[0];
            } elseif ($login === null && $reserve !== null) {
                $login = $groups[0];
            }
        }

        return ['login' => $login, 'reserve' => $reserve];
    }

    /**
     * Build a call-site dict for a token type from the analyzer's encrypt_meta.
     *
     * encrypt_meta is the per-type ground truth the Python analyzer resolved and the
     * Live-JS sidecar loads. It is used as the authoritative fallback when raw
     * call-site attribution cannot label a type. A type is only usable when the
     * analyzer mapped a module and resolved the real secret for it.
     *
     * @param  array<string, mixed>  $scriptResult
     * @return array{algorithm: string, skip: int|null, enc_len: int|null, version: int|null, module: string|null, secret: string}|null
     */
    private function siteFromEncryptMeta(array $scriptResult, string $type): ?array
    {
        $meta = $scriptResult['encrypt_meta'][$type] ?? null;

        if (! is_array($meta) || ($meta['module'] ?? null) === null || empty($meta['secret'])) {
            return null;
        }

        return [
            'algorithm' => $type,
            'skip' => $meta['skip'] ?? null,
            'enc_len' => $meta['enc_len'] ?? null,
            'version' => $meta['version'] ?? null,
            'module' => $meta['module'] ?? null,
            'secret' => $meta['secret'],
        ];
    }

    /**
     * Detect whether the live-bundle extraction itself failed, as opposed to the
     * algorithm simply being unchanged.
     *
     * The live_js engine and the ground-truth check rely on the analyzer being able
     * to (a) expose encrypt modules from the bundle, (b) resolve the encrypt call
     * sites/secrets, (c) map each token type to a module, and (d) run that module to
     * produce a ground-truth output. When IVAC changes the bundle's *structure*
     * (module-emission shape, version dispatch table, config-literal shape, etc.)
     * one or more of these silently yield null/empty — which previously looked like a
     * harmless "new"/"changed" snapshot. This surfaces those as an explicit alarm so a
     * structural regression can never masquerade as a no-op.
     *
     * A special, non-structural case is "mid-rollout": IVAC's config selects a
     * captcha version whose module is not shipped in the downloaded bundle yet
     * (the dispatch table tops out below it). That is not our parser breaking — the
     * site itself cannot encrypt that token type from this bundle — so it is reported
     * with severity 'rollout' (keep last-known-good, re-run later) rather than the
     * red 'structural' banner that implies our extraction code needs a re-port.
     *
     * @param  array<int, array<string, mixed>>  $callSites
     * @param  array<int, string>|null  $liveModules  module vars exposed from the live bundle
     * @param  array<string, mixed>|null  $encryptMeta  per-type module/version mapping
     * @param  array<string, mixed>|null  $loginSite
     * @param  array<string, mixed>|null  $reserveSite
     * @param  array<int, int>  $dispatchVersions  versions the bundle dispatch table can load
     * @param  array{login?: bool, reserve?: bool}|null  $wellformed  live-output canary per type
     * @param  bool|null  $distinctModules  false when login/reserve resolved to the same module
     * @return array{triggered: bool, severity: string, issues: array<int, string>, pending_rollout: array<int, string>, unaffected: array<int, string>}
     */
    private function detectExtractionAlarm(
        array $callSites,
        ?array $liveModules,
        ?array $encryptMeta,
        ?array $loginSite,
        ?array $reserveSite,
        ?bool $loginImplMatch,
        ?bool $reserveImplMatch,
        array $dispatchVersions = [],
        ?array $wellformed = null,
        ?bool $distinctModules = null,
    ): array {
        $issues = [];
        $structural = false;
        $pendingRollout = [];
        $unaffected = [];

        // Canary: the two token types must resolve to distinct modules. The same
        // module for two different versions means attribution/dispatch is inconsistent.
        if ($distinctModules === false) {
            $issues[] = 'Login and reserve resolved to the same encrypt module with different versions — attribution or version dispatch is inconsistent. Encryption is unsafe until this is resolved.';
            $structural = true;
        }

        if (empty($liveModules)) {
            $issues[] = 'No encrypt modules were exposed from the live bundle — its module-emission shape likely changed. The Live-JS engine cannot run until this is fixed.';
            $structural = true;
        }

        if (empty($callSites)) {
            $issues[] = 'No encrypt call sites were resolved from the bundle — secret/offset extraction failed (obfuscation or call-shape change).';
            $structural = true;
        }

        $range = $dispatchVersions !== []
            ? min($dispatchVersions).'–'.max($dispatchVersions)
            : 'the shipped set';

        $types = [
            'login' => [$loginSite, $loginImplMatch],
            'reserve' => [$reserveSite, $reserveImplMatch],
        ];

        foreach ($types as $type => [$site, $implMatch]) {
            $label = ucfirst($type);

            if ($site === null) {
                $issues[] = "{$label}: no encrypt call site could be attributed — algorithm extraction failed for this token type.";
                $structural = true;

                continue;
            }

            $version = $encryptMeta[$type]['version'] ?? null;
            $module = $encryptMeta[$type]['module'] ?? null;

            // Mid-rollout: the config picked a version the bundle has no module for.
            // This is IVAC's deploy in flight, not our extraction breaking.
            if ($version !== null && $module === null
                && $dispatchVersions !== [] && ! in_array($version, $dispatchVersions, true)) {
                $pendingRollout[] = $type;
                $issues[] = "{$label}: IVAC's bundle now selects captcha version {$version}, but this bundle ships only versions {$range}. The new {$type} module has not been deployed yet — IVAC appears to be mid-rollout. Last-known-good {$type} encryption is retained; re-run Analysis after IVAC finishes deploying.";

                continue;
            }

            if ($implMatch === null) {
                $issues[] = "{$label}: the live bundle's ground-truth output could not be computed (version-to-module dispatch or config extraction failed), so PHP cannot be verified against the live code.";
                $structural = true;
            }

            if ($module === null) {
                $issues[] = "{$label}: no module is mapped in encrypt_meta — the Live-JS sidecar has no module for this token type.";
                $structural = true;

                continue;
            }

            // Canary: the live module ran but produced a malformed token (wrong length,
            // altered prefix/suffix, or non-charset chars). Possible anti-headless
            // poisoning or a wrong module — refuse to treat this type as healthy.
            if (is_array($wellformed) && ($wellformed[$type] ?? null) === false) {
                $issues[] = "{$label}: the live module produced a malformed output (failed the well-formedness canary) — encryption is unsafe for this token type.";
                $structural = true;

                continue;
            }

            // This type extracted cleanly (module mapped, ground truth computable).
            if ($implMatch !== null) {
                $unaffected[] = $type;
            }
        }

        $severity = $structural
            ? 'structural'
            : (count($pendingRollout) > 0 ? 'rollout' : 'ok');

        return [
            'triggered' => count($issues) > 0,
            'severity' => $severity,
            'issues' => $issues,
            'pending_rollout' => $pendingRollout,
            'unaffected' => $unaffected,
        ];
    }

    /**
     * Insert a new seed row and activate it (deactivating others).
     * When offset/length are provided they are stored on the row (new or existing).
     *
     * @return array{success: bool, message: string, seed_id?: int}
     */
    public function updateActiveSeed(string $seed, string $tokenType = 'reserve', ?int $skip = null, ?int $encLen = null): array
    {
        $seed = trim($seed);

        if ($seed === '') {
            return ['success' => false, 'message' => 'Seed cannot be empty.'];
        }

        $defaults = $tokenType === 'login'
            ? ['offset' => 4, 'length' => 26]
            : ['offset' => 4, 'length' => 26];

        $existing = CaptchaTransformSeed::where('token_type', $tokenType)->where('seed', $seed)->first();

        if ($existing) {
            if ($skip !== null) {
                $existing->offset = $skip;
            }
            if ($encLen !== null) {
                $existing->length = $encLen;
            }
            $existing->save();
            $row = $existing;
        } else {
            $row = CaptchaTransformSeed::create([
                'token_type' => $tokenType,
                'seed' => $seed,
                'offset' => $skip ?? $defaults['offset'],
                'length' => $encLen ?? $defaults['length'],
                'is_active' => false,
            ]);
        }

        $row->activate();

        return [
            'success' => true,
            'message' => $existing
                ? 'Existing seed row activated.'
                : 'New seed row created and activated.',
            'seed_id' => $row->id,
        ];
    }

    /**
     * Sync the active DB seeds to the freshly extracted live values when the extraction
     * is clean, so encryption self-heals with no manual "Apply Seed". On an unclean
     * extraction the seeds (and meta) are left as last-known-good and a needs-attention
     * alarm is raised instead.
     *
     * @param  array<string, mixed>  $scriptResult  parsed analyzer output
     * @param  array{triggered: bool, issues: array<int, string>}  $extractionAlarm
     * @return array{applied: bool, reason: ?string, types: array<int, string>}
     */
    private function autoApplySeeds(
        array $scriptResult,
        array $extractionAlarm,
        ?array $loginSite,
        ?array $reserveSite,
        ?string $loginSecret,
        ?int $loginSkip,
        ?int $loginEncLen,
        ?string $reserveSecret,
        ?int $reserveSkip,
        ?int $reserveEncLen,
    ): array {
        $metaPublished = ($scriptResult['extraction_ok'] ?? false) === true;
        $loginReady = $loginSecret !== null && $loginSkip !== null && $loginEncLen !== null;
        $reserveReady = $reserveSecret !== null && $reserveSkip !== null && $reserveEncLen !== null;

        if (! $metaPublished || $extractionAlarm['triggered'] || ! $loginReady || ! $reserveReady) {
            $reason = $extractionAlarm['triggered']
                ? implode(' ', $extractionAlarm['issues'])
                : ($scriptResult['extraction_reason'] ?? 'incomplete extraction — login or reserve could not be fully resolved');
            $this->raiseNeedsAttention($reason);

            return ['applied' => false, 'reason' => $reason, 'types' => []];
        }

        $this->updateActiveSeed($loginSecret, 'login', $loginSkip, $loginEncLen);
        $this->updateActiveSeed($reserveSecret, 'reserve', $reserveSkip, $reserveEncLen);
        $this->clearNeedsAttention();

        return ['applied' => true, 'reason' => null, 'types' => ['login', 'reserve']];
    }

    /**
     * Store the just-downloaded bundle as a version and either activate it (clean
     * extraction) or keep the last-good active pair on disk (unclean extraction).
     *
     * @param  array<string, mixed>  $scriptResult
     */
    /**
     * Extract the reserve-slot ID constant from the downloaded bundle and update
     * settings.reserve_slot_id when it differs. IVAC hardcodes it as
     * "/slots/{uuid}/reserve-slot" and rotates it on redeploy.
     *
     * @return array{detected: ?string, previous: ?string, changed: bool}
     */
    private function syncReserveSlotId(): array
    {
        // `previous` is filled up-front, not only on a successful match: when extraction
        // finds nothing the stored value is still what the bot uses, and the monitor has to
        // show it rather than an empty cell.
        $setting = Setting::instance();
        $previous = $setting->reserve_slot_id;
        $result = ['detected' => null, 'previous' => $previous, 'changed' => false];

        $bundlePath = rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/').'/ivac-bundle.js';
        if (! is_file($bundlePath)) {
            return $result;
        }

        $contents = @file_get_contents($bundlePath);
        if ($contents === false || $contents === '') {
            return $result;
        }

        if (! preg_match('#/slots/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/reserve-slot#i', $contents, $matches)) {
            return $result;
        }

        $detected = $matches[1];
        $result['detected'] = $detected;

        if ($previous !== $detected) {
            $setting->update(['reserve_slot_id' => $detected]);
            $result['changed'] = true;
            Log::info('[CaptchaAlgorithm] reserve_slot_id synced from bundle', [
                'previous' => $previous,
                'detected' => $detected,
            ]);
        }

        return $result;
    }

    /**
     * Extract the deployment-scoped request constants IVAC bakes into the bundle and keep the
     * matching settings columns in sync (like syncReserveSlotId, but these values are RC4-obfuscated
     * in the bundle rather than plain literals, so they cannot be regex-matched). A small Node script
     * executes the bundle's own builder functions and reports:
     *   payment_config_id    — the UUID in POST /payment/{id}/dg-epay/initiate
     *   reserve_request_meta — the x-v-request-meta header value on POST .../reserve-slot
     * Best-effort: any failure leaves the last-known-good values untouched (the bot keeps using the
     * current config), so a brittle extraction never blanks a working constant.
     *
     * @return array{payment_config_id: array{detected: ?string, previous: ?string, changed: bool}, reserve_request_meta: array{detected: ?string, previous: ?string, changed: bool}}
     */
    private function syncRequestConstants(): array
    {
        // Seeded with the stored values so a key these obfuscated constants could not be
        // extracted from still reports what the bot is actually using — an empty cell would
        // read as "we have nothing" when in fact the last-known-good value is in force.
        $setting = Setting::instance();
        $result = [
            'payment_config_id' => ['detected' => null, 'previous' => $setting->payment_config_id, 'changed' => false],
            'reserve_request_meta' => ['detected' => null, 'previous' => $setting->reserve_request_meta, 'changed' => false],
        ];

        $data = $this->extractBundleRequestData();
        if ($data === null) {
            return $result;
        }

        try {
            $map = [
                'payment_config_id' => $data['paymentConfigId'] ?? null,
                'reserve_request_meta' => $data['reserveRequestMeta'] ?? null,
            ];

            $updates = [];
            foreach ($map as $column => $detected) {
                if (! is_string($detected) || $detected === '') {
                    continue;
                }
                $previous = $setting->{$column};
                $result[$column]['detected'] = $detected;
                if ($previous !== $detected) {
                    $updates[$column] = $detected;
                    $result[$column]['changed'] = true;
                }
            }

            if ($updates !== []) {
                $setting->update($updates);
                Log::info('[CaptchaAlgorithm] request constants synced from bundle', $updates);
            }
        } catch (\Throwable $e) {
            Log::warning('[CaptchaAlgorithm] request-constants sync threw', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Run app/Scripts/extract_request_constants.cjs once per analyze() call and memoize the decoded
     * JSON so syncRequestConstants() and syncEndpoints() share a single Node process. Returns null on
     * any failure (missing bundle/script, non-zero exit, unparseable or ok=false output) — callers
     * then keep their last-known-good values.
     *
     * The Node run is a cold eval of the whole ~2 MB bundle (~3.3s) and its output is a pure
     * function of (bundle, extractor), so a successful extraction is also cached on disk beside
     * the Python analyzer's own probe cache. An unchanged bundle then costs a file read instead
     * of a second full bundle eval, which is the normal state between IVAC redeploys.
     *
     * @return array<string, mixed>|null
     */
    private function extractBundleRequestData(): ?array
    {
        if ($this->extractedBundleDataRan) {
            return $this->extractedBundleData;
        }
        $this->extractedBundleDataRan = true;

        $bundlePath = rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/').'/ivac-bundle.js';
        $scriptPath = base_path('app/Scripts/extract_request_constants.cjs');
        if (! is_file($bundlePath) || ! is_file($scriptPath)) {
            return null;
        }

        $cacheFile = $this->requestConstantsCachePath($bundlePath, $scriptPath);
        $cached = $this->loadRequestConstantsCache($cacheFile);
        if ($cached !== null) {
            return $this->extractedBundleData = $cached;
        }

        try {
            $process = new Process(['node', $scriptPath, $bundlePath]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('[CaptchaAlgorithm] request-constants extractor failed', [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => substr($process->getErrorOutput(), 0, 500),
                ]);

                return null;
            }

            $data = json_decode(trim($process->getOutput()), true);
            if (! is_array($data) || ($data['ok'] ?? false) !== true) {
                Log::warning('[CaptchaAlgorithm] request-constants extractor returned no data', [
                    'reason' => is_array($data) ? ($data['reason'] ?? 'unknown') : 'unparseable',
                ]);

                return null;
            }

            $this->storeRequestConstantsCache($cacheFile, $data);

            return $this->extractedBundleData = $data;
        } catch (\Throwable $e) {
            Log::warning('[CaptchaAlgorithm] request-constants extraction threw', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Content-addressed cache path for one (bundle, extractor) pair, in the same analysis_cache
     * directory the Python analyzer prunes — so this cache is bounded by the existing pruner
     * rather than a second one that could race it.
     *
     * The key hashes the bundle BODY with the analyzer's own "// source:" header lines stripped:
     * dump_bundle() prepends that header on the proxy path but not on the edge path, so hashing
     * raw bytes would key one deployed bundle two different ways. It also carries the extractor
     * script's hash, so editing extract_request_constants.cjs invalidates every entry on its own
     * and a fixed extractor can never be handed its own pre-fix output.
     *
     * Returns null when the bundle cannot be read, which disables caching for this run.
     */
    private function requestConstantsCachePath(string $bundlePath, string $scriptPath): ?string
    {
        $contents = @file_get_contents($bundlePath);
        if ($contents === false || $contents === '') {
            return null;
        }

        while (str_starts_with($contents, '// source:')) {
            $newline = strpos($contents, "\n");
            if ($newline === false) {
                break;
            }
            $contents = substr($contents, $newline + 1);
        }

        $dir = rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/').'/analysis_cache';
        $key = sprintf(
            'reqconst_%s_%s_%s',
            self::REQUEST_CONSTANTS_CACHE_VERSION,
            hash('sha256', $contents),
            substr((string) hash_file('sha256', $scriptPath), 0, 12)
        );

        return $dir.'/'.$key.'.json';
    }

    /**
     * Read a cached extraction. Only a well-formed ok=true payload is served — a failed or
     * truncated extraction is never cached, so a transient Node failure cannot become sticky.
     *
     * @return array<string, mixed>|null
     */
    private function loadRequestConstantsCache(?string $cacheFile): ?array
    {
        if ($cacheFile === null || ! is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($cacheFile), true);

        return is_array($decoded) && ($decoded['ok'] ?? false) === true ? $decoded : null;
    }

    /**
     * Best-effort atomic cache write. Group-writable so an artisan run as root cannot lock the
     * www-data web path out of refreshing the entry later.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeRequestConstantsCache(?string $cacheFile, array $data): void
    {
        if ($cacheFile === null) {
            return;
        }

        $dir = dirname($cacheFile);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        $tmp = $cacheFile.'.tmp.'.getmypid();
        if (@file_put_contents($tmp, json_encode($data)) === false) {
            return;
        }

        @chmod($tmp, 0664);
        if (! @rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }

    /**
     * Extract the IVAC endpoint paths + sign-in nav-state header from the bundle and merge them into
     * settings.ivac_endpoints so the bot picks up a path/header rotation from /api/config with no Java
     * edit or JAR rebuild. Like syncRequestConstants: best-effort and last-known-good — a key is only
     * overwritten when the extractor emits a well-formed value, so a brittle extraction never blanks a
     * working path. sendOtp and uploadRuntimeState are not headless-extractable (obfuscated
     * component-local concats), so the extractor omits them and they keep their seeded default / manual
     * override untouched.
     *
     * @return array{changed: array<string, string>, detected_count: int}
     */
    private function syncEndpoints(): array
    {
        // `effective` is the full set the bot will receive over /api/config — the merge of
        // whatever this bundle yielded over the stored values. Reported alongside `detected`
        // so the monitor can show every endpoint and mark which ones this bundle confirmed
        // (sendOtp and the runtime-state header are never headless-extractable).
        $setting = Setting::instance();
        $result = [
            'changed' => [],
            'detected' => [],
            'detected_count' => 0,
            'effective' => $setting->ivacEndpointsWithDefaults(),
        ];

        $data = $this->extractBundleRequestData();
        if ($data === null || ! isset($data['endpoints']) || ! is_array($data['endpoints'])) {
            return $result;
        }

        $detected = $data['endpoints'];
        $result['detected'] = $detected;
        $result['detected_count'] = count($detected);

        try {
            $current = is_array($setting->ivac_endpoints) ? $setting->ivac_endpoints : [];
            $merged = $current;
            $changed = [];

            foreach ($detected as $key => $value) {
                if (! is_string($key) || ! is_string($value) || $value === '') {
                    continue;
                }
                // Paths must start with "/"; the nav-state header is a bare UUID.
                if ($key !== 'signinNavState' && $value[0] !== '/') {
                    continue;
                }
                if (($current[$key] ?? null) !== $value) {
                    $merged[$key] = $value;
                    $changed[$key] = $value;
                }
            }

            if ($changed !== []) {
                $setting->update(['ivac_endpoints' => $merged]);
                Log::info('[CaptchaAlgorithm] IVAC endpoints synced from bundle', $changed);
            }

            $result['changed'] = $changed;
            $result['effective'] = $setting->fresh()->ivacEndpointsWithDefaults();
        } catch (\Throwable $e) {
            Log::warning('[CaptchaAlgorithm] endpoints sync threw', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    private function registerBundleVersion(array $scriptResult, bool $clean): void
    {
        $bundlePath = rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/').'/ivac-bundle.js';
        if (! is_file($bundlePath)) {
            return;
        }

        $meta = $scriptResult['encrypt_meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];
        $bundleUrl = $scriptResult['bundle_url'] ?? null;

        // Processing = everything after the download completed (rest of the Python
        // analysis, site attribution, PHP/JS cross-checks, this registration) up to now.
        // Derive it from the precise in-memory download-complete instant rather than the
        // second-resolution timestamp columns, so the phase durations are accurate to ms.
        $now = now();
        $downloadCompletedAt = null;
        if ($this->lastEdgeDownloadStartedAt !== null) {
            $downloadCompletedAt = $this->lastEdgeDownloadStartedAt->copy()
                ->addMilliseconds($this->lastEdgeDownloadDurationMs ?? 0);
        } elseif (! empty($scriptResult['download_started_at'])) {
            $downloadCompletedAt = \Illuminate\Support\Carbon::parse($scriptResult['download_started_at'])
                ->addMilliseconds((int) ($scriptResult['download_duration_ms'] ?? 0));
        }

        $timing = [
            'download_started_at' => $scriptResult['download_started_at'] ?? null,
            'download_duration_ms' => $scriptResult['download_duration_ms'] ?? null,
            'processing_completed_at' => $now,
            'processing_duration_ms' => $downloadCompletedAt !== null
                ? (int) round($downloadCompletedAt->diffInMilliseconds($now))
                : null,
        ];

        $version = $this->bundleVersions->register($bundlePath, $meta, $bundleUrl, $clean, $timing);

        if ($clean) {
            $result = $this->bundleVersions->activate($version);
            $this->bundleVersions->pruneToLimit();

            // The bundle+meta are committed to disk; if the sidecar did not reload, the
            // bot keeps encrypting with the previous bundle. Flag it so the operator sees
            // a stale sidecar instead of silent wrong-token submissions.
            if (($result['sidecar_reloaded'] ?? true) === false) {
                $this->raiseNeedsAttention('Sidecar reload failed after activating the new bundle; encryption may serve the previous bundle until it reloads.');
            }

            return;
        }

        // Unclean: python overwrote ivac-bundle.js with the new (broken) bundle but kept
        // last-known-good encrypt_meta.json. Restore the active version's bundle so the
        // pair stays consistent; the broken bundle remains registered for inspection.
        // (Needs-attention for the extraction failure itself is raised by the alarm path.)
        $this->bundleVersions->reconcileActiveFiles();
    }

    /**
     * Current needs-attention alarm payload, or null when clear.
     *
     * @return array<string, mixed>|null
     */
    public function needsAttention(): ?array
    {
        return Cache::get(self::NEEDS_ATTENTION_CACHE_KEY);
    }

    private function clearNeedsAttentionIfSidecarHealthy(): void
    {
        if (Cache::get(self::NEEDS_ATTENTION_CACHE_KEY) === null) {
            return;
        }

        $health = $this->sidecar->health();
        if (! $health || ! ($health['ok'] ?? false)) {
            return;
        }

        $active = CaptchaBundleVersion::active()->first();
        if ($active && ($health['bundle_hash'] ?? null) === $active->bundle_hash) {
            $this->clearNeedsAttention();
        }
    }

    private function raiseNeedsAttention(string $reason): void
    {
        $payload = ['reason' => $reason, 'at' => now()->toIso8601String()];
        Cache::forever(self::NEEDS_ATTENTION_CACHE_KEY, $payload);
        Log::warning('Captcha auto-extraction needs attention', $payload);
    }

    private function clearNeedsAttention(): void
    {
        Cache::forget(self::NEEDS_ATTENTION_CACHE_KEY);
    }

    /**
     * Current encryption-engine status for the monitor UI.
     *
     * @return array<string, mixed>
     */
    public function engineStatus(): array
    {
        $health = $this->sidecar->health();
        $bundleHash = $health['bundle_hash'] ?? null;

        // The sidecar reports when it loaded the bundle into memory; the monitor wants
        // the bundle's download (created_at) time, so resolve it from the stored version
        // whose content hash matches what the sidecar currently has loaded.
        $bundleCreatedAt = $bundleHash
            ? CaptchaBundleVersion::where('bundle_hash', $bundleHash)->value('created_at')
            : null;

        return [
            'bd_proxy_url' => Setting::instance()->captcha_bd_proxy_url,
            'needs_attention' => $this->needsAttention(),
            'sidecar' => [
                'url' => config('captcha.sidecar.url'),
                'healthy' => $health !== null && ($health['ok'] ?? false),
                'bundle_hash' => $bundleHash,
                'modules' => $health['modules'] ?? null,
                'meta' => $health['meta'] ?? null,
                'loaded_at' => $health['loaded_at'] ?? null,
                'bundle_created_at' => $bundleCreatedAt?->toISOString(),
            ],
        ];
    }

    /**
     * Ask the live-bundle sidecar to reload, returning its fresh health.
     *
     * @return array<string, mixed>
     */
    public function reloadSidecar(): array
    {
        if (! $this->sidecar->reload()) {
            $this->raiseNeedsAttention('Manual sidecar reload failed; encryption may serve a stale bundle until the sidecar recovers.');
        }

        return $this->engineStatus()['sidecar'];
    }

    /**
     * Get snapshot history for the monitor page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(): array
    {
        return CaptchaAlgorithmSnapshot::history()
            ->map(fn (CaptchaAlgorithmSnapshot $s) => $this->serializeSnapshot($s))
            ->values()
            ->all();
    }

    /**
     * Delete a snapshot by ID.
     *
     * @throws \Exception if snapshot not found
     */
    public function deleteSnapshot(int $id): void
    {
        $snapshot = CaptchaAlgorithmSnapshot::find($id);
        if (! $snapshot) {
            throw new \Exception('Snapshot not found');
        }
        $snapshot->delete();
    }

    /**
     * Delete all snapshots.
     *
     * @return int number of deleted rows
     */
    public function clearHistory(): int
    {
        return CaptchaAlgorithmSnapshot::query()->delete();
    }

    /**
     * Save a snapshot and compute status (new, duplicate, changed).
     *
     * @return array{status: string, id: int, previous: array|null, changes: array, history: array}
     */
    private function saveSnapshot(array $data): array
    {
        $fingerprint = CaptchaAlgorithmSnapshot::computeFingerprint($data);

        $existing = CaptchaAlgorithmSnapshot::query()
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('created_at')
            ->first();

        $latest = CaptchaAlgorithmSnapshot::query()
            ->orderByDesc('created_at')
            ->first();

        $history = CaptchaAlgorithmSnapshot::history()
            ->map(fn (CaptchaAlgorithmSnapshot $s) => $this->serializeSnapshot($s))
            ->values()
            ->all();

        if ($existing) {
            return [
                'status' => 'duplicate',
                'id' => $existing->id,
                'previous' => null,
                'changes' => [],
                'history' => $history,
            ];
        }

        $changes = $latest ? $latest->diffAgainst($data) : [];

        $snapshot = CaptchaAlgorithmSnapshot::create(array_merge($data, [
            'fingerprint' => $fingerprint,
        ]));

        $history = CaptchaAlgorithmSnapshot::history()
            ->map(fn (CaptchaAlgorithmSnapshot $s) => $this->serializeSnapshot($s))
            ->values()
            ->all();

        return [
            'status' => $latest && count($changes) > 0 ? 'changed' : 'new',
            'id' => $snapshot->id,
            'previous' => $latest ? $this->serializeSnapshot($latest) : null,
            'changes' => $changes,
            'history' => $history,
        ];
    }

    /**
     * Serialize a snapshot to an array for JSON output.
     *
     * @return array<string, mixed>
     */
    private function serializeSnapshot(CaptchaAlgorithmSnapshot $s): array
    {
        return [
            'id' => $s->id,
            'bundle_url' => $s->bundle_url,
            'bundle_filename' => $s->bundle_filename,
            'offset' => $s->offset,
            'length' => $s->length,
            'magic_numbers_match' => $s->magic_numbers_match,
            'login_magic_match' => $s->login_magic_match,
            'charset' => $s->charset,
            'secret' => $s->secret,
            'login_secret' => $s->login_secret,
            'skip' => $s->skip,
            'encrypt_len' => $s->encrypt_len,
            'login_skip' => $s->login_skip,
            'login_enc_len' => $s->login_enc_len,
            'fingerprint' => $s->fingerprint,
            'created_at' => $s->created_at?->toISOString(),
            'created_at_human' => $s->created_at?->diffForHumans(),
        ];
    }

    /**
     * Read per-algorithm skip/encLen constants and the shared charset from the PHP transformer.
     *
     * @return array{reserve_skip: int|null, reserve_enc_len: int|null, login_skip: int|null, login_enc_len: int|null, charset: string|null}
     */
    private function readPhpConstants(): array
    {
        $filePath = base_path(self::PHP_FILE);

        if (! file_exists($filePath)) {
            return ['reserve_skip' => null, 'reserve_enc_len' => null, 'login_skip' => null, 'login_enc_len' => null, 'charset' => null];
        }

        try {
            $content = file_get_contents($filePath);

            $reserveSkip   = preg_match('/private const RESERVE_SKIP = (\d+);/', $content, $m) ? (int) $m[1] : null;
            $reserveEncLen = preg_match('/private const RESERVE_ENC_LEN = (\d+);/', $content, $m) ? (int) $m[1] : null;
            $loginSkip     = preg_match('/private const LOGIN_SKIP = (\d+);/', $content, $m) ? (int) $m[1] : null;
            $loginEncLen   = preg_match('/private const LOGIN_ENC_LEN = (\d+);/', $content, $m) ? (int) $m[1] : null;
            $charset       = preg_match("/private const (?:CHARSET|ALPHABET) = '([^']+)';/", $content, $m) ? $m[1] : null;

            return [
                'reserve_skip'   => $reserveSkip,
                'reserve_enc_len' => $reserveEncLen,
                'login_skip'     => $loginSkip,
                'login_enc_len'  => $loginEncLen,
                'charset'        => $charset,
            ];
        } catch (\Exception) {
            return ['reserve_skip' => null, 'reserve_enc_len' => null, 'login_skip' => null, 'login_enc_len' => null, 'charset' => null];
        }
    }

    /**
     * Run the Python analysis script.
     *
     * @param  string  $proxy  HTTP proxy URL
     * @return array Script output (parsed JSON)
     */
    protected function runScript(string $proxy, ?callable $progressCallback = null): array
    {
        $scriptPath = base_path('app/Scripts/analyze_captcha_algo.py');

        if (! file_exists($scriptPath)) {
            return ['error' => 'Python script not found'];
        }

        $edgeBundlePath = $this->tryFetchBundleViaEdgeIps($progressCallback);

        if ($edgeBundlePath !== null) {
            $args = ['python3', $scriptPath, '-', '--bundle', $edgeBundlePath];
        } elseif ($proxy !== '') {
            // Only retry via the Python script (BD proxy) when the caller explicitly
            // selected + supplied one. In direct mode $proxy is '' and the Python
            // script would just make the same request from the same server IP that
            // already failed the edge-IP race — a guaranteed repeat failure, not a
            // real fallback.
            $args = ['python3', $scriptPath, $proxy];
        } else {
            if ($progressCallback !== null) {
                ($progressCallback)(['[edge] No BD proxy selected — not retrying (direct mode would just repeat the same failure from this server).']);
            }

            return [
                'error' => $this->lastEdgeRaceResult['message'] ?? 'Edge-IP race failed and no BD proxy was selected.',
                'logs' => [],
            ];
        }

        try {
            $process = new Process($args);
            $process->setTimeout(180);
            $process->setIdleTimeout(60);

            $outputBuffer = '';
            $process->run(function ($type, $buffer) use (&$outputBuffer, $progressCallback) {
                $outputBuffer .= $buffer;
                if ($progressCallback !== null) {
                    $lines = array_values(array_filter(explode("\n", $outputBuffer)));
                    ($progressCallback)($lines);
                }
            });

            if (! $process->isSuccessful()) {
                return [
                    'error' => 'Python script failed: '.$process->getErrorOutput(),
                    'logs' => array_filter(explode("\n", $outputBuffer)),
                ];
            }

            $output = trim($outputBuffer ?: $process->getOutput());
            $result = json_decode($output, true);

            if (! is_array($result)) {
                return [
                    'error' => 'Invalid Python script output',
                    'logs' => array_filter(explode("\n", $output)),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => 'Error running Python script: '.$e->getMessage()];
        }
    }

    /**
     * Fetch the bundle straight from a Cloudflare edge IP before falling back to the
     * BD-proxy fetch. Bypasses the proxy entirely on success — .js assets are
     * un-challenged from any CF edge IP, so this is both faster and more reliable
     * than depending on a single external proxy server.
     *
     * @return string|null Path to the downloaded bundle on disk, or null to fall back to the proxy.
     */
    private function tryFetchBundleViaEdgeIps(?callable $progressCallback): ?string
    {
        if ($progressCallback !== null) {
            ($progressCallback)(['[edge] Fetching the live bundle from Cloudflare...']);
        }

        $downloadStartedAt = now();
        $result = $this->edgeFetcher->fetchFastest(fn (string $asset): ?string => $this->archivedBundlePath($asset));
        $this->lastEdgeRaceResult = [
            'ok' => $result['ok'],
            'notice_active' => $result['notice_active'],
            'message' => $result['message'],
            'edge_ip' => $result['edge_ip'],
            'strategy' => $result['strategy'] ?? 'race',
            'discover_log' => $result['discover_log'],
            'download_log' => $result['download_log'],
        ];

        if (! $result['ok'] || ($result['body'] === null && ($result['local_path'] ?? null) === null)) {
            if ($progressCallback !== null) {
                ($progressCallback)(['[edge] Edge fetch failed ('.($result['message'] ?? 'unknown error').'), falling back to BD proxy...']);
            }

            return null;
        }

        $this->lastEdgeDownloadStartedAt = $downloadStartedAt;
        $this->lastEdgeDownloadDurationMs = $downloadStartedAt->diffInMilliseconds(now());
        $this->lastEdgeBundleUrl = ! empty($result['name'])
            ? 'https://'.IvacEdgeBundleFetcher::HOST.'/assets/'.$result['name']
            : null;

        $dumpPath = storage_path('app/captcha/ivac-bundle.js');
        if (! is_dir(dirname($dumpPath))) {
            mkdir(dirname($dumpPath), 0775, true);
        }

        // The archive shortcut hands back a local file instead of 2.2 MB of body: copy it
        // into place so everything downstream (Python --bundle, the sidecar's /stage, the
        // version registration) still reads the one fixed dump path.
        if (($result['local_path'] ?? null) !== null) {
            copy($result['local_path'], $dumpPath);

            if ($progressCallback !== null) {
                ($progressCallback)(["[edge] ✓ {$result['name']} already archived — reusing local copy, download skipped"]);
            }
        } else {
            file_put_contents($dumpPath, $result['body']);
        }

        // Pre-stage the freshly downloaded bundle into the sidecar's double-buffer NOW, so
        // its ~1.1s eval runs IN PARALLEL with the Python analysis that follows instead of
        // adding to the critical path. The sidecar acks immediately and evals in the
        // background; activation later calls promote() for an instant swap. Best-effort:
        // a miss just means promote() falls back to a normal reload. (Python's dump_bundle
        // is a no-op on this path, so these exact bytes are what analysis will hash.)
        $this->sidecar->stage();

        if ($progressCallback !== null && ($result['local_path'] ?? null) === null) {
            ($progressCallback)(["[edge] ✓ Bundle downloaded from edge IP {$result['edge_ip']}, dumped to {$dumpPath}"]);
        }

        return $dumpPath;
    }

    /**
     * Local path of an already-archived copy of $assetName, or null if we have never
     * downloaded it (or the archive is missing/corrupt and must be re-fetched).
     *
     * IVAC's assets are content-hashed by Vite and served "cache-control: immutable", so a
     * filename we have already stored cannot have different bytes behind it — re-downloading
     * 2.2 MB to get a file we already have is pure latency. The stored sha256 is re-verified
     * against the file so local corruption or a truncated write falls back to a real fetch
     * rather than feeding bad bytes into analysis.
     */
    private function archivedBundlePath(string $assetName): ?string
    {
        $version = CaptchaBundleVersion::query()
            ->where('bundle_filename', $assetName)
            ->latest('id')
            ->first();

        if ($version === null) {
            return null;
        }

        $path = config('captcha.storage_path').'/bundles/'.$version->bundle_hash.'.js';

        if (! is_file($path) || hash_file('sha256', $path) !== $version->bundle_hash) {
            return null;
        }

        return $path;
    }
}
