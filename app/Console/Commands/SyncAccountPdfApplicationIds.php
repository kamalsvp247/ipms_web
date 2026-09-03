<?php

namespace App\Console\Commands;

use App\Models\AccountPdf;
use App\Support\VisaFormPdfParser;
use Illuminate\Console\Command;

/**
 * Backfills the "Application Id" (web file number) printed under the photo barcode of every stored
 * applicant PDF. New uploads get it written by App\Services\AccountService; this exists for rows
 * stored before the column did, and to re-read the corpus after a parser change.
 */
class SyncAccountPdfApplicationIds extends Command
{
    protected $signature = 'pdfs:application-ids {--force : Re-read PDFs that already carry an application ID}';

    protected $description = 'Read the IVAC application ID off stored applicant PDFs into account_pdfs.application_id';

    public function handle(): int
    {
        $query = AccountPdf::query()->orderBy('id');
        if (! $this->option('force')) {
            $query->whereNull('application_id');
        }

        $read = 0;
        $unreadable = [];
        $total = 0;

        $query->select('id', 'account_id', 'name', 'base64')->chunkById(100, function ($pdfs) use (&$read, &$unreadable, &$total): void {
            foreach ($pdfs as $pdf) {
                $total++;
                $binary = base64_decode((string) $pdf->base64, true);
                $applicationId = $binary !== false && str_starts_with($binary, '%PDF-')
                    ? VisaFormPdfParser::applicationId($binary)
                    : null;

                if ($applicationId === null) {
                    $unreadable[] = [$pdf->id, $pdf->account_id, $this->shorten($pdf->name)];

                    continue;
                }

                // Terminal write on a chunked read: touch only this column so a concurrent PDF
                // upload for the same row is not overwritten with the copy read here.
                AccountPdf::query()->whereKey($pdf->id)->update(['application_id' => $applicationId]);
                $read++;
            }
        });

        if ($unreadable !== []) {
            $this->warn(sprintf('%d PDF(s) carry no readable application ID:', count($unreadable)));
            $this->table(['ID', 'Account', 'Name'], $unreadable);
        }

        $this->info(sprintf('Read %d/%d application ID(s).', $read, $total));

        return self::SUCCESS;
    }

    private function shorten(?string $name): string
    {
        $name ??= '(unnamed)';

        return strlen($name) > 40 ? substr($name, 0, 37).'...' : $name;
    }
}
