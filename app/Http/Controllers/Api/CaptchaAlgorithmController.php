<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptchaBundleVersion;
use App\Models\CaptchaProvider;
use App\Services\Captcha\CaptchaBundleVersionService;
use App\Services\Captcha\ExtractorRepairService;
use App\Services\CaptchaAlgorithmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CaptchaAlgorithmController extends Controller
{
    private const PROGRESS_CACHE_KEY = 'captcha_analysis_progress';

    private const PROGRESS_TTL = 600; // 10 minutes

    /** Gap between publishing fresh IVAC constants and releasing the bot, so its refresh lands first. */
    private const PROVIDER_ENABLE_DELAY_US = 1_000_000;

    public function __construct(
        protected CaptchaAlgorithmService $service,
        protected CaptchaBundleVersionService $versions,
        protected ?ExtractorRepairService $repair = null,
    ) {}

    /**
     * Analyze the live IVAC site and compare with Java implementation.
     *
     * @param  Request  $request  Must include 'proxy' in request body or query
     */
    public function analyze(Request $request): JsonResponse
    {
        $proxy = (string) ($request->input('proxy') ?? '');

        // Persist the BD proxy server-side so the scheduled auto-refresh job can reuse it.
        // When the caller sends an empty proxy (direct connection), keep the existing saved value.
        if (! empty($proxy)) {
            $setting = \App\Models\Setting::instance();
            if ($setting->captcha_bd_proxy_url !== $proxy) {
                $setting->captcha_bd_proxy_url = $proxy;
                $setting->save();
            }
        }

        // Initialize progress tracking
        $sessionId = $request->hasSession() ? $request->session()->getId() : uniqid();
        $progressKey = self::PROGRESS_CACHE_KEY.'.'.$sessionId;
        Cache::put($progressKey, ['status' => 'starting', 'step' => 0, 'logs' => []], self::PROGRESS_TTL);

        try {
            $result = $this->service->analyze($proxy, function (array $logs) use ($progressKey): void {
                Cache::put($progressKey, ['status' => 'running', 'logs' => $logs], self::PROGRESS_TTL);
            });

            // Clear progress on completion
            Cache::forget($progressKey);

            // Re-enabling providers releases the bot, so it must require a genuinely clean extraction —
            // not merely the absence of a fatal error. analyze() returns error=null on the unclean path
            // too, where autoApplySeeds() kept the last-known-good seeds and registerBundleVersion()
            // restored the previous bundle to disk. The sidecar is perfectly healthy there, serving the
            // OLD bundle, so an error/health check alone would open the gate on a rotated deployment and
            // send the bot to race with stale encryption. Match the CLI path
            // (RefreshCaptchaAlgorithmCommand), which already gates on auto_applied.applied.
            $sidecarHealthy = $result['engine']['sidecar']['healthy'] ?? false;
            $extractionClean = ($result['auto_applied']['applied'] ?? false) === true;
            $mayEnable = empty($result['error'])
                && $sidecarHealthy
                && $extractionClean
                && empty($result['needs_attention']);

            if ($mayEnable) {
                // Publish the constants a beat before opening the gate. Bots poll the captcha endpoint
                // every 500ms and refresh their IVAC constants when the config version changes, so this
                // pause lets that refresh land before a token is handed out — keeping the round-trip off
                // the critical path instead of adding it to the first sign-in.
                usleep(self::PROVIDER_ENABLE_DELAY_US);
            }

            $result['providers_enabled'] = $mayEnable
                ? CaptchaProvider::where('enabled', false)->update(['enabled' => true])
                : 0;
            $result['providers_withheld_reason'] = $mayEnable
                ? null
                : $this->providersWithheldReason($result, $sidecarHealthy, $extractionClean);

            // The bundle download is a manual action, so this button — not the cron — is the
            // trigger that matters. A structural extraction failure means IVAC rotated the
            // config emission shape and our extractor can no longer read it; queue an
            // unattended repair for the scheduler to pick up. It cannot run inline: a repair
            // takes ~25 minutes and needs the scheduler user's Claude credentials.
            if (! $extractionClean) {
                $result['repair'] = ($this->repair ?? app(ExtractorRepairService::class))->queueFromAnalysis(
                    $result,
                    $result['auto_applied']['reason'] ?? 'extraction unclean'
                );
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Cache::forget($progressKey);

            return response()->json([
                'error' => $e->getMessage(),
                'logs' => [],
            ], 500);
        }
    }

    /**
     * Why captcha providers stayed disabled after an analysis, for the monitor toast. Ordered so the
     * operator sees the root cause rather than a downstream symptom.
     *
     * @param  array<string, mixed>  $result
     */
    private function providersWithheldReason(array $result, bool $sidecarHealthy, bool $extractionClean): string
    {
        if (! empty($result['error'])) {
            return 'Analysis failed: '.$result['error'];
        }

        if (! $extractionClean) {
            return 'Extraction unclean — last-known-good kept: '
                .($result['auto_applied']['reason'] ?? 'login or reserve could not be fully resolved');
        }

        if (! $sidecarHealthy) {
            return 'Encrypt sidecar is not healthy — it would serve a stale bundle.';
        }

        return 'Needs attention: '.($result['needs_attention']['reason'] ?? 'unresolved captcha alarm');
    }

    /**
     * Get current analysis progress.
     */
    public function progress(Request $request): JsonResponse
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : uniqid();
        $progressKey = self::PROGRESS_CACHE_KEY.'.'.$sessionId;
        $progress = Cache::get($progressKey, ['status' => 'idle', 'step' => 0, 'logs' => []]);

        return response()->json($progress);
    }

    /**
     * Get snapshot history (last 20 analyses).
     */
    public function history(): JsonResponse
    {
        return response()->json($this->service->getHistory());
    }

    /**
     * Current encryption engine + sidecar status, plus any queued/completed extractor repair
     * so the monitor can report that an unattended fix is pending or has failed.
     */
    public function engine(): JsonResponse
    {
        return response()->json($this->service->engineStatus() + [
            'repair' => ($this->repair ?? app(ExtractorRepairService::class))->status(),
        ]);
    }

    /**
     * Reload the live-bundle encrypt sidecar.
     */
    public function reloadSidecar(): JsonResponse
    {
        return response()->json($this->service->reloadSidecar());
    }

    /**
     * Delete a snapshot by ID.
     */
    public function deleteSnapshot(int $id): JsonResponse
    {
        try {
            $this->service->deleteSnapshot($id);

            return response()->json(['message' => 'Snapshot deleted']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * List stored bundle versions + the currently active one.
     */
    public function versions(): JsonResponse
    {
        return response()->json($this->versions->summary());
    }

    /**
     * Activate a stored bundle version (roll the live encryption to it).
     */
    public function activateVersion(int $id): JsonResponse
    {
        $version = CaptchaBundleVersion::find($id);
        if (! $version) {
            return response()->json(['success' => false, 'message' => 'Version not found'], 404);
        }

        $result = $this->versions->activate($version);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Set or clear a version's label (a labeled version is protected from pruning).
     */
    public function labelVersion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
        ]);

        $version = CaptchaBundleVersion::find($id);
        if (! $version) {
            return response()->json(['message' => 'Version not found'], 404);
        }

        $version->label = $validated['label'] !== null && $validated['label'] !== '' ? $validated['label'] : null;
        $version->save();

        return response()->json($this->versions->serialize($version->refresh()));
    }

    /**
     * Delete a stored bundle version (blocked while active).
     */
    public function deleteVersion(int $id): JsonResponse
    {
        $version = CaptchaBundleVersion::find($id);
        if (! $version) {
            return response()->json(['message' => 'Version not found'], 404);
        }

        if ($version->is_active) {
            return response()->json(['message' => 'Cannot delete the active version. Activate another version first.'], 422);
        }

        $this->versions->delete($version);

        return response()->json(['message' => 'Version deleted']);
    }

    /**
     * Delete all snapshots.
     */
    public function clearHistory(): JsonResponse
    {
        $count = $this->service->clearHistory();

        return response()->json([
            'message' => "Deleted {$count} snapshot(s).",
            'deleted' => $count,
        ]);
    }
}
