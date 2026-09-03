<?php

namespace App\Jobs;

use App\Models\BypassIp;
use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Services\BypassIpScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanSubnetJob implements ShouldQueue
{
    use Queueable;

    // Max 10 minutes before the queue worker kills the job
    public int $timeout = 600;

    // No automatic retries — a failed scan should stay failed
    public int $tries = 1;

    private const BATCH_CONCURRENCY = 2200;

    private const BATCH_DELAY_US = 0;

    // How often (in batches) to flush probed_count to DB
    private const PROGRESS_FLUSH_EVERY = 5;

    public function __construct(public readonly int $sessionId) {}

    public function handle(BypassIpScanner $scanner): void
    {
        $session = IpScanSession::find($this->sessionId);
        if (! $session || ! $session->isRunning()) {
            return;
        }

        $existingIps = BypassIp::pluck('ip')->toArray();
        ['subnets' => $subnets, 'candidates' => $candidates] = $scanner->buildCandidates($existingIps);

        $session->update([
            'subnets' => $subnets,
            'total_candidates' => count($candidates),
            'started_at' => now(),
        ]);

        $chunks = array_chunk($candidates, self::BATCH_CONCURRENCY);
        $probedSinceFlush = 0;
        $batchIndex = 0;

        foreach ($chunks as $chunk) {
            // Check stop flag before every batch
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

            $found = $scanner->probeChunk($chunk, 3000, 3000);

            foreach ($found as $result) {
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
            }

            $probedSinceFlush += count($chunk);
            $batchIndex++;

            // Flush progress to DB periodically to reduce write load
            if ($batchIndex % self::PROGRESS_FLUSH_EVERY === 0) {
                $session->increment('probed_count', $probedSinceFlush);
                $session->increment('found_count', count($found));
                $probedSinceFlush = 0;
            }

            usleep(self::BATCH_DELAY_US);
        }

        // Final flush for any remainder
        if ($probedSinceFlush > 0) {
            $session->increment('probed_count', $probedSinceFlush);
        }

        $session->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        IpScanSession::where('id', $this->sessionId)
            ->whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);
    }
}
