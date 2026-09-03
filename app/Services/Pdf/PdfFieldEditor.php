<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Rewrites the applicant's identity fields inside an Indian visa application form PDF.
 *
 * The form is flat vector text with no AcroForm fields, so the edit is a redact-and-overlay: the old
 * value's span is located geometrically (by its label and the x origin of the value column), painted
 * over, and the replacement drawn on the original baseline. That work is done by PyMuPDF in
 * app/Scripts/edit_passport_pdf.py — this class is the PHP side of that call.
 *
 * The script exits 0 whenever it placed at least ONE field, so a label it could not locate is silently
 * skipped. Callers that depend on a field actually landing must verify the result with
 * App\Support\VisaFormPdfParser, which reads the same coordinates back and is built to see through the
 * overlays this writes.
 */
class PdfFieldEditor
{
    /**
     * Fields the script knows how to place. Anything else is dropped before the call rather than being
     * passed through and ignored.
     *
     * @var list<string>
     */
    public const FIELDS = ['surname', 'given_name', 'passport_no', 'nid', 'phone', 'email'];

    public function __construct(
        private readonly string $binary = 'python3',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    /**
     * Applies the given field values to a PDF and returns the edited bytes, or null when the input is
     * not a PDF, no known field was supplied, or the script failed / located nothing to replace.
     *
     * Values are written as given (the script upper-cases everything except the phone).
     *
     * @param  array<string, string|null>  $fields  keyed by self::FIELDS; null/blank entries are skipped
     */
    public function edit(string $pdf, array $fields): ?string
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            return null;
        }

        $payload = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $payload[$field] = trim($value);
            }
        }

        if ($payload === []) {
            return null;
        }

        $inFile = tempnam(sys_get_temp_dir(), 'pdfedit_in_');
        $outFile = tempnam(sys_get_temp_dir(), 'pdfedit_out_');
        if ($inFile === false || $outFile === false) {
            if (is_string($inFile)) {
                @unlink($inFile);
            }

            return null;
        }

        try {
            file_put_contents($inFile, $pdf);

            $process = new Process([
                $this->binary,
                base_path('app/Scripts/edit_passport_pdf.py'),
                $inFile,
                $outFile,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('PdfFieldEditor: edit script failed', [
                    'exit_code' => $process->getExitCode(),
                    'error' => $process->getErrorOutput(),
                    'fields' => array_keys($payload),
                ]);

                return null;
            }

            $output = file_get_contents($outFile);

            return ($output === false || ! str_starts_with($output, '%PDF-')) ? null : $output;
        } catch (ProcessTimedOutException) {
            Log::warning('PdfFieldEditor: edit script timed out');

            return null;
        } finally {
            @unlink($inFile);
            @unlink($outFile);
        }
    }
}
