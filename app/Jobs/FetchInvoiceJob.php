<?php

namespace App\Jobs;

use App\Models\PaymentLink;
use App\Services\Payment\InvoiceArchive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Cache one paid reservation's invoice while its IVAC session is still alive.
 *
 * Dispatched the moment IVAC confirms a payment, because the window is short: the invoice is
 * scoped to the paying account and that account's JWT lasts 899s from sign-in. Miss it and the
 * PDF is unreachable until someone signs the account in again.
 */
class FetchInvoiceJob implements ShouldQueue
{
    use Queueable;

    /**
     * IVAC does not always have the PDF ready the instant it confirms the payment, so a first
     * miss is retried rather than treated as absent.
     */
    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public int $paymentLinkId)
    {
        $this->onConnection('redis')->onQueue('payments');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 45, 90];
    }

    public function handle(InvoiceArchive $archive): void
    {
        $link = PaymentLink::find($this->paymentLinkId);

        if ($link === null || $link->hasStoredInvoice()) {
            return;
        }

        $result = $archive->fetch($link);

        if ($result['ok']) {
            return;
        }

        // Thrown rather than returned so the queue's own backoff gets another go while the
        // account's session may still be alive; the final failure is logged by the worker.
        throw new \RuntimeException(
            "Invoice fetch failed for reservation {$link->reservation_id}: ".$result['error']
        );
    }

    public function failed(\Throwable $e): void
    {
        // Not fatal to anything: the download endpoint still falls back to a live fetch, so this
        // only means the copy was not banked while the session was warm.
        Log::warning('FetchInvoiceJob gave up', [
            'payment_link_id' => $this->paymentLinkId,
            'error' => $e->getMessage(),
        ]);
    }
}
