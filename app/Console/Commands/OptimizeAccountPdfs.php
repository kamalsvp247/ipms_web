<?php

namespace App\Console\Commands;

use App\Models\AccountPdf;
use App\Services\Pdf\PdfOptimizer;
use Illuminate\Console\Command;

/**
 * Backfills the Ghostscript-optimized copy of every stored applicant PDF so the bot uploads far
 * fewer bytes to IVAC. Optimizes from the pristine `base64` (never the already-optimized copy), so
 * it is safe to re-run and to force a re-optimization after a quality-setting change.
 */
class OptimizeAccountPdfs extends Command
{
    protected $signature = 'pdfs:optimize {--force : Re-optimize PDFs that already have an optimized copy}';

    protected $description = 'Ghostscript-optimize stored applicant PDFs so the bot uploads fewer bytes to IVAC';

    public function handle(PdfOptimizer $optimizer): int
    {
        $query = AccountPdf::query()->orderBy('id');
        if (! $this->option('force')) {
            $query->whereNull('optimized_base64');
        }

        $pdfs = $query->get(['id', 'account_id', 'name', 'base64', 'optimized_size']);
        if ($pdfs->isEmpty()) {
            $this->info('No PDFs to optimize.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalOriginal = 0;
        $totalOptimized = 0;
        $optimizedCount = 0;

        foreach ($pdfs as $pdf) {
            $result = $optimizer->optimize($pdf->base64);
            $originalBytes = (int) (strlen((string) $pdf->base64) * 3 / 4);

            if ($result === null) {
                // Drop any stale optimized copy (e.g. one made below the current 50 KB floor) so the
                // bot falls back to the pristine original rather than uploading a too-small file.
                $status = 'skipped';
                if ($pdf->optimized_size !== null) {
                    $pdf->update(['optimized_base64' => null, 'optimized_size' => null]);
                    $status = 'reverted';
                }

                $rows[] = [$pdf->id, $this->shorten($pdf->name), $this->kb($originalBytes), '—', $status];
                $totalOriginal += $originalBytes;
                $totalOptimized += $originalBytes;

                continue;
            }

            $pdf->update([
                'optimized_base64' => $result['base64'],
                'original_size' => $result['original_size'],
                'optimized_size' => $result['optimized_size'],
            ]);

            $totalOriginal += $result['original_size'];
            $totalOptimized += $result['optimized_size'];
            $optimizedCount++;

            $saved = 100 * (1 - $result['optimized_size'] / $result['original_size']);
            $rows[] = [
                $pdf->id,
                $this->shorten($pdf->name),
                $this->kb($result['original_size']),
                $this->kb($result['optimized_size']),
                sprintf('-%.0f%%', $saved),
            ];
        }

        $this->table(['ID', 'Name', 'Original', 'Optimized', 'Saved'], $rows);

        $overall = $totalOriginal > 0 ? 100 * (1 - $totalOptimized / $totalOriginal) : 0;
        $this->info(sprintf(
            'Optimized %d/%d PDF(s): %s -> %s (-%.0f%%).',
            $optimizedCount,
            $pdfs->count(),
            $this->kb($totalOriginal),
            $this->kb($totalOptimized),
            $overall,
        ));

        return self::SUCCESS;
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 0).' KB';
    }

    private function shorten(?string $name): string
    {
        $name ??= '(unnamed)';

        return strlen($name) > 32 ? substr($name, 0, 29).'...' : $name;
    }
}
