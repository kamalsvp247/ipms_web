<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Shrinks applicant PDFs with Ghostscript so the Java bot's upload to IVAC (POST /file/upload-file)
 * moves far fewer bytes on the booking critical path.
 *
 * The optimization is loss-safe for IVAC's OCR by construction: every applicant field on the form is
 * vector text, which Ghostscript keeps as vector (rendered sharp at whatever DPI IVAC rasterizes at),
 * and the mono barcode is kept lossless. Only the colour images — the static emblem letterhead and the
 * face photo, neither of which carries OCR-extracted data — are re-encoded to JPEG. A single emblem
 * stored as raw RGB is typically ~90% of a file, so this alone cuts ~80% of the size with no data loss.
 */
class PdfOptimizer
{
    /**
     * Ghostscript arguments proven against the live application-form PDFs:
     *  - colour images  -> JPEG (QFactor 0.4, ~q80), capped at 300 DPI (a no-op unless a scan exceeds it)
     *  - gray images    -> kept lossless (Flate), so any grayscale scan/barcode is untouched
     *  - mono images    -> kept lossless (CCITT G4), so the 1-D barcode stays crisp for scanning
     *  - vector text/graphics (all OCR-extracted data + the 2-D barcode) -> untouched
     *
     * @var list<string>
     */
    private const GS_ARGS = [
        '-q', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5', '-dDetectDuplicateImages=true',
        '-dDownsampleColorImages=true', '-dColorImageResolution=300', '-dColorImageDownsampleThreshold=1.5',
        '-dAutoFilterColorImages=false', '-dEncodeColorImages=true', '-dColorImageFilter=/DCTEncode',
        '-dDownsampleGrayImages=false', '-dAutoFilterGrayImages=false', '-dEncodeGrayImages=true', '-dGrayImageFilter=/FlateEncode',
        '-dDownsampleMonoImages=false', '-dEncodeMonoImages=true', '-dMonoImageFilter=/CCITTFaxEncode',
    ];

    /**
     * JPEG quality for the colour images. QFactor 0.4 is roughly quality 80 — indistinguishable on the
     * emblem/photo while collapsing a raw-RGB emblem to a few KB.
     */
    private const QUALITY_PS = '<< /ColorImageDict << /QFactor 0.4 /Blend 1 /HSamples [2 1 1 2] /VSamples [2 1 1 2] >> >> setdistillerparams';

    /**
     * Floor for the optimized output: IVAC rejects PDFs that are too small, so an optimized copy below
     * this size is discarded and the pristine original is kept instead.
     */
    private const MIN_OPTIMIZED_BYTES = 50 * 1024;

    public function __construct(
        private readonly string $binary = 'gs',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    /**
     * Optimizes a Base64-encoded PDF. Returns the optimized Base64 with byte sizes, or null when the
     * input is not a PDF, Ghostscript is unavailable/fails, the result is not a smaller valid PDF, the
     * result falls below the 50 KB floor, or the page count changed. A null return means "keep the
     * original untouched".
     *
     * @return array{base64: string, original_size: int, optimized_size: int}|null
     */
    public function optimize(string $base64): ?array
    {
        $input = base64_decode($base64, true);
        if ($input === false || ! str_starts_with($input, '%PDF-')) {
            return null;
        }

        $inFile = tempnam(sys_get_temp_dir(), 'pdfopt_in_');
        $outFile = tempnam(sys_get_temp_dir(), 'pdfopt_out_');
        if ($inFile === false || $outFile === false) {
            if (is_string($inFile)) {
                @unlink($inFile);
            }

            return null;
        }

        try {
            file_put_contents($inFile, $input);

            $process = new Process(array_merge(
                [$this->binary, '-o', $outFile],
                self::GS_ARGS,
                ['-c', self::QUALITY_PS, '-f', $inFile],
            ));
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($outFile)) {
                Log::warning('PdfOptimizer: ghostscript failed', ['error' => $process->getErrorOutput()]);

                return null;
            }

            $output = file_get_contents($outFile);
            if ($output === false || ! str_starts_with($output, '%PDF-')) {
                return null;
            }

            $originalSize = strlen($input);
            $optimizedSize = strlen($output);

            // Only accept a genuinely smaller file that still parses and preserves every page, and never
            // one that shrinks below the 50 KB floor IVAC will reject.
            if ($optimizedSize >= $originalSize || $optimizedSize < self::MIN_OPTIMIZED_BYTES) {
                return null;
            }
            $outPages = $this->pageCount($outFile);
            if ($outPages === null || $outPages !== $this->pageCount($inFile)) {
                return null;
            }

            return [
                'base64' => base64_encode($output),
                'original_size' => $originalSize,
                'optimized_size' => $optimizedSize,
            ];
        } catch (ProcessTimedOutException) {
            Log::warning('PdfOptimizer: ghostscript timed out');

            return null;
        } finally {
            @unlink($inFile);
            @unlink($outFile);
        }
    }

    /**
     * Returns the PDF's page count via Ghostscript, or null when the file will not parse — which also
     * serves as a structural validity check on the optimized output. Runs with SAFER on, granting read
     * access to only the single file being counted.
     */
    private function pageCount(string $file): ?int
    {
        $process = new Process([
            $this->binary, '-q', '-dNODISPLAY', '--permit-file-read='.$file,
            '-c', '('.$file.') (r) file runpdfbegin pdfpagecount = quit',
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        $out = trim($process->getOutput());

        return $process->isSuccessful() && ctype_digit($out) ? (int) $out : null;
    }
}
