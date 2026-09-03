<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsBookingWindow;
use App\Models\Setting;
use App\Services\Captcha\ExtractorRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Consume the repair request left by captcha-algorithm:auto-refresh and drive an
 * unattended extractor repair.
 *
 * Deliberately a separate command rather than an inline step of auto-refresh: a repair
 * can run for ~25 minutes, and blocking the five-minute refresh tick for that long would
 * stall redeploy detection itself.
 */
class RepairCaptchaExtractorCommand extends Command
{
    use SkipsBookingWindow;

    protected $signature = 'captcha-algorithm:auto-repair
                            {--bundle= : Path to the bundle that broke extraction (defaults to the queued request)}
                            {--reason=manual invocation : Analyzer verdict to hand the agent}
                            {--force : Run with no queued request, and ignore the booking window}';

    protected $description = 'Repair the captcha extractor after an IVAC bundle emission-shape rotation';

    public function handle(ExtractorRepairService $repair): int
    {
        if (! config('captcha.auto_repair.enabled') && ! $this->option('force')) {
            $this->info('Auto-repair is disabled (CAPTCHA_AUTO_REPAIR).');

            return self::SUCCESS;
        }

        $setting = Setting::instance();

        // The repair ends in an analyze() that reloads the sidecar, so it never runs
        // mid-race on its own. An explicit --force is a human accepting that risk.
        if (! $this->option('force') && $this->inBookingWindow($setting)) {
            $this->reportBookingWindowSkip($setting, 'the extractor repair');

            return self::SUCCESS;
        }

        $request = Cache::get(ExtractorRepairService::REQUEST_CACHE_KEY);
        $bundle = $this->option('bundle') ?: ($request['bundle'] ?? null);
        $reason = $request['reason'] ?? $this->option('reason');

        if (! $bundle) {
            if (! $this->option('force')) {
                $this->info('No repair requested.');

                return self::SUCCESS;
            }

            $this->error('No bundle path: pass --bundle when forcing.');

            return self::FAILURE;
        }

        $hash = $request['hash'] ?? hash_file('sha256', $bundle) ?: '';

        $this->warn('Extraction is structurally broken — starting unattended repair. This can take ~25 minutes.');

        $outcome = $repair->attempt($bundle, (string) $hash, (string) $reason, trim((string) $setting->captcha_bd_proxy_url) ?: null);

        if ($outcome['repaired']) {
            Cache::forget(ExtractorRepairService::REQUEST_CACHE_KEY);
            $this->info('Repaired: '.$outcome['detail']);

            return self::SUCCESS;
        }

        $this->error("Repair did not apply (stage: {$outcome['stage']}): {$outcome['detail']}");

        // Leave the request queued so a later attempt can retry, up to the service's cap.
        return self::FAILURE;
    }
}
