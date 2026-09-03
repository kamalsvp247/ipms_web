<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AwsIpRangesFetcher
{
    private const URL = 'https://ip-ranges.amazonaws.com/ip-ranges.json';

    private const CACHE_KEY = 'aws_ip_ranges_json';

    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Returns CIDR blocks for the given AWS region.
     * Filtered to EC2 service (which includes ELB/ALB/NLB origins).
     *
     * @return string[] e.g. ['13.204.0.0/14', '15.207.0.0/16', ...]
     */
    public function cidrsForRegion(string $region, string $service = 'EC2'): array
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $response = Http::timeout(30)->retry(2, 500)->get(self::URL);

            return $response->successful() ? $response->json() : null;
        });

        if (! is_array($data) || ! isset($data['prefixes'])) {
            return [];
        }

        return collect($data['prefixes'])
            ->where('region', $region)
            ->where('service', $service)
            ->pluck('ip_prefix')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Returns IPv6 CIDR blocks for the given AWS region (from ipv6_prefixes).
     *
     * @return string[] e.g. ['2406:daeb:a000::/40', '2606:7b40:1b06:8100::/56', ...]
     */
    public function ipv6CidrsForRegion(string $region, string $service = 'EC2'): array
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $response = Http::timeout(30)->retry(2, 500)->get(self::URL);

            return $response->successful() ? $response->json() : null;
        });

        if (! is_array($data) || ! isset($data['ipv6_prefixes'])) {
            return [];
        }

        return collect($data['ipv6_prefixes'])
            ->where('region', $region)
            ->where('service', $service)
            ->pluck('ipv6_prefix')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Count scannable IPv6 addresses across the given CIDRs.
     * For blocks larger than /64, up to $maxSubnetsPerBlock /64 subnets are scanned
     * with $hostsPerSubnet hosts each.
     *
     * @param  string[]  $cidrs
     */
    public function countIpv6Ips(array $cidrs, int $maxSubnetsPerBlock = 256, int $hostsPerSubnet = 254): int
    {
        $total = 0;
        foreach ($cidrs as $cidr) {
            [, $len] = explode('/', $cidr);
            $len = (int) $len;
            if ($len >= 64) {
                $total += min($hostsPerSubnet, (1 << (128 - $len)) - 2);
            } else {
                $subnetBits = 64 - $len;
                $numSubnets = min($maxSubnetsPerBlock, 1 << $subnetBits);
                $total += $numSubnets * $hostsPerSubnet;
            }
        }

        return $total;
    }

    /**
     * Yield every candidate IPv6 address across the given CIDRs.
     * For blocks larger than /64, enumerates up to $maxSubnetsPerBlock /64 subnets and
     * probes hosts ::1 through ::$hostsPerSubnet in each.
     * Only byte-aligned prefix lengths (/8, /16, … /64) are supported.
     *
     * @param  string[]  $cidrs
     * @return \Generator<int, string>
     */
    public function expandIpv6Cidrs(array $cidrs, int $hostsPerSubnet = 254, int $maxSubnetsPerBlock = 256): \Generator
    {
        foreach ($cidrs as $cidr) {
            [$prefix, $len] = explode('/', $cidr);
            $len = (int) $len;
            $baseBytes = inet_pton($prefix);

            if ($len >= 64) {
                // Small block — probe hosts directly (last byte only)
                $maxHost = min($hostsPerSubnet, (1 << (128 - $len)) - 2);
                for ($h = 1; $h <= $maxHost; $h++) {
                    $addr = $baseBytes;
                    $addr[15] = chr($h);
                    yield inet_ntop($addr);
                }

                continue;
            }

            // Larger block — break into /64 subnets, probe $hostsPerSubnet hosts each
            $subnetBits = 64 - $len;
            $numSubnets = min($maxSubnetsPerBlock, 1 << $subnetBits);
            $prefixBytes = intdiv($len, 8);

            for ($s = 0; $s < $numSubnets; $s++) {
                $subnetBase = $baseBytes;

                // Write subnet index big-endian into bytes [$prefixBytes..7]
                $carry = $s;
                for ($b = 7; $b >= $prefixBytes; $b--) {
                    $subnetBase[$b] = chr($carry & 0xFF);
                    $carry >>= 8;
                }

                // Zero out host bytes 8–15
                for ($b = 8; $b < 16; $b++) {
                    $subnetBase[$b] = "\x00";
                }

                for ($h = 1; $h <= $hostsPerSubnet; $h++) {
                    $addr = $subnetBase;
                    $addr[15] = chr($h);
                    yield inet_ntop($addr);
                }
            }
        }
    }

    /**
     * Returns only the CIDRs from the given region that contain at least one of the given IPs.
     *
     * @param  string[]  $ips
     * @return string[]
     */
    public function cidrsContainingIps(array $ips, string $region = 'ap-south-1', string $service = 'EC2'): array
    {
        if (empty($ips)) {
            return [];
        }

        $longIps = array_map('ip2long', $ips);

        return array_values(array_filter(
            $this->cidrsForRegion($region, $service),
            function (string $cidr) use ($longIps): bool {
                [$network, $prefix] = explode('/', $cidr);
                $mask = ~((1 << (32 - (int) $prefix)) - 1);
                $base = ip2long($network) & $mask;
                foreach ($longIps as $long) {
                    if (($long & $mask) === $base) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * Total IP count across a list of CIDRs.
     *
     * @param  string[]  $cidrs
     */
    public function countIps(array $cidrs): int
    {
        $total = 0;
        foreach ($cidrs as $cidr) {
            [, $prefix] = explode('/', $cidr);
            $total += 1 << (32 - (int) $prefix);
        }

        return $total;
    }

    /**
     * Yield every IP in the given CIDR list (skips network + broadcast for /24 or smaller).
     *
     * @param  string[]  $cidrs
     * @return \Generator<int, string>
     */
    public function expandCidrs(array $cidrs): \Generator
    {
        foreach ($cidrs as $cidr) {
            [$network, $prefix] = explode('/', $cidr);
            $prefix = (int) $prefix;
            $base = ip2long($network);
            $count = 1 << (32 - $prefix);
            $skipEdges = $prefix >= 24;

            $start = $skipEdges ? 1 : 0;
            $end = $skipEdges ? $count - 1 : $count;

            for ($i = $start; $i < $end; $i++) {
                yield long2ip($base + $i);
            }
        }
    }
}
