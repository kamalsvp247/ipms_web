<?php

namespace App\Services\Captcha;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Splits a captured challenge trace into what Cloudflare keeps and what it rotates.
 *
 * Step 2 of the protocol-emulation plan. Every URL in the flow mixes three kinds of
 * segment, and telling them apart is the whole job — an emulator that treats a per-session
 * value as config breaks on the second solve, and one that treats a deployment constant as
 * per-session has nowhere to read it from:
 *
 *   structural      the hosts, /cdn-cgi/challenge-platform/, and the fo|i|pat|ci verbs.
 *   deployment      the /h/<letter>/ branch, the api.js asset hash, the av/fb segments and
 *                   the fo deploy triple. Rotates when Cloudflare redeploys, identical
 *                   across every solve in between — so it belongs in a portal setting.
 *   per-session     cf-ray, the challenge tokens, the millisecond timestamps, the pat
 *                   digest and the rch cache-buster. Different on every single solve, read
 *                   from the live bootstrap and never stored.
 *
 * The classification is not asserted, it is measured: stability() re-derives across several
 * captures and reports any key that moved, which is what proves a value belongs in the
 * deployment set rather than the session set.
 */
class TurnstileFlowExtractor
{
    /**
     * Each deployment constant is gated on a shape before it can be stored. A capture that
     * yields something malformed contributes nothing rather than poisoning the setting —
     * the same last-known-good discipline syncEndpoints() uses for IVAC.
     */
    private const CONSTANT_PATTERNS = [
        'widget_host' => '/^[a-z0-9.-]+\.cloudflare\.com$/',
        'telemetry_host' => '/^[a-z0-9.-]+\.cloudflare\.com$/',
        'branch' => '/^[a-z]$/',
        'api_asset' => '/^[0-9a-f]{8,32}$/',
        'av' => '/^av\d+$/',
        'fb' => '/^[A-Za-z0-9]{2,8}$/',
        'deploy_triple' => '/^\d+:\d+:[A-Za-z0-9_-]+$/',
    ];

    /** Templates must keep their anchor, so a mis-derived one cannot be stored. */
    private const TEMPLATE_ANCHORS = [
        'api_js' => '/api.js',
        'iframe' => '/cdn-cgi/challenge-platform/',
        'flow' => '/cdn-cgi/challenge-platform/',
        'init' => '/cdn-cgi/challenge-platform/',
        'pat' => '/cdn-cgi/challenge-platform/',
        'ci' => '/cdn-cgi/challenge-platform/',
    ];

    /**
     * Derive the flow's constants, templates and session values from one capture.
     *
     * @param  array<string, mixed>  $trace
     * @return array{constants: array<string, string>, templates: array<string, string>, session: array<string, string>}
     */
    public function extract(array $trace): array
    {
        $calls = $trace['calls'] ?? [];
        $constants = [];
        $templates = [];
        $session = [];

        foreach ($calls as $call) {
            $path = (string) ($call['path'] ?? '');
            $host = (string) ($call['host'] ?? '');
            $role = (string) ($call['role'] ?? '');

            if ($path === '' || ! in_array($role, ['challenge', 'turnstile'], true)) {
                continue;
            }

            if ($role === 'turnstile' && preg_match('#^/turnstile/v0/g/([0-9a-f]+)/api\.js$#', $path, $m)) {
                $constants['widget_host'] = $host;
                $constants['api_asset'] = $m[1];
                $templates['api_js'] = '/turnstile/v0/g/{api_asset}/api.js';

                continue;
            }

            if (preg_match('#^/cdn-cgi/challenge-platform/h/([a-z])/#', $path, $m)) {
                $constants['branch'] = $m[1];
            }

            // The widget iframe. rch/<id> changes on every solve — it is a cache-buster,
            // not a version — while av0 and fbE hold across captures.
            if (preg_match('#^/cdn-cgi/challenge-platform/h/[a-z]/turnstile/f/(av\d+)/rch/([a-z0-9]+)/([\w-]+)/auto/([A-Za-z0-9]+)/new/normal#', $path, $m)) {
                $constants['widget_host'] = $host;
                $constants['av'] = $m[1];
                $constants['fb'] = $m[4];
                $session['cache_bust'] = $m[2];
                $session['site_key'] = $m[3];
                $templates['iframe'] = '/cdn-cgi/challenge-platform/h/{branch}/turnstile/f/{av}/rch/{cache_bust}/{site_key}/auto/{fb}/new/normal?lang=auto';

                continue;
            }

            // The flow endpoint, called twice: once to collect the challenge, once to
            // submit it. The leading triple is stamped at deploy time and is identical
            // across every solve of that deployment.
            if (preg_match('#^/cdn-cgi/challenge-platform/h/[a-z]/fo/(\d+:\d+:[A-Za-z0-9_-]+)/([0-9a-f]+)/(.+)$#', $path, $m)) {
                $constants['widget_host'] = $host;
                $constants['deploy_triple'] = $m[1];
                $session['ray'] = $m[2];
                $session['chl_token'] = $m[3];
                $templates['flow'] = '/cdn-cgi/challenge-platform/h/{branch}/fo/{deploy_triple}/{ray}/{chl_token}';

                continue;
            }

            if (preg_match('#^/cdn-cgi/challenge-platform/h/[a-z]/i/([0-9a-f]+)/(.+)$#', $path, $m)) {
                $constants['telemetry_host'] = $host;
                $session['ray'] = $m[1];
                $session['chl_token'] = $m[2];
                $templates['init'] = '/cdn-cgi/challenge-platform/h/{branch}/i/{ray}/{chl_token}';

                continue;
            }

            if (preg_match('#^/cdn-cgi/challenge-platform/h/[a-z]/pat/([0-9a-f]+)/(\d+)/([0-9a-f]{64})/(.+)$#', $path, $m)) {
                $session['ray'] = $m[1];
                $session['pat_ts'] = $m[2];
                $session['pat_digest'] = $m[3];
                $session['pat_token'] = $m[4];
                $templates['pat'] = '/cdn-cgi/challenge-platform/h/{branch}/pat/{ray}/{pat_ts}/{pat_digest}/{pat_token}';

                continue;
            }

            if (preg_match('#^/cdn-cgi/challenge-platform/h/[a-z]/ci/([0-9a-f]+)/(\d+)/(.+)$#', $path, $m)) {
                $session['ray'] = $m[1];
                $session['ci_ts'] = $m[2];
                $session['ci_token'] = $m[3];
                $templates['ci'] = '/cdn-cgi/challenge-platform/h/{branch}/ci/{ray}/{ci_ts}/{ci_token}';
            }
        }

        return [
            'constants' => $this->gateConstants($constants),
            'templates' => $this->gateTemplates($templates),
            'session' => $session,
        ];
    }

    /**
     * Re-derive across several captures and report which constants actually held.
     *
     * This is the evidence behind the deployment/session split. A key that differs between
     * two solves minutes apart is per-session no matter what it looks like, and storing it
     * would bake one solve's value into the config.
     *
     * @param  list<array<string, mixed>>  $traces
     * @return array{samples: int, stable: array<string, string>, volatile: array<string, list<string>>}
     */
    public function stability(array $traces): array
    {
        $seen = [];

        foreach ($traces as $trace) {
            foreach ($this->extract($trace)['constants'] as $key => $value) {
                $seen[$key][] = $value;
            }
        }

        $stable = [];
        $volatile = [];

        foreach ($seen as $key => $values) {
            $unique = array_values(array_unique($values));

            if (count($unique) === 1) {
                $stable[$key] = $unique[0];

                continue;
            }

            $volatile[$key] = $unique;
        }

        return ['samples' => count($traces), 'stable' => $stable, 'volatile' => $volatile];
    }

    /**
     * Merge a capture's deployment constants into settings.turnstile_endpoints.
     *
     * Only well-formed values are written and only differences are logged, so a failed or
     * partial extraction leaves the previous configuration in place rather than clearing
     * it. Nothing from the session set is ever persisted.
     *
     * @param  array<string, mixed>  $trace
     * @return array{changed: array<string, string>, effective: array<string, string>}
     */
    public function sync(array $trace): array
    {
        $setting = Setting::instance();
        $derived = $this->extract($trace);
        $current = is_array($setting->turnstile_endpoints) ? $setting->turnstile_endpoints : [];
        $incoming = $derived['constants'] + $derived['templates'];
        $changed = [];
        $merged = $current;

        foreach ($incoming as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $merged[$key] = $value;
                $changed[$key] = $value;
            }
        }

        if ($changed !== []) {
            try {
                $setting->update(['turnstile_endpoints' => $merged]);
                Log::info('[TurnstileFlow] challenge constants synced from trace', $changed);
            } catch (\Throwable $e) {
                Log::warning('[TurnstileFlow] constants sync threw', ['error' => $e->getMessage()]);

                return ['changed' => [], 'effective' => $current];
            }
        }

        return ['changed' => $changed, 'effective' => $merged];
    }

    /**
     * @param  array<string, string>  $constants
     * @return array<string, string>
     */
    private function gateConstants(array $constants): array
    {
        return array_filter(
            $constants,
            fn (string $value, string $key) => isset(self::CONSTANT_PATTERNS[$key])
                && preg_match(self::CONSTANT_PATTERNS[$key], $value) === 1,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, string>  $templates
     * @return array<string, string>
     */
    private function gateTemplates(array $templates): array
    {
        return array_filter(
            $templates,
            fn (string $value, string $key) => isset(self::TEMPLATE_ANCHORS[$key])
                && str_starts_with($value, '/')
                && str_contains($value, self::TEMPLATE_ANCHORS[$key]),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
