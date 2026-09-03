<?php

namespace App\Services\Pdf;

use App\Support\VisaFormPdfParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Reads the "Web Registration Date" printed down the left margin of an IVAC application form.
 *
 * The label is rotated 90 degrees, so a positional read interleaves its glyphs with the column
 * printed beside it and some generators shred it beyond matching ("RegistratioRelationName").
 * Painting order keeps the rotated run contiguous, so both strategies below read the date out of
 * that order rather than off the page layout:
 *
 *  - VisaFormPdfParser::rawText() inflates the content streams in-process. No dependency, no process
 *    spawn, and it resolves every form in the current corpus.
 *  - Ghostscript txtwrite -dTextFormat=0 emits one XML <char> per glyph, used only when the first
 *    strategy finds nothing — it also reads the stream encodings that the in-process decoder skips.
 */
class PdfRegistrationDate
{
    /** Maximum age, in days, an application form may carry and still be accepted for upload. */
    public const MAX_AGE_DAYS = 30;

    /** @var array<string, int> */
    private const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** IVAC prints the form in Bangladesh local time, so "today" must be measured there too. */
    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private readonly string $binary = 'gs',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * Inspects a Base64-encoded application form.
     *
     * `date` is null when the PDF will not parse or carries no readable registration date, and
     * `expired` is then false — an unreadable form is deliberately allowed through, so a missing or
     * broken Ghostscript degrades to "no age check" rather than rejecting every upload in the portal.
     *
     * @return array{date: ?CarbonImmutable, age_days: ?int, expired: bool}
     */
    public function inspect(string $base64): array
    {
        $date = $this->extract($base64);
        if ($date === null) {
            return ['date' => null, 'age_days' => null, 'expired' => false];
        }

        $ageDays = (int) $date->diffInDays(CarbonImmutable::now(self::TIMEZONE)->startOfDay());

        return [
            'date' => $date,
            'age_days' => $ageDays,
            'expired' => $ageDays > self::MAX_AGE_DAYS,
        ];
    }

    /**
     * Returns the form's web registration date at midnight Dhaka time, or null when it cannot be read.
     */
    public function extract(string $base64): ?CarbonImmutable
    {
        $input = base64_decode($base64, true);
        if ($input === false || ! str_starts_with($input, '%PDF-')) {
            return null;
        }

        return $this->parse(VisaFormPdfParser::rawText($input)) ?? $this->extractWithGhostscript($input);
    }

    /**
     * Fallback read for a PDF whose content streams the in-process decoder cannot inflate.
     */
    private function extractWithGhostscript(string $input): ?CarbonImmutable
    {
        $file = tempnam(sys_get_temp_dir(), 'pdfreg_');
        if ($file === false) {
            return null;
        }

        try {
            file_put_contents($file, $input);

            $process = new Process([
                $this->binary, '-q', '-dNOPAUSE', '-dBATCH',
                '-dFirstPage=1', '-dLastPage=1',
                '-sDEVICE=txtwrite', '-dTextFormat=0', '-sOutputFile=-', $file,
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('PdfRegistrationDate: ghostscript failed', ['error' => $process->getErrorOutput()]);

                return null;
            }

            return $this->parse($this->glyphs($process->getOutput()));
        } catch (ProcessTimedOutException) {
            Log::warning('PdfRegistrationDate: ghostscript timed out');

            return null;
        } finally {
            @unlink($file);
        }
    }

    /**
     * Concatenates the txtwrite XML's per-glyph characters back into one string, in the order
     * Ghostscript emitted them.
     */
    private function glyphs(string $xml): string
    {
        if (preg_match_all('/\sc="([^"]*)"/', $xml, $matches) === 0) {
            return '';
        }

        return html_entity_decode(implode('', $matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Pulls the dd-MON-yyyy date that follows the label. The month name is mapped explicitly rather
     * than parsed by format, since the form prints it uppercase and locale-dependent parsing is not
     * worth the risk on a gate that blocks uploads.
     */
    private function parse(string $text): ?CarbonImmutable
    {
        if (preg_match('/Web\s*Registration\s*Date\s*:?\s*(\d{1,2})-([A-Za-z]{3})-(\d{4})/i', $text, $matches) !== 1) {
            return null;
        }

        $month = self::MONTHS[strtoupper($matches[2])] ?? null;
        if ($month === null) {
            return null;
        }

        $date = CarbonImmutable::create((int) $matches[3], $month, (int) $matches[1], 0, 0, 0, self::TIMEZONE);

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
