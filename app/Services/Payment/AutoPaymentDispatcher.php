<?php

namespace App\Services\Payment;

use App\Jobs\AutoPaymentJob;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use Illuminate\Database\QueryException;

/**
 * Decides whether a payment link should be paid automatically, and queues it exactly once.
 *
 * Both entry points funnel through here — the bot's link ingest and the per-minute sweep — so the
 * eligibility rules and the create-once semantics live in one place.
 */
class AutoPaymentDispatcher
{
    /**
     * Queue an automation run for this link if it qualifies.
     *
     * @return bool Whether a job was dispatched
     */
    public function dispatchFor(PaymentLink $link): bool
    {
        $attempt = $this->eligibleAttempt($link);
        if ($attempt === null) {
            return false;
        }

        AutoPaymentJob::dispatch($attempt->id);

        return true;
    }

    /**
     * The attempt row to run, or null when this link must not be auto-paid.
     */
    public function eligibleAttempt(PaymentLink $link): ?PaymentAutomationAttempt
    {
        if (! $link->isAutomatable()) {
            return null;
        }

        // A callback already in hand means the payment is done (by a human or an earlier run).
        if (filled($link->callback_url)) {
            return null;
        }

        // An expired checkout cannot be completed, so starting a browser on it only wastes a
        // slot and produces a guaranteed failure.
        if ($link->isExpired()) {
            return null;
        }

        // IVAC reissues a link per attempt; only the newest one for the account is worth paying.
        if ($link->isSupersededForAccount()) {
            return null;
        }

        $account = $link->account ?? $link->account()->first();
        if ($account === null || ! $account->isAutoPaymentReady()) {
            return null;
        }

        // IVAC reissues links after a payment succeeds, so without this every one of them would be
        // paid in turn. No attempt row is created at all, which is what lets the automation log
        // explain the skip instead of showing a failed run.
        if ($account->hasCompletedPayment()) {
            return null;
        }

        $existing = PaymentAutomationAttempt::where('payment_link_id', $link->id)->first();
        if ($existing !== null) {
            return $existing->isRetryable() ? $existing : null;
        }

        try {
            return PaymentAutomationAttempt::create([
                'payment_link_id' => $link->id,
                'account_id' => $account->id,
                'method' => $account->auto_payment_method,
                'status' => PaymentAutomationAttempt::STATUS_PENDING,
            ]);
        } catch (QueryException $e) {
            // Unique constraint on payment_link_id: a concurrent caller won the race, and its job
            // is already queued. Losing here is the correct outcome, not an error.
            return null;
        }
    }
}
