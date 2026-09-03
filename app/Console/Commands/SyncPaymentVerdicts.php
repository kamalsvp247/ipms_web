<?php

namespace App\Console\Commands;

use App\Jobs\FetchInvoiceJob;
use App\Models\PaymentLink;
use Illuminate\Console\Command;

/**
 * Set Payment Status from IVAC's own redirect, for links that predate that being automatic.
 *
 * Rows judged by hand are left alone: only `unread` ones are touched, so a human's correction is
 * never overwritten by a replay of this command.
 */
class SyncPaymentVerdicts extends Command
{
    protected $signature = 'payment-links:sync-verdicts
                            {--fetch-invoices : Queue an invoice fetch for each newly-paid link}';

    protected $description = "Backfill Payment Status from IVAC's callback redirect";

    public function handle(): int
    {
        $links = PaymentLink::whereNotNull('callback_response')
            ->where('review_status', 'unread')
            ->where('is_fake', false)
            ->get();

        $paid = 0;
        $declined = 0;
        $queued = 0;

        foreach ($links as $link) {
            $verdict = $link->ivacVerdict();

            if ($verdict === null) {
                continue;
            }

            $link->update(['review_status' => $verdict]);
            $verdict === 'succeeded' ? $paid++ : $declined++;

            if ($verdict === 'succeeded' && $this->option('fetch-invoices') && ! $link->hasStoredInvoice()) {
                FetchInvoiceJob::dispatch($link->id);
                $queued++;
            }
        }

        $this->info("Marked {$paid} paid and {$declined} declined out of {$links->count()} unjudged link(s).");

        if ($queued > 0) {
            $this->info("Queued {$queued} invoice fetch(es).");
        }

        return self::SUCCESS;
    }
}
