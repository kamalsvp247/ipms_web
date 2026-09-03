<?php

namespace App\Console\Commands;

use App\Services\Captcha\CaptchaBundleVersionService;
use Illuminate\Console\Command;

/**
 * Backfill the bundle-version registry from the bundle currently on disk, so the
 * existing live config becomes the first activatable/rollback version. Idempotent:
 * does nothing once any version exists.
 */
class ImportCurrentBundleCommand extends Command
{
    protected $signature = 'captcha:import-current-bundle';

    protected $description = 'Register the current on-disk captcha bundle as the initial active version';

    public function handle(CaptchaBundleVersionService $service): int
    {
        $version = $service->importCurrent();

        if ($version === null) {
            $this->warn('Nothing imported (versions already exist, or no bundle/meta on disk).');

            return self::SUCCESS;
        }

        $this->info("Imported active version #{$version->id} ({$version->bundle_hash}).");

        return self::SUCCESS;
    }
}
