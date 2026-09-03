<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CensysOriginLookup
{
    private const SEARCH_URL = 'https://search.censys.io/api/v2/hosts/search';

    /**
     * Default Censys query: hosts serving the IVAC wildcard origin certificate on
     * Amazon infrastructure, port 443. These are the only hosts that can front the
     * api.ivacbd.com origin, so the result set is the candidate pool of ELB nodes.
     */
    public const DEFAULT_QUERY =
        'services.tls.certificates.leaf_data.subject.common_name: "*.ivacbd.com" '
        .'and services.port: 443 '
        .'and autonomous_system.name: "AMAZON"';

    /**
     * Run the Censys hosts search and return the distinct IP addresses it reports.
     *
     * @return string[]
     *
     * @throws RuntimeException when credentials are missing or the API call fails.
     */
    public function search(?string $query = null, int $perPage = 100): array
    {
        $setting = Setting::instance();
        $apiId = $setting->censys_api_id;
        $apiSecret = $setting->censys_api_secret;

        if (empty($apiId) || empty($apiSecret)) {
            throw new RuntimeException('Censys API credentials are not configured. Add them in the Censys panel first.');
        }

        $response = Http::withBasicAuth($apiId, $apiSecret)
            ->acceptJson()
            ->timeout(30)
            ->get(self::SEARCH_URL, [
                'q' => $query ?: self::DEFAULT_QUERY,
                'per_page' => max(1, min($perPage, 100)),
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Censys rejected the credentials (HTTP '.$response->status().'). Check the API ID and secret.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Censys search failed (HTTP '.$response->status().').');
        }

        return $this->extractIps($response->body());
    }

    /**
     * Pull every distinct IPv4 address out of a raw Censys response body. Parsing the
     * JSON shape is brittle across Censys API revisions, so we extract addresses by
     * pattern — the same approach used by the manual paste flow.
     *
     * @return string[]
     */
    public function extractIps(string $body): array
    {
        if (! preg_match_all('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $body, $matches)) {
            return [];
        }

        $ips = array_values(array_unique($matches[1]));

        return array_values(array_filter($ips, static function (string $ip): bool {
            foreach (explode('.', $ip) as $octet) {
                if ((int) $octet > 255) {
                    return false;
                }
            }

            return true;
        }));
    }
}
