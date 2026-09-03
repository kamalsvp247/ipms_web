<?php

namespace App\Services;

use App\Support\IvacBundleMarkers;

class BypassIpScanner
{
    private const PROBE_URL_PATH = '/iams/api/v1/auth/sign-in-v4';

    /**
     * Cloudflare only challenges HTML/non-static routes on appointment.ivacbd.com;
     * GET /.well-known/ leaks index.html (naming the current bundle) challenge-free.
     * Probing this instead of a fixed bundle asset path means a hit self-verifies
     * against whatever bundle is actually live, with no filename to keep in sync.
     */
    private const FRONTEND_PROBE_PATH = '/.well-known/';

    private const CONNECT_TIMEOUT = 1;

    private const READ_TIMEOUT = 3;

    /**
     * Substring that must appear in the peer certificate Subject/SAN for an IP
     * to count as a genuine IVAC origin. Any AWS ELB returns 503 to an unknown
     * Host header, so the status code alone is not enough — only the IPs whose
     * TLS certificate is issued for *.ivacbd.com are real origin nodes.
     */
    public const ORIGIN_CERT_NEEDLE = 'ivacbd';

    /**
     * Determine whether a curl CERTINFO payload belongs to the IVAC origin by
     * matching the leaf certificate Subject or Subject Alternative Name.
     *
     * @param  array<int, array<string, string>>|null  $certInfo
     */
    public static function certMatchesOrigin(?array $certInfo): bool
    {
        if (empty($certInfo)) {
            return false;
        }

        $leaf = $certInfo[0] ?? [];
        $subject = $leaf['Subject'] ?? '';
        $san = $leaf['X509v3 Subject Alternative Name'] ?? '';

        return stripos($subject, self::ORIGIN_CERT_NEEDLE) !== false
            || stripos($san, self::ORIGIN_CERT_NEEDLE) !== false;
    }

    /**
     * @param  string[]  $existingIps
     * @return array{subnets: string[], candidates: string[]}
     */
    public function buildCandidates(array $existingIps): array
    {
        $subnets = collect($existingIps)
            ->map(fn ($ip) => implode('.', array_slice(explode('.', $ip), 0, 3)))
            ->unique()
            ->values()
            ->toArray();

        $candidates = [];
        foreach ($subnets as $subnet) {
            for ($i = 1; $i <= 254; $i++) {
                $ip = "{$subnet}.{$i}";
                if (! in_array($ip, $existingIps)) {
                    $candidates[] = $ip;
                }
            }
        }

        return ['subnets' => $subnets, 'candidates' => $candidates];
    }

    /**
     * Probe a list of IPs concurrently using curl_multi.
     * Returns only IPs confirmed as live IVAC app nodes: cert matches *.ivacbd.com
     * AND the response body is valid JSON (meaning the request reached the IVAC app,
     * not just an ELB with drained/unhealthy targets which returns HTML 503).
     *
     * @param  string[]  $ips
     * @return array<int, array{ip: string, label: string, response_status: int, response_message: string|null, response_time_ms: int}>
     */
    public function probe(array $ips, int $concurrency = 80): array
    {
        $valid = [];
        foreach (array_chunk($ips, $concurrency) as $chunk) {
            $valid = array_merge($valid, $this->probeChunk($chunk));
        }

        return $valid;
    }

    /**
     * @param  string[]  $chunk
     * @return array<int, array{ip: string, label: string, response_status: int, response_message: string|null, response_time_ms: int}>
     */
    public function probeChunk(array $chunk, int $connectTimeoutMs = 1000, int $readTimeoutMs = 3000): array
    {
        $valid = [];
        $mh = curl_multi_init();
        $handles = [];
        $startTimes = [];

        foreach ($chunk as $ip) {
            $ch = curl_init();
            $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://{$host}".self::PROBE_URL_PATH,
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $readTimeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_HTTPHEADER => ['Host: api.ivacbd.com', 'User-Agent: BLITZ-Portal/1.0'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CERTINFO => true,
            ]);
            $handles[$ip] = $ch;
            $startTimes[$ip] = microtime(true);
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $ip => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            $responseTimeMs = (int) round((microtime(true) - $startTimes[$ip]) * 1000);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($response !== false && self::certMatchesOrigin($certInfo)) {
                $decoded = json_decode($response, true);

                // Require JSON body — a drained ELB node returns an HTML 503 (awselb/2.0)
                // and passes the cert check but never serves real API traffic.
                // Only a response that reached the IVAC app layer will be JSON.
                if (! is_array($decoded)) {
                    continue;
                }

                $isIpv6 = str_contains($ip, ':');
                $label = $isIpv6
                    ? 'IPv6 Scan - '.implode(':', array_slice(explode(':', $ip), 0, 4)).'::/64'
                    : 'AWS Scan - '.implode('.', array_slice(explode('.', $ip), 0, 3)).'.x';
                $msg = $decoded['message'] ?? null;
                $valid[] = [
                    'ip' => $ip,
                    'label' => $label,
                    'response_status' => (int) ($decoded['statusCode'] ?? $httpCode),
                    'response_message' => is_array($msg) ? json_encode($msg) : $msg,
                    'response_time_ms' => $responseTimeMs,
                ];
            }
        }

        curl_multi_close($mh);

        return $valid;
    }

    /**
     * Probe a chunk of IPs for the IVAC booking frontend (appointment.ivacbd.com).
     * A hit is a challenge-free /.well-known/ response that names a bundle —
     * confirming the IP serves the frontend directly (i.e., it is the frontend
     * origin behind CF), without depending on knowing today's exact bundle path.
     *
     * @param  string[]  $chunk
     * @return array<int, array{ip: string, label: string, response_status: int, response_message: string|null, response_time_ms: int}>
     */
    public function probeFrontendChunk(array $chunk, int $connectTimeoutMs = 3000, int $readTimeoutMs = 5000): array
    {
        $valid = [];
        $mh = curl_multi_init();
        $handles = [];
        $startTimes = [];

        foreach ($chunk as $ip) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://{$ip}".self::FRONTEND_PROBE_PATH,
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $readTimeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_HTTPHEADER => [
                    'Host: appointment.ivacbd.com',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Cache-Control: no-cache',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $handles[$ip] = $ch;
            $startTimes[$ip] = microtime(true);
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $ip => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string) curl_multi_getcontent($ch);
            $responseTimeMs = (int) round((microtime(true) - $startTimes[$ip]) * 1000);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($httpCode !== 200 || IvacBundleMarkers::looksLikeChallenge($body) || IvacBundleMarkers::looksLikeBookingNotice($body)) {
                continue;
            }

            $name = IvacBundleMarkers::extractBundleName($body);
            if ($name === null) {
                continue;
            }

            $version = IvacBundleMarkers::extractVersion($body);
            $label = 'Frontend - '.implode('.', array_slice(explode('.', $ip), 0, 3)).'.x';
            $valid[] = [
                'ip' => $ip,
                'label' => $label,
                'response_status' => $httpCode,
                'response_message' => 'appointment.ivacbd.com · '.$name.($version ? ' (v'.$version.')' : ''),
                'response_time_ms' => $responseTimeMs,
            ];
        }

        curl_multi_close($mh);

        return $valid;
    }
}
