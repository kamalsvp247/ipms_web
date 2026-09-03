<?php

namespace App\Jobs;

use App\Models\BypassIp;
use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Services\AwsIpRangesFetcher;
use App\Services\BypassIpScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAwsRegionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 28800;

    public int $tries = 1;

    private const BATCH_CONCURRENCY = 2200;

    private const CONNECT_TIMEOUT_MS = 3000;

    private const READ_TIMEOUT_MS = 3000;

    private const BATCH_DELAY_US = 0;

    /** Known-good AWS ap-south-1 CIDRs that contain confirmed bypass IPs — always scanned first. */
    private const PRIORITY_CIDRS = [
        '13.126.0.0/15',
        '13.202.0.0/15',
        '13.204.0.0/14',
        '13.232.0.0/14',
        '15.206.0.0/15',
        '3.108.0.0/14',
        '3.6.0.0/15',
        '35.154.0.0/16',
        '43.204.0.0/15',
        '52.66.0.0/16',
        '65.0.0.0/14',
    ];

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

        $cidrs = $this->cidrOverride ?: $this->prioritizeCidrs($aws->cidrsForRegion($this->region));

        if (empty($cidrs)) {
            $session->update(['status' => 'stopped', 'completed_at' => now()]);

            return;
        }

        if ($this->resumeOffset === 0) {
            $session->update([
                'subnets' => $cidrs,
                'region' => $this->region,
                'total_candidates' => $aws->countIps($cidrs),
                'started_at' => now(),
            ]);
        }

        $batch = [];
        $skipped = 0;

        foreach ($aws->expandCidrs($cidrs) as $ip) {
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

            usleep(self::BATCH_DELAY_US);
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

    /**
     * Move the known-good priority CIDRs to the front, then append the rest.
     *
     * @param  string[]  $cidrs
     * @return string[]
     */
    private function prioritizeCidrs(array $cidrs): array
    {
        $prioritySet = array_flip(self::PRIORITY_CIDRS);

        $priority = [];
        $rest = [];
        foreach ($cidrs as $cidr) {
            if (isset($prioritySet[$cidr])) {
                $priority[] = $cidr;
            } else {
                $rest[] = $cidr;
            }
        }

        // Preserve the defined order within the priority block
        usort($priority, fn ($a, $b) => $prioritySet[$a] <=> $prioritySet[$b]);

        return array_merge($priority, $rest);
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
                        'label' => "AWS {$this->region} - {$result['ip']}",
                        'response_status' => $result['response_status'],
                        'response_message' => is_array($result['response_message']) ? json_encode($result['response_message']) : $result['response_message'],
                        'response_time_ms' => $result['response_time_ms'],
                        'status' => 'pending',
                    ]
                );
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already persisted by a concurrent job instance — skip
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
