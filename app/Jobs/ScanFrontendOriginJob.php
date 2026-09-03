<?php

namespace App\Jobs;

use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Services\AwsIpRangesFetcher;
use App\Services\BypassIpScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanFrontendOriginJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 28800;

    public int $tries = 1;

    private const BATCH_CONCURRENCY = 2200;

    private const CONNECT_TIMEOUT_MS = 3000;

    private const READ_TIMEOUT_MS = 5000;

    /** Known AWS ap-south-1 CIDRs — scanned first to find the frontend origin faster. */
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
        public readonly int $resumeOffset = 0,
        public readonly array $cidrOverride = [],
    ) {}

    public function handle(BypassIpScanner $scanner, AwsIpRangesFetcher $aws): void
    {
        $session = IpScanSession::find($this->sessionId);
        if (! $session || ! $session->isRunning()) {
            return;
        }

        $cidrs = $this->cidrOverride ?: $this->prioritizeCidrs($aws->cidrsForRegion('ap-south-1'));

        if (empty($cidrs)) {
            $session->update(['status' => 'stopped', 'completed_at' => now()]);

            return;
        }

        if ($this->resumeOffset === 0) {
            $session->update([
                'subnets' => $cidrs,
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

            $found = $scanner->probeFrontendChunk($batch, self::CONNECT_TIMEOUT_MS, self::READ_TIMEOUT_MS);
            $this->persistFound($session, $found);

            $session->increment('probed_count', count($batch));
            if (! empty($found)) {
                $session->increment('found_count', count($found));
            }
            $batch = [];
        }

        if (! empty($batch)) {
            $found = $scanner->probeFrontendChunk($batch, self::CONNECT_TIMEOUT_MS, self::READ_TIMEOUT_MS);
            $this->persistFound($session, $found);
            $session->increment('probed_count', count($batch));
            if (! empty($found)) {
                $session->increment('found_count', count($found));
            }
        }

        $session->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
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

        usort($priority, fn ($a, $b) => $prioritySet[$a] <=> $prioritySet[$b]);

        return array_merge($priority, $rest);
    }

    private function persistFound(IpScanSession $session, array $found): void
    {
        if (empty($found)) {
            return;
        }

        foreach ($found as $result) {
            try {
                IpScanResult::firstOrCreate(
                    ['ip' => $result['ip']],
                    [
                        'scan_session_id' => $session->id,
                        'label' => $result['label'],
                        'response_status' => $result['response_status'],
                        'response_message' => $result['response_message'],
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
