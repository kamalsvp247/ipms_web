<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Services\Pdf\PdfOptimizer;
use App\Support\VisaFormPdfParser;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(private readonly PdfOptimizer $pdfOptimizer) {}

    public function delete(Account $account): void
    {
        $account->delete();
    }

    public function create(User $user, array $data): Account
    {
        if (array_key_exists('appointment_id', $data) && $data['appointment_id'] !== null) {
            $data['appointment_id_updated_at'] = now();
        }

        $pdfs = $this->extractPdfs($data);

        return DB::transaction(function () use ($user, $data, $pdfs): Account {
            $account = $user->accounts()->create($data);
            if ($pdfs !== null) {
                $this->syncPdfs($account, $pdfs);
            }

            return $account;
        });
    }

    public function update(Account $account, array $data): Account
    {
        if (array_key_exists('appointment_id', $data)) {
            $data['appointment_id_updated_at'] = $data['appointment_id'] !== null ? now() : null;
        }

        $pdfs = $this->extractPdfs($data);

        return DB::transaction(function () use ($account, $data, $pdfs): Account {
            $account->update($data);
            if ($pdfs !== null) {
                $this->syncPdfs($account, $pdfs);
            }

            return $account;
        });
    }

    /**
     * Pops the `pdfs` payload out of the account attributes — it lives in the account_pdfs child
     * table, not a column. Returns null when the caller did not send `pdfs` (existing PDFs left
     * untouched); returns the array (possibly empty) when they did.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>|null
     */
    private function extractPdfs(array &$data): ?array
    {
        if (! array_key_exists('pdfs', $data)) {
            return null;
        }

        $pdfs = $data['pdfs'];
        unset($data['pdfs']);

        return is_array($pdfs) ? $pdfs : [];
    }

    /**
     * Replaces the account's PDFs with the given set (name, base64, is_primary). Each PDF is
     * Ghostscript-optimized so the bot uploads a much smaller file to IVAC; the pristine base64 is
     * always kept and optimization failure is non-fatal (the bot falls back to the original).
     *
     * @param  list<array<string, mixed>>  $pdfs
     */
    private function syncPdfs(Account $account, array $pdfs): void
    {
        $account->pdfs()->delete();

        $rows = [];
        foreach ($pdfs as $pdf) {
            if (! isset($pdf['base64']) || $pdf['base64'] === '') {
                continue;
            }
            $row = [
                'name' => $pdf['name'] ?? null,
                'application_id' => self::applicationId($pdf['base64']),
                'base64' => $pdf['base64'],
                'is_primary' => filter_var($pdf['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            $optimized = $this->pdfOptimizer->optimize($pdf['base64']);
            if ($optimized !== null) {
                $row['optimized_base64'] = $optimized['base64'];
                $row['original_size'] = $optimized['original_size'];
                $row['optimized_size'] = $optimized['optimized_size'];
            }

            $rows[] = $row;
        }

        if ($rows !== []) {
            $account->pdfs()->createMany($rows);
        }
    }

    /**
     * The web file number printed on an application form, or null when the upload is not a readable
     * IVAC form. A form that will not parse is still stored — the ID is display metadata, never a
     * gate on the upload itself.
     */
    private static function applicationId(string $base64): ?string
    {
        $binary = base64_decode($base64, true);
        if ($binary === false || ! str_starts_with($binary, '%PDF-')) {
            return null;
        }

        return VisaFormPdfParser::applicationId($binary);
    }
}
