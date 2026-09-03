<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Captcha\InHouseCaptchaClient;
use App\Services\Captcha\TurnstileBisectStore;
use App\Services\Captcha\TurnstileFlowExtractor;
use App\Services\Captcha\TurnstileTraceStore;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Self-hosted Turnstile solving.
 *
 * Every route here is admin-only via the `can:bot.manage` group in routes/api.php.
 * The site key and page URL always come from Setting::instance() — the token is only
 * valid for the (site key, hostname) pair it was minted against, so hardcoding either
 * would silently produce tokens IVAC rejects after a settings change.
 */
class InHouseCaptchaController extends Controller
{
    /**
     * POST /api/in-house-captcha/generate
     *
     * Mint one Turnstile token with the local solver.
     */
    public function generate(InHouseCaptchaClient $solver): JsonResponse
    {
        $setting = Setting::instance();
        $siteKey = trim((string) $setting->captcha_site_key);
        $pageUrl = trim((string) $setting->captcha_page_url);

        if ($siteKey === '' || $pageUrl === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'captcha_site_key and captcha_page_url must be set under Settings before solving.',
            ], 422);
        }

        try {
            $result = $solver->solve($siteKey, $pageUrl);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status' => 'ok',
            'token' => $result['token'],
            'ms' => $result['ms'],
            'attempts' => $result['attempts'],
            'site_key' => $siteKey,
            'page_url' => $pageUrl,
        ]);
    }

    /**
     * GET /api/in-house-captcha/health
     *
     * Solver process + Chrome status, pool utilisation and lifetime counters.
     */
    public function health(InHouseCaptchaClient $solver): JsonResponse
    {
        $health = $solver->health();

        if ($health === null) {
            return response()->json([
                'status' => 'down',
                'message' => 'Solver sidecar is unreachable. Check: systemctl status ipms-in-house-captcha',
            ], 503);
        }

        return response()->json(['status' => 'up'] + $health);
    }

    /**
     * POST /api/in-house-captcha/trace
     *
     * Capture one instrumented solve and persist the whole Cloudflare challenge sequence.
     * This is the ground truth the protocol-emulation work is specified against, and
     * re-capturing it is how a later breakage gets diagnosed.
     */
    public function trace(
        InHouseCaptchaClient $solver,
        TurnstileTraceStore $traces,
        TurnstileFlowExtractor $extractor,
    ): JsonResponse {
        $setting = Setting::instance();
        $siteKey = trim((string) $setting->captcha_site_key);
        $pageUrl = trim((string) $setting->captcha_page_url);

        if ($siteKey === '' || $pageUrl === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'captcha_site_key and captcha_page_url must be set under Settings before tracing.',
            ], 422);
        }

        try {
            $captured = $solver->trace($siteKey, $pageUrl);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        // Derive straight away: a capture is only useful once its deployment constants are
        // separated out, and doing it here means a Cloudflare rotation is picked up by the
        // same action that observes it.
        $stored = $traces->read($captured['file']);
        $captured['flow'] = $stored === null ? null : $extractor->sync($stored);

        return response()->json(['status' => 'ok'] + $captured);
    }

    /**
     * GET /api/in-house-captcha/flow
     *
     * The stored deployment constants, plus how well they held across every capture on
     * disk. A key reported volatile is one that is really per-session and must not be
     * treated as configuration.
     */
    public function flow(TurnstileTraceStore $traces, TurnstileFlowExtractor $extractor): JsonResponse
    {
        $samples = [];

        foreach ($traces->list() as $row) {
            $trace = $traces->read($row['file']);

            if ($trace !== null) {
                $samples[] = $trace;
            }
        }

        $latest = $samples[0] ?? null;

        return response()->json([
            'status' => 'ok',
            'stored' => Setting::instance()->turnstile_endpoints ?? [],
            'session_keys' => $latest === null ? [] : array_keys($extractor->extract($latest)['session']),
            'stability' => $extractor->stability($samples),
        ]);
    }

    /**
     * GET /api/in-house-captcha/traces
     *
     * List captured traces, newest first.
     */
    public function traces(TurnstileTraceStore $traces): JsonResponse
    {
        return response()->json(['status' => 'ok', 'traces' => $traces->list()]);
    }

    /**
     * GET /api/in-house-captcha/traces/{file}
     *
     * One trace, with response bodies reduced to a preview plus their true size. A single
     * capture holds several hundred KB of challenge script, so the full bodies stay on
     * disk where the later emulation steps read them.
     */
    public function showTrace(string $file, TurnstileTraceStore $traces): JsonResponse
    {
        $trace = $traces->read($file);

        if ($trace === null) {
            return response()->json(['status' => 'failed', 'message' => 'Trace not found.'], 404);
        }

        return response()->json(['status' => 'ok', 'trace' => $traces->summarise($trace)]);
    }

    /**
     * GET /api/in-house-captcha/bisect
     *
     * The most recent fingerprint bisect: which browser signals Cloudflare actually checks.
     */
    public function bisectReport(TurnstileBisectStore $bisects): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'report' => $bisects->latest(),
            'history' => $bisects->list(),
        ]);
    }

    /**
     * POST /api/in-house-captcha/bisect
     *
     * Measure which browser signals the challenge rejects, one mutation at a time. This is
     * minutes of live solving, so it is a deliberate operator action rather than anything
     * scheduled.
     */
    public function bisect(InHouseCaptchaClient $solver): JsonResponse
    {
        $setting = Setting::instance();
        $siteKey = trim((string) $setting->captcha_site_key);
        $pageUrl = trim((string) $setting->captcha_page_url);

        if ($siteKey === '' || $pageUrl === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'captcha_site_key and captcha_page_url must be set under Settings before bisecting.',
            ], 422);
        }

        $samples = (int) request()->integer('samples', 6);
        $mutations = array_values(array_filter((array) request()->input('mutations', []), 'is_string'));

        try {
            $report = $solver->bisect($siteKey, $pageUrl, $samples, $mutations);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['status' => 'ok'] + $report);
    }

    /**
     * POST /api/in-house-captcha/restart
     *
     * Relaunch the solver's Chrome instance without restarting the sidecar.
     */
    public function restart(InHouseCaptchaClient $solver): JsonResponse
    {
        if (! $solver->restart()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Could not restart the solver browser.',
            ], 502);
        }

        return response()->json(['status' => 'ok']);
    }
}
