<?php

namespace App\Console\Commands;

use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Services\Payment\AutoPaymentDispatcher;
use App\Support\PaymentWalletLock;
use Illuminate\Console\Command;

/**
 * Safety net for auto payment.
 *
 * Dispatch normally happens inline when the bot posts a payment link. This sweep catches the
 * cases that path cannot: a queue outage, a worker restart mid-job, or an attempt deferred by the
 * concurrency cap.
 */
class SweepAutoPayments extends Command
{
    protected $signature = 'payments:sweep-auto {--minutes=30 : How far back to look for unpaid links}';

    protected $description = 'Re-dispatch auto payment for recent links that have no callback URL yet.';

    public function handle(AutoPaymentDispatcher $dispatcher): int
    {
        $lookback = now()->subMinutes((int) $this->option('minutes'));

        // A link older than its expiry window can never be paid, so the floor is whichever is
        // more recent: the lookback window or the oldest still-live link.
        $since = $lookback->max(now()->subMinutes(PaymentLink::EXPIRY_MINUTES));

        $links = PaymentLink::query()
            ->where('created_at', '>=', $since)
            ->where('is_fake', false)
            ->whereNull('callback_url')
            ->whereNotNull('reservation_id')
            ->whereHas('account', fn ($q) => $q->autoPaymentReady()->autoPaymentArmed())
            // A running attempt owns the link; anything already succeeded is done.
            ->whereDoesntHave('automationAttempt', fn ($q) => $q->whereIn('status', [
                PaymentAutomationAttempt::STATUS_RUNNING,
                PaymentAutomationAttempt::STATUS_SUCCEEDED,
            ]))
            ->with('account')
            ->orderByDesc('id')
            ->get()
            // One candidate per account — the newest link, since IVAC supersedes the rest.
            ->unique('account_phone')
            ->values();

        $candidates = $links->count();

        $walletKey = fn (PaymentLink $link): string => PaymentWalletLock::normalize(
            (string) $link->account?->auto_payment_wallet,
        );

        // A wallet whose lock is held cannot make progress, so dispatching only produces a
        // deferral. Running attempts are a superset of held locks (the lock is taken after the job
        // claims the row), which is the safe side to err on for an advisory check.
        $busyWallets = PaymentAutomationAttempt::where('status', PaymentAutomationAttempt::STATUS_RUNNING)
            ->with('account')
            ->get()
            ->map(fn (PaymentAutomationAttempt $attempt): string => PaymentWalletLock::normalize(
                (string) $attempt->account?->auto_payment_wallet,
            ))
            ->filter()
            ->unique()
            ->all();

        $links = $links->reject(fn (PaymentLink $link): bool => in_array($walletKey($link), $busyWallets, true));
        $skippedBusy = $candidates - $links->count();

        $beforeWalletDedupe = $links->count();
        $links = $links
            // Accounts sharing a wallet are serialised anyway, so give the turn to the link with the
            // least life left rather than letting a fresher one — which can afford to wait another
            // minute — starve it.
            ->sortBy('created_at')
            ->unique($walletKey)
            ->values();
        $skippedSharedWallet = $beforeWalletDedupe - $links->count();

        $dispatched = 0;
        foreach ($links as $link) {
            if ($dispatcher->dispatchFor($link)) {
                $dispatched++;
            }
        }

        $this->info(
            "Swept {$candidates} candidate link(s); dispatched {$dispatched}, "
            ."skipped {$skippedBusy} on a busy wallet and {$skippedSharedWallet} sharing a wallet."
        );

        return self::SUCCESS;
    }
}
