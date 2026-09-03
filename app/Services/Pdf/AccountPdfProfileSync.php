<?php

namespace App\Services\Pdf;

use App\Models\Account;
use App\Models\AccountPdf;
use App\Support\VisaFormPdfParser;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites an account's primary applicant PDF so it states the identity IVAC already holds for that
 * account.
 *
 * IVAC's file service answers a PDF upload with HTTP 200 and lists every field where the signed-in
 * ACCOUNT and the uploaded FORM disagree, and it will not create the appointment until they agree. The
 * account is the identity we must not touch — its phone is the IVAC login and its email carries the
 * OTP — so the form is what gets corrected. The bot reads GET /profile and hands the result here.
 *
 * Two things make this safe to run unattended:
 *  - the edit is verified by reading the result back with VisaFormPdfParser before anything is stored,
 *    because app/Scripts/edit_passport_pdf.py exits 0 whenever it placed at least ONE field and would
 *    otherwise skip an unlocatable label silently;
 *  - an already-correct document is detected up front and left completely alone, so a repeated call
 *    costs nothing and never stacks a second overlay onto the same page.
 */
class AccountPdfProfileSync
{
    /**
     * Profile field -> [PdfFieldEditor key, VisaFormPdfParser key]. The profile is read with IVAC's own
     * key names, which differ from both sides (it returns "surname" but the form calls it Surname, and
     * the editor calls the passport "passport_no").
     *
     * @var array<string, array{string, string}>
     */
    private const FIELDS = [
        'given_name' => ['given_name', 'given_name'],
        'surname' => ['surname', 'surname'],
        'passport' => ['passport_no', 'passport'],
        'nid' => ['nid', 'nid'],
        'phone' => ['phone', 'phone'],
        'email' => ['email', 'email'],
    ];

    public function __construct(
        private readonly PdfFieldEditor $editor,
        private readonly PdfOptimizer $optimizer,
    ) {
    }

    /**
     * Brings the account's primary PDF in line with the given profile.
     *
     * Statuses:
     *   unchanged   — the document already states every supplied value; nothing was written.
     *   updated     — the document was edited, verified, re-optimized and stored.
     *   no_primary  — the account has no primary PDF to correct.
     *   edit_failed — the editor could not produce a PDF at all.
     *   not_applied — the edit ran but at least one field did not land; nothing was stored.
     *
     * @param  array<string, string|null>  $profile  keyed by self::FIELDS
     * @return array{status: string, unapplied: array<string, array{expected: string, actual: string|null}>}
     */
    public function sync(Account $account, array $profile): array
    {
        $primary = $account->pdfs()->where('is_primary', true)->first();
        if ($primary === null) {
            return ['status' => 'no_primary', 'unapplied' => []];
        }

        $wanted = $this->wantedValues($profile);
        if ($wanted === []) {
            return ['status' => 'unchanged', 'unapplied' => []];
        }

        $current = base64_decode($primary->base64, true);
        if ($current === false) {
            return ['status' => 'edit_failed', 'unapplied' => []];
        }

        if ($this->unapplied($current, $wanted) === []) {
            return ['status' => 'unchanged', 'unapplied' => []];
        }

        $fields = [];
        foreach ($wanted as $field => $value) {
            $fields[self::FIELDS[$field][0]] = $value;
        }

        $edited = $this->editor->edit($current, $fields);
        if ($edited === null) {
            return ['status' => 'edit_failed', 'unapplied' => []];
        }

        // Never store an edit that did not fully take: the next upload would be rejected for the very
        // field that was supposed to be repaired, and the original would already be gone.
        $unapplied = $this->unapplied($edited, $wanted);
        if ($unapplied !== []) {
            return ['status' => 'not_applied', 'unapplied' => $unapplied];
        }

        $this->store($account, $primary, $edited);

        return ['status' => 'updated', 'unapplied' => []];
    }

    /**
     * The supplied profile values that are worth writing, keyed by profile field. Blank entries are
     * dropped so a profile IVAC returned incomplete never blanks a field that is already correct.
     *
     * @param  array<string, string|null>  $profile
     * @return array<string, string>
     */
    private function wantedValues(array $profile): array
    {
        $wanted = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $profile[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $wanted[$field] = trim($value);
            }
        }

        return $wanted;
    }

    /**
     * Reads the PDF back and returns every requested field it does not state, with what was expected
     * and what the form actually carries. An empty result means the document is correct.
     *
     * @param  array<string, string>  $wanted
     * @return array<string, array{expected: string, actual: string|null}>
     */
    private function unapplied(string $pdf, array $wanted): array
    {
        $parsed = VisaFormPdfParser::extract($pdf);

        $unapplied = [];
        foreach ($wanted as $field => $value) {
            $actual = $parsed[self::FIELDS[$field][1]] ?? null;
            $expected = self::canonical($field, $value);

            // A value that does not survive the parser's own normalization — a phone that is not a
            // Bangladeshi mobile, say — can never be read back off the form, so it can never be
            // confirmed as applied. Reporting it is what stops two nulls comparing equal and passing
            // an edit that silently did nothing.
            if ($expected === null || $expected !== self::canonical($field, $actual)) {
                $unapplied[$field] = ['expected' => $value, 'actual' => $actual];
            }
        }

        return $unapplied;
    }

    /**
     * Reduces a value to the form both sides can be compared in — the same normalization
     * VisaFormPdfParser applies when it reads a field, so a comparison never fails on case or
     * punctuation the editor legitimately changed.
     */
    private static function canonical(string $field, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return match ($field) {
            'passport' => strtoupper(preg_replace('/\s+/', '', $value) ?? ''),
            'nid' => preg_replace('/\D+/', '', $value) ?? '',
            'email' => strtolower(trim($value)),
            'phone' => self::canonicalPhone($value),
            default => strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? ''),
        };
    }

    /**
     * The local 01XXXXXXXXX form VisaFormPdfParser returns. A number that is not a Bangladeshi mobile
     * canonicalizes to null — see unapplied(), which treats that as unverifiable rather than equal.
     */
    private static function canonicalPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return preg_match('/(1[3-9]\d{8})$/', $digits, $matches) === 1 ? '0'.$matches[1] : null;
    }

    /**
     * Stores the corrected document and re-derives its optimized copy. The IVAC-side chain was built
     * around the old form, so the setup flags are cleared the same way a primary re-selection clears
     * them and the bot rebuilds it from scratch.
     */
    private function store(Account $account, AccountPdf $primary, string $edited): void
    {
        $base64 = base64_encode($edited);
        $optimized = $this->optimizer->optimize($base64);

        DB::transaction(function () use ($account, $primary, $base64, $optimized): void {
            $primary->update([
                'base64' => $base64,
                'optimized_base64' => $optimized['base64'] ?? null,
                'original_size' => $optimized['original_size'] ?? null,
                'optimized_size' => $optimized['optimized_size'] ?? null,
            ]);

            $account->update([
                'pdf_uploaded' => false,
                'pdf_uploaded_date' => null,
                'booking_configured' => false,
                'booking_configured_date' => null,
            ]);
        });
    }
}
