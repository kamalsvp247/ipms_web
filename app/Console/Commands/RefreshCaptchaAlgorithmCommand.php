<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsBookingWindow;
use App\Models\Setting;
use App\Services\Captcha\ExtractorRepairService;
use App\Services\CaptchaAlgorithmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Detect an IVAC redeploy and self-heal captcha encryption with no human action.
 *
 * Cheap path: fetch only the HTML and compare the content-hashed JS bundle asset name
 * to the last-seen marker. Unchanged -> exit. Changed -> run the full analyzer, which
 * re-extracts from the live bundle, atomically refreshes encrypt_meta.json (incl. the
 * secret), auto-applies the DB seeds, and reloads the sidecar. The marker only advances
 * on a clean extraction so a broken redeploy is retried and surfaced as needs-attention.
 */
class RefreshCaptchaAlgorithmCommand extends Command
{
    use SkipsBookingWindow;

    protected $signature = 'captcha-algorithm:auto-refresh
                            {--force : Run the full analysis even if the bundle asset is unchanged}';

    protected $description = 'Detect IVAC redeploys and auto-refresh captcha encryption from the live bundle';

    public function handle(CaptchaAlgorithmService $service, ExtractorRepairService $repair): int
    {
        $setting = Setting::instance();
        $proxy = trim((string) $setting->captcha_bd_proxy_url);

        if ($proxy === '') {
            $this->warn('No captcha_bd_proxy_url configured — run an analysis once from the monitor to set it.');

            return self::SUCCESS;
        }

        if ($this->inBookingWindow($setting)) {
            $this->reportBookingWindowSkip($setting, 'the captcha auto-refresh');

            return self::SUCCESS;
        }

        $asset = $this->currentBundleAsset($proxy);
        if ($asset === null) {
            // Transient fetch/proxy failure — not an extraction failure. Log and retry next tick.
            $this->warn('Could not fetch the live bundle asset (proxy/CF issue) — will retry.');

            return self::SUCCESS;
        }

        $lastAsset = Cache::get(CaptchaAlgorithmService::BUNDLE_ASSET_CACHE_KEY);
        if (! $this->option('force') && $asset === $lastAsset) {
            $this->info("No redeploy: bundle asset unchanged ({$asset}).");

            return self::SUCCESS;
        }

        $this->info("Redeploy detected (asset {$lastAsset} -> {$asset}) — running full analysis…");
        $result = $service->analyze($proxy);

        if (($result['error'] ?? null) !== null) {
            $this->error('Analyzer failed: '.$result['error']);

            return self::FAILURE;
        }

        $applied = $result['auto_applied']['applied'] ?? false;
        if ($applied) {
            // The marker is advanced inside CaptchaAlgorithmService::analyze() on a clean
            // apply, so the button and the cron stay consistent — nothing to set here.
            $this->info('Clean extraction — DB seeds auto-applied, encrypt_meta refreshed, sidecar reloaded.');

            return self::SUCCESS;
        }

        // Unclean: keep last-known-good (do NOT advance the marker so we retry), alert.
        $reason = $result['auto_applied']['reason'] ?? 'unknown extraction issue';
        $severity = $result['extraction_alarm']['severity'] ?? 'unknown';

        Log::warning('Captcha auto-refresh: redeploy detected but extraction unclean; last-known-good kept', [
            'asset' => $asset,
            'reason' => $reason,
            'severity' => $severity,
        ]);
        $this->warn('Extraction unclean — last-known-good kept, needs-attention raised: '.$reason);

        // A 'rollout' alarm means IVAC shipped a config version whose module is not in the
        // bundle yet — their problem, and it heals when they finish deploying. Only a
        // 'structural' failure means our extractor can no longer read the bundle.
        $queued = $repair->queueFromAnalysis($result, $reason);
        $queued['queued']
            ? $this->warn('Structural failure — unattended extractor repair queued for bundle '.substr((string) $queued['hash'], 0, 8).'.')
            : $this->info('No repair queued: '.$queued['reason']);

        return self::SUCCESS;
    }

    /**
     * Cheap HTML-only probe for the current Vite bundle asset filename, or null on failure.
     */
    private function currentBundleAsset(string $proxy): ?string
    {
        $script = base_path('app/Scripts/analyze_captcha_algo.py');

        $result = Process::timeout(120)->run(['python3', $script, $proxy, '--head-only']);
        if (! $result->successful()) {
            return null;
        }

        $data = json_decode(trim($result->output()), true);
        if (! is_array($data) || ($data['error'] ?? null) !== null) {
            return null;
        }

        $asset = $data['bundle_asset'] ?? null;

        return is_string($asset) && $asset !== '' ? $asset : null;
    }
}
