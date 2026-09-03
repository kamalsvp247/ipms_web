<?php

namespace App\Jobs;

use App\Models\BypassIp;
use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Services\AwsIpRangesFetcher;
use App\Services\BypassIpScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAwsIpv6RegionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 28800;

    public int $tries = 1;

    private const BATCH_CONCURRENCY = 2200;

    private const CONNECT_TIMEOUT_MS = 3000;

    private const READ_TIMEOUT_MS = 3000;

    /** Max /64 subnets to scan per CIDR block (256 × 254 hosts = ~65K probes per block). */
    private const MAX_SUBNETS_PER_BLOCK = 256;

    /** Hosts to probe within each /64 subnet (::1 through ::$hostsPerSubnet). */
    private const HOSTS_PER_SUBNET = 254;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $region,
        public readonly int $resumeOffset = 0,
        public readonly array $cidrOverride = [],
    ) {}

    public function handle(BypassIpScanner $scanner, AwsIpRangesFetcher $aws): void
    {
        $session = IpScanSession::find($this->sessionId);
        if (! $session || ! $session->isRunning()) {
            return;
        }

        $cidrs = $this->cidrOverride ?: $aws->ipv6CidrsForRegion($this->region);

        if (empty($cidrs)) {
            $session->update(['status' => 'stopped', 'completed_at' => now()]);

            return;
        }

        if ($this->resumeOffset === 0) {
            $session->update([
                'subnets' => $cidrs,
                'region' => $this->region,
                'total_candidates' => $aws->countIpv6Ips($cidrs, self::MAX_SUBNETS_PER_BLOCK, self::HOSTS_PER_SUBNET),
                'started_at' => now(),
            ]);
        }

        $batch = [];
        $skipped = 0;

        foreach ($aws->expandIpv6Cidrs($cidrs, self::HOSTS_PER_SUBNET, self::MAX_SUBNETS_PER_BLOCK) as $ip) {
            if ($skipped < $this->resumeOffset) {
                $skipped++;

                continue;
            }

            $batch[] = $ip;

            if (count($batch) < self::BATCH_CONCURRENCY) {
                continue;
            }

            $session->refresh();
            if ($session->status === 'stopping') {
                $session->update(['status' => 'stopped', 'completed_at' => now()]);

                return;
            }
            if ($session->status === 'pausing') {
                $session->update(['status' => 'paused']);

                return;
            }
            if ($session->status !== 'running') {
                return;
            }

            $found = $scanner->probeChunk($batch, self::CONNECT_TIMEOUT_MS, self::READ_TIMEOUT_MS);
            $this->persistFound($session, $found);

            $session->increment('probed_count', count($batch));
            if (! empty($found)) {
                $session->increment('found_count', count($found));
            }
            $batch = [];
        }

        if (! empty($batch)) {
            $found = $scanner->probeChunk($batch, self::CONNECT_TIMEOUT_MS, self::READ_TIMEOUT_MS);
            $this->persistFound($session, $found);
            $session->increment('probed_count', count($batch));
            if (! empty($found)) {
                $session->increment('found_count', count($found));
            }
        }

        $session->update(['status' => 'completed', 'completed_at' => now()]);
    }

    private function persistFound(IpScanSession $session, array $found): void
    {
        if (empty($found)) {
            return;
        }

        $foundIps = array_column($found, 'ip');
        $alreadyConfigured = BypassIp::whereIn('ip', $foundIps)->pluck('ip')->flip()->all();

        foreach ($found as $result) {
            if (isset($alreadyConfigured[$result['ip']])) {
                continue;
            }

            try {
                IpScanResult::firstOrCreate(
                    ['ip' => $result['ip']],
                    [
                        'scan_session_id' => $session->id,
                        'label' => $result['label'],
                        'response_status' => $result['response_status'],
                        'response_message' => is_array($result['response_message']) ? json_encode($result['response_message']) : $result['response_message'],
                        'response_time_ms' => $result['response_time_ms'],
                        'status' => 'pending',
                    ]
                );
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already persisted by a concurrent instance — skip
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        IpScanSession::where('id', $this->sessionId)
            ->whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);
    }
}
