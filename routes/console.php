<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Enums\CaptchaRequestStatus;
use App\Models\BotLog;
use App\Models\CaptchaRequest;
use App\Models\CaptchaToken;
use App\Support\CaptchaPoolExpiry;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    CaptchaToken::where('created_at', '<=', CaptchaPoolExpiry::cutoff())
        ->delete();
})->everyMinute();

Schedule::call(function () {
    // Delete captcha requests older than 5 minutes (tokens expire after ~5 min anyway)
    CaptchaRequest::where('created_at', '<', now()->subMinutes(5))->delete();
})->everyMinute();

// Same rule the Captcha Control "Delete Expired" button applies, run continuously so
// stale ready tokens don't sit in the pool for up to a minute waiting on the coarser
// sweep above (pool_expiry_seconds defaults to 120s, well under that 5-minute window).
Schedule::call(function () {
    CaptchaRequest::where('source', 'pool')
        ->where('status', CaptchaRequestStatus::Ready)
        ->where('solved_at', '<', CaptchaPoolExpiry::cutoff())
        ->delete();
})->everyTenSeconds()->name('captcha-pool:purge-expired')->withoutOverlapping();

Schedule::call(function () {
    // Keep 429 (rate-limit signal) and 5xx (server errors) — delete noisy 4xx only.
    BotLog::where(function ($q) {
        $q->where(function ($r) {
            $r->whereBetween('status_code', [401, 499])
                ->where('status_code', '!=', 429);
        })
            ->orWhere(function ($inner) {
                $inner->whereNull('status_code')->whereNotNull('error_type');
            })
            ->orWhere('method', 'LOG');
    })->delete();
})->everyMinute();

Schedule::command('bypass-ips:scan-subnets')->daily();

// Safety net for auto payment — inline dispatch on link ingest is the fast path, this catches
// anything that path missed (queue outage, worker restart, concurrency deferral).
Schedule::command('payments:sweep-auto')->everyMinute()->withoutOverlapping();

Schedule::command('gmail:renew-watch')->daily();
Schedule::command('gmail:sync-fallback')->everyTenMinutes();

// Captcha auto-refresh: detect IVAC redeploys and self-heal encryption with no human
// action. Each tick does a cheap HTML-only probe (compares the content-hashed bundle
// asset name); only a changed asset triggers the full analyzer. The command skips the
// booking window, atomically refreshes encrypt_meta.json + reloads the sidecar on a
// clean extraction, and keeps last-known-good + raises needs-attention on an unclean one.
Schedule::command('captcha-algorithm:auto-refresh')->everyFiveMinutes()->withoutOverlapping();

// When that unclean extraction is 'structural' (our extractor can no longer read the
// bundle, as opposed to IVAC mid-rollout), auto-refresh queues a repair request and this
// picks it up. Kept separate because a repair can run for ~25 minutes, and doing it inline
// would stall redeploy detection itself. The long withoutOverlapping expiry stops a second
// tick from starting a concurrent repair on the same bundle.
Schedule::command('captcha-algorithm:auto-repair')->everyFiveMinutes()->withoutOverlapping(45);
