<?php

namespace App\Jobs;

use App\Models\PaymentAutomationAttempt;
use App\Services\Payment\PaymentAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Drives one dg-epay checkout to completion for one payment link.
 *
 * tries = 1 on purpose: this job spends real money, so retry policy is owned by the attempt row
 * (which counts tries and caps them) and the sweep, never by the queue's automatic retry.
 */
class AutoPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $attemptId)
    {
        $this->onConnection('redis')->onQueue('payments');
    }

    public function handle(PaymentAutomationService $service): void
    {
        $attempt = $this->claim();
        if ($attempt === null) {
            // Another worker already owns this attempt, or it is finished / out of tries.
            return;
        }

        $service->run($attempt);
    }

    /**
     * Atomically take ownership of the attempt.
     *
     * The status guard is the mutex: only a row still pending or failed can move to running, so a
     * duplicate dispatch cannot start a second browser against the same link.
     */
    private function claim(): ?PaymentAutomationAttempt
    {
        $attempt = PaymentAutomationAttempt::find($this->attemptId);
        if ($attempt === null || ! $attempt->isRetryable()) {
            return null;
        }

        $claimed = DB::table('payment_automation_attempts')
            ->where('id', $attempt->id)
            ->whereIn('status', [
                PaymentAutomationAttempt::STATUS_PENDING,
                PaymentAutomationAttempt::STATUS_FAILED,
            ])
            ->update([
                'status' => PaymentAutomationAttempt::STATUS_RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $attempt->refresh() : null;
    }

    /**
     * A crashed worker must not leave the row stuck in running, or the sweep will skip it forever.
     */
    public function failed(?\Throwable $exception): void
    {
        PaymentAutomationAttempt::where('id', $this->attemptId)
            ->where('status', PaymentAutomationAttempt::STATUS_RUNNING)
            ->update([
                'status' => PaymentAutomationAttempt::STATUS_FAILED,
                'last_error' => $exception?->getMessage() ?? 'Job failed',
                'finished_at' => now(),
            ]);
    }
}
