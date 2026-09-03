<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Proxy-free fetcher for the live IVAC captcha JS bundle. Cloudflare only
 * challenges HTML/non-static routes; .js assets are served un-challenged
 * from any CF edge IP, and GET /.well-known/ leaks index.html (which names
 * the current hashed bundle).
 *
 * Two entry points, for two different jobs:
 *   - fetchFastest() just wants the bytes, quickly: one sequential discover +
 *     download over a single kept-alive connection, race only as a fallback.
 *   - raceDownload() wants per-edge-IP data points: hits every IP in parallel
 *     and waits for all of them.
 *
 * Used by CaptchaAlgorithmService as the algorithm monitor's primary fetch path.
 */
class IvacEdgeBundleFetcher
{
    public const HOST = 'appointment.ivacbd.com';

    /**
     * Cloudflare edge IPs that front appointment.ivacbd.com (full A + AAAA set,
     * confirmed via DNS across multiple resolvers and geo subnets). IPv6 entries
     * are unreachable from a v4-only network but harmless to include.
     *
     * @var array<int, string>
     */
    public const EDGE_IPS = [
        '104.26.14.90',
        '104.26.15.90',
        '172.67.68.164',
        '2606:4700:20::681a:e5a',
        '2606:4700:20::681a:f5a',
        '2606:4700:20::ac43:44a4',
    ];

    public const NOTICE_MESSAGE = 'IVAC booking-guidelines notice is active — Cloudflare is serving a static notice for all paths, so the site is not live and the bundle cannot be fetched right now. Try again during the live booking window.';

    public const BLOCKED_MESSAGE = 'Cloudflare blocked the request ("Sorry, you have been blocked") on every edge IP. This is a WAF/fingerprint block on our HTTP client, not the booking notice and not an IP ban — the site itself is up. Check that the fetcher still sends its TLS ClientHello without ALPN.';

    /**
     * Cache key holding the edge IP that last served a valid bundle. fetchFastest() tries
     * it first so the common case is a single request instead of a full race.
     */
    public const PREFERRED_EDGE_CACHE_KEY = 'captcha:preferred_edge_ip';

    /**
     * The discovery request, with a unique cache-buster on every call.
     *
     * Cloudflare caches /.well-known/ at the edge for four hours (cache-control:
     * max-age=14400, observed serving age=628 on a HIT) and ignores a client
     * Cache-Control: no-cache request header, so the plain path can keep naming the
     * PREVIOUS bundle for hours after an IVAC redeploy — precisely when a fresh name is
     * what we need. A unique query string is a distinct cache key, so the request goes to
     * origin (verified: cf-cache-status MISS, same 585-byte index.html) while keeping the
     * /.well-known/ prefix that earns the challenge exemption in the first place.
     */
    public static function discoveryPath(): string
    {
        return '/.well-known/?cb='.bin2hex(random_bytes(4));
    }

    /**
     * Connect/total timeouts for the sequential fast path. Tighter than the race's, because
     * a stalled fast attempt delays the whole fetch: the edges are ~2ms away, so anything
     * slower than this is a dead IP and we want to move to the next candidate quickly.
     */
    private const FAST_CONNECT_TIMEOUT_MS = 2000;

    private const FAST_DISCOVER_TIMEOUT_MS = 5000;

    private const FAST_DOWNLOAD_TIMEOUT_MS = 20000;

    /** How many edge IPs the sequential fast path walks before handing over to the race. */
    private const FAST_PATH_MAX_ATTEMPTS = 3;

    /**
     * Connect timeout for the parallel race. The edges are ~2ms away, so this only ever
     * elapses for an IP that is genuinely dark — and because the race waits for every
     * handle, a long one puts the whole wait on the caller, twice (discover + download).
     */
    private const RACE_CONNECT_TIMEOUT = 4;

    /**
     * Fetch the live bundle as fast as possible: one sequential discover + download over a
     * single kept-alive TLS connection, falling back to the full parallel race only when
     * that fails.
     *
     * Racing every edge IP is actively counter-productive for plain fetching. curl_multi is
     * single-threaded, so N parallel TLS handshakes serialise into N x ~60ms, and N parallel
     * copies of the same 2.2 MB body contend for the same uplink — measured, the race's own
     * winner is slower than a lone request (~550ms vs ~250ms) and the whole call costs
     * ~1.3s versus ~0.2s sequentially. The race stays as the fallback because it is still
     * the right tool when an edge IP is actually broken.
     *
     * $archiveResolver, when given, is called with the discovered asset name and may return
     * the path of an already-archived copy. IVAC's assets are content-hashed and served
     * "cache-control: immutable", so a name we have already downloaded cannot have different
     * bytes — returning a path skips the download entirely.
     *
     * @param  callable(string): ?string  $archiveResolver
     * @return array{ok: bool, body: ?string, local_path: ?string, name: ?string, version: ?string, edge_ip: ?string, cf_cache_status: ?string, notice_active: bool, message: ?string, strategy: string, discover_log: array, download_log: array}
     */
    public function fetchFastest(?callable $archiveResolver = null): array
    {
        $discoverLog = [];
        $downloadLog = [];

        foreach ($this->fastPathCandidates() as $ip) {
            $ch = $this->newHandle($ip, $this->newCapture());
            $discovery = $this->discoverEntry($this->execute($ch, $ip, self::discoveryPath(), self::FAST_DISCOVER_TIMEOUT_MS));
            $discoverLog[] = $discovery;

            if (! $discovery['valid']) {
                curl_close($ch);
                // The booking notice is a site-wide state, not a per-IP fault, so walking
                // to the next IP would just collect the same answer. Hand straight to the
                // race, which confirms it across every edge before declaring the site off.
                if ($discovery['notice_active']) {
                    break;
                }

                continue;
            }

            $name = $discovery['name'];

            if ($archiveResolver !== null && ($archived = $archiveResolver($name)) !== null) {
                curl_close($ch);
                Cache::forever(self::PREFERRED_EDGE_CACHE_KEY, $ip);

                return [
                    'ok' => true,
                    'body' => null,
                    'local_path' => $archived,
                    'name' => $name,
                    'version' => $discovery['version'],
                    'edge_ip' => $ip,
                    'cf_cache_status' => null,
                    'notice_active' => false,
                    'message' => null,
                    'strategy' => 'archive',
                    'discover_log' => $discoverLog,
                    'download_log' => [],
                ];
            }

            // Same handle => the TLS session from discovery is reused, so the asset
            // request pays no handshake at all.
            $attempt = $this->execute($ch, $ip, '/assets/'.$name, self::FAST_DOWNLOAD_TIMEOUT_MS);
            curl_close($ch);
            $entry = $this->downloadEntry($attempt);
            $downloadLog[] = $entry;

            if ($entry['valid']) {
                Cache::forever(self::PREFERRED_EDGE_CACHE_KEY, $ip);

                return [
                    'ok' => true,
                    'body' => $attempt['body'],
                    'local_path' => null,
                    'name' => $name,
                    'version' => $discovery['version'],
                    'edge_ip' => $ip,
                    'cf_cache_status' => $entry['cf_cache_status'],
                    'notice_active' => false,
                    'message' => null,
                    'strategy' => 'direct',
                    'discover_log' => $discoverLog,
                    'download_log' => $downloadLog,
                ];
            }

            if ($entry['notice_active']) {
                break;
            }
        }

        // Every fast candidate failed. Fall back to the full race, which re-probes every
        // edge IP in parallel and produces the per-IP diagnostics; keep what we already
        // learned at the head of each log so the UI shows the whole story.
        $race = $this->raceDownload($archiveResolver);
        $race['discover_log'] = array_merge($discoverLog, $race['discover_log']);
        $race['download_log'] = array_merge($downloadLog, $race['download_log']);
        $race['strategy'] = 'race';

        return $race;
    }

    /**
     * Edge IPs to try sequentially, last known-good first. Capped so a wholesale outage
     * still reaches the race quickly instead of burning the connect timeout on every IP.
     *
     * @return array<int, string>
     */
    private function fastPathCandidates(): array
    {
        $edgeIps = $this->edgeIps();
        $preferred = Cache::get(self::PREFERRED_EDGE_CACHE_KEY);

        if (is_string($preferred) && in_array($preferred, $edgeIps, true)) {
            $edgeIps = array_merge([$preferred], array_values(array_diff($edgeIps, [$preferred])));
        }

        return array_slice($edgeIps, 0, self::FAST_PATH_MAX_ATTEMPTS);
    }

    /**
     * Race every edge IP for /.well-known/ discovery, then race every edge IP
     * for the discovered bundle asset. Returns the fastest valid responder in
     * each phase, plus a per-IP log of every attempt for diagnostics.
     *
     * @param  callable(string): ?string  $archiveResolver  See fetchFastest().
     * @return array{ok: bool, body: ?string, local_path: ?string, name: ?string, version: ?string, edge_ip: ?string, cf_cache_status: ?string, notice_active: bool, message: ?string, discover_log: array, download_log: array}
     */
    public function raceDownload(?callable $archiveResolver = null): array
    {
        $discoverAttempts = $this->httpGetMulti(self::discoveryPath(), $this->edgeIps());
        $discoverLog = [];
        $discoverWinner = null;

        foreach ($discoverAttempts as $attempt) {
            $entry = $this->discoverEntry($attempt);
            $discoverLog[] = $entry;

            if ($entry['valid'] && ($discoverWinner === null || $entry['duration_ms'] < $discoverWinner['duration_ms'])) {
                $discoverWinner = $entry;
            }
        }

        if ($discoverWinner === null) {
            $anyNotice = collect($discoverLog)->contains('notice_active', true);
            $anyBlocked = collect($discoverLog)->contains('blocked', true);

            return [
                'ok' => false,
                'body' => null,
                'local_path' => null,
                'name' => null,
                'version' => null,
                'edge_ip' => null,
                'cf_cache_status' => null,
                'notice_active' => $anyNotice,
                'message' => match (true) {
                    $anyNotice => self::NOTICE_MESSAGE,
                    $anyBlocked => self::BLOCKED_MESSAGE,
                    default => 'No edge IP returned a usable /.well-known/ response.',
                },
                'discover_log' => $discoverLog,
                'download_log' => [],
            ];
        }

        $name = $discoverWinner['name'];
        $version = $discoverWinner['version'];

        if ($archiveResolver !== null && ($archived = $archiveResolver($name)) !== null) {
            return [
                'ok' => true,
                'body' => null,
                'local_path' => $archived,
                'name' => $name,
                'version' => $version,
                'edge_ip' => $discoverWinner['edge_ip'],
                'cf_cache_status' => null,
                'notice_active' => false,
                'message' => null,
                'discover_log' => $discoverLog,
                'download_log' => [],
            ];
        }

        $downloadAttempts = $this->httpGetMulti('/assets/'.$name, $this->edgeIps());
        $downloadLog = [];
        $downloadWinner = null;
        $downloadWinnerBody = null;

        foreach ($downloadAttempts as $attempt) {
            $entry = $this->downloadEntry($attempt);
            $downloadLog[] = $entry;

            if ($entry['valid'] && ($downloadWinner === null || $entry['duration_ms'] < $downloadWinner['duration_ms'])) {
                $downloadWinner = $entry;
                $downloadWinnerBody = $attempt['body'];
            }
        }

        if ($downloadWinner === null) {
            $anyNotice = collect($downloadLog)->contains('notice_active', true);
            $anyBlocked = collect($downloadLog)->contains('blocked', true);

            return [
                'ok' => false,
                'body' => null,
                'local_path' => null,
                'name' => $name,
                'version' => $version,
                'edge_ip' => null,
                'cf_cache_status' => null,
                'notice_active' => $anyNotice,
                'message' => match (true) {
                    $anyNotice => self::NOTICE_MESSAGE,
                    $anyBlocked => self::BLOCKED_MESSAGE,
                    default => 'No edge IP returned a valid bundle for '.$name.'.',
                },
                'discover_log' => $discoverLog,
                'download_log' => $downloadLog,
            ];
        }

        Cache::forever(self::PREFERRED_EDGE_CACHE_KEY, $downloadWinner['edge_ip']);

        return [
            'ok' => true,
            'body' => $downloadWinnerBody,
            'local_path' => null,
            'name' => $name,
            'version' => $version,
            'edge_ip' => $downloadWinner['edge_ip'],
            'cf_cache_status' => $downloadWinner['cf_cache_status'],
            'notice_active' => false,
            'message' => null,
            'discover_log' => $discoverLog,
            'download_log' => $downloadLog,
        ];
    }

    /**
     * Classify one /.well-known/ attempt.
     *
     * @param  array{edge_ip: string, status: int, duration_ms: int, bytes: int, error: ?string, headers: array<string, string>, body: string}  $attempt
     */
    private function discoverEntry(array $attempt): array
    {
        $notice = $this->looksLikeBookingNotice($attempt['body']);
        $name = $notice ? null : $this->extractBundleName($attempt['body']);

        return [
            'edge_ip' => $attempt['edge_ip'],
            'status' => $attempt['status'],
            'duration_ms' => $attempt['duration_ms'],
            'error' => $attempt['error'],
            'notice_active' => $notice,
            'blocked' => $this->looksLikeWafBlock($attempt['body']),
            'challenged' => $this->looksLikeChallenge($attempt['body']),
            'name' => $name,
            'version' => $name !== null ? $this->extractVersion($attempt['body']) : null,
            'valid' => $attempt['error'] === null && $attempt['status'] === 200 && ! $notice && $name !== null,
        ];
    }

    /**
     * Classify one asset attempt.
     *
     * @param  array{edge_ip: string, status: int, duration_ms: int, bytes: int, error: ?string, headers: array<string, string>, body: string}  $attempt
     */
    private function downloadEntry(array $attempt): array
    {
        $notice = $this->looksLikeBookingNotice($attempt['body']);
        $isRealJs = ! $notice
            && str_contains(substr($attempt['body'], 0, 200), 'var __defProp')
            && ! $this->looksLikeChallenge($attempt['body']);

        return [
            'edge_ip' => $attempt['edge_ip'],
            'status' => $attempt['status'],
            'duration_ms' => $attempt['duration_ms'],
            'bytes' => $attempt['bytes'],
            'error' => $attempt['error'],
            'notice_active' => $notice,
            'blocked' => $this->looksLikeWafBlock($attempt['body']),
            'cf_cache_status' => $attempt['headers']['cf-cache-status'] ?? null,
            'cf_ray' => $attempt['headers']['cf-ray'] ?? null,
            'is_real_js' => $isRealJs,
            'valid' => $attempt['error'] === null && $attempt['status'] === 200 && $isRealJs,
        ];
    }

    /**
     * GET the same path against every given edge IP in parallel via curl_multi,
     * and wait for all of them to finish (bounded by each handle's own timeouts).
     *
     * Overridable for the same reason execute() is: so tests can drive raceDownload()'s
     * classification with canned responses instead of real network calls.
     *
     * @param  array<int, string>  $edgeIps
     * @return array<int, array{edge_ip: string, status: int, duration_ms: int, bytes: int, error: ?string, headers: array<string, string>, body: string}>
     */
    protected function httpGetMulti(string $path, array $edgeIps): array
    {
        $mh = curl_multi_init();
        $entries = [];

        foreach ($edgeIps as $ip) {
            // A fresh object per iteration so the header-capturing closure inside
            // newHandle() holds a stable reference instead of all closures sharing
            // one reused loop variable (the classic foreach-closure pitfall in PHP).
            $capture = $this->newCapture();
            $ch = $this->newHandle($ip, $capture);
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://'.self::HOST.$path,
                CURLOPT_CONNECTTIMEOUT => self::RACE_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => 30,
            ]);

            curl_multi_add_handle($mh, $ch);
            $entries[spl_object_id($ch)] = [
                'edge_ip' => $ip,
                'ch' => $ch,
                'capture' => $capture,
            ];
        }

        $running = null;
        do {
            $execStatus = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($execStatus === CURLM_CALL_MULTI_PERFORM || $running > 0);

        // curl_errno()/curl_error() on a handle don't reliably reflect the outcome
        // for handles driven through the multi interface — the per-handle result
        // code is only delivered via curl_multi_info_read's CURLMSG_DONE message.
        $resultByHandleId = [];
        while ($info = curl_multi_info_read($mh)) {
            $resultByHandleId[spl_object_id($info['handle'])] = (int) $info['result'];
        }

        $results = [];
        foreach ($entries as $id => $entry) {
            $ch = $entry['ch'];
            $body = curl_multi_getcontent($ch);
            $resultCode = $resultByHandleId[$id] ?? CURLE_OK;
            $error = $resultCode !== CURLE_OK ? curl_strerror($resultCode) : null;

            $results[] = [
                'edge_ip' => $entry['edge_ip'],
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'duration_ms' => (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000),
                'bytes' => is_string($body) ? strlen($body) : 0,
                'error' => $error,
                'headers' => $entry['capture']->headers,
                'body' => is_string($body) ? $body : '',
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    }

    /**
     * The edge IPs to hit. Overridable so tests can point the fetcher at a stub server or
     * a deliberately dead address without touching the public EDGE_IPS constant.
     *
     * @return array<int, string>
     */
    protected function edgeIps(): array
    {
        return self::EDGE_IPS;
    }

    private function newCapture(): \stdClass
    {
        $capture = new \stdClass();
        $capture->headers = [];

        return $capture;
    }

    /**
     * A curl handle pinned to one edge IP, with the response headers collected into
     * $capture. Timeouts are left to the caller — the race and the fast path want very
     * different ones. Brotli is requested (CURLOPT_ENCODING): the bundle is 2.17 MB raw
     * but 772 KB over the wire, which more than halves the transfer.
     *
     * ALPN is switched off deliberately. Cloudflare fingerprints the TLS ClientHello, and
     * an ALPN-advertising libcurl hello is blocked outright on appointment.ivacbd.com —
     * every path, every edge IP, every header set and both HTTP versions answer 403
     * "Sorry, you have been blocked". Dropping the ALPN extension changes the fingerprint
     * enough to be served normally (this is also why python-requests and node fetch, which
     * do not advertise ALPN, sail through while curl does not). Costs nothing else: without
     * ALPN the connection is plain HTTP/1.1, which is all these two requests need.
     */
    private function newHandle(string $ip, \stdClass $capture): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_ENABLE_ALPN => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use ($capture): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $capture->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $resolveAddress = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        curl_setopt($ch, CURLOPT_RESOLVE, [self::HOST.':443:'.$resolveAddress]);

        return $ch;
    }

    /**
     * Run one request on an existing handle, leaving it open so a follow-up request to the
     * same host reuses the connection (and skips the ~60ms TLS handshake).
     *
     * Overridable so tests can drive fetchFastest()'s failover logic with canned responses
     * instead of real network calls.
     *
     * @return array{edge_ip: string, status: int, duration_ms: int, bytes: int, error: ?string, headers: array<string, string>, body: string}
     */
    protected function execute(\CurlHandle $ch, string $edgeIp, string $path, int $timeoutMs): array
    {
        $capture = $this->newCapture();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://'.self::HOST.$path,
            CURLOPT_CONNECTTIMEOUT_MS => self::FAST_CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            // Rebound per request so each response's headers land in a fresh capture
            // instead of accumulating across the reused handle.
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use ($capture): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $capture->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);

        return [
            'edge_ip' => $edgeIp,
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'duration_ms' => (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000),
            'bytes' => is_string($body) ? strlen($body) : 0,
            'error' => $errno !== CURLE_OK ? curl_strerror($errno) : null,
            'headers' => $capture->headers,
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function extractBundleName(string $html): ?string
    {
        if (preg_match('#src="/assets/([A-Za-z0-9._-]+\.js)"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractVersion(string $html): ?string
    {
        if (preg_match('#name="version"\s+content="([^"]+)"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Cloudflare's WAF block page ("Sorry, you have been blocked"), which is a hard 403 with
     * no challenge to solve — distinct from both the interstitial challenge and the booking
     * notice. On this host it fires on the client's TLS fingerprint, so it says nothing about
     * whether IVAC is live.
     */
    private function looksLikeWafBlock(string $body): bool
    {
        return stripos($body, 'Sorry, you have been blocked') !== false
            || stripos($body, 'Attention Required! | Cloudflare') !== false;
    }

    private function looksLikeChallenge(string $body): bool
    {
        return stripos($body, 'Just a moment') !== false
            || stripos($body, 'challenge-platform') !== false
            || stripos($body, 'cf-mitigated') !== false;
    }

    /**
     * IVAC serves a Cloudflare-edge "APPOINTMENT BOOKING GUIDELINES" notice for
     * every path (including static assets) outside the live booking window. While
     * it is active nothing is fetchable — discovery and download both hit it.
     */
    private function looksLikeBookingNotice(string $body): bool
    {
        return stripos($body, 'APPOINTMENT BOOKING GUIDELINES') !== false
            || stripos($body, 'IMPORTANT NOTICE') !== false
            || stripos($body, 'Appointment booking opens at') !== false;
    }
}
