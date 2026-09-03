<?php

namespace App\Services\Payment;

use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\Setting;
use App\Services\Captcha\RawCaptchaTokenProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Fetches IVAC invoice PDFs and keeps them on disk.
 *
 * An invoice can only be pulled while the owning account still holds an unexpired IVAC session
 * (899s from sign-in), and IVAC scopes the invoice to that account — asking with somebody else's
 * valid token answers 500 with an incident ID rather than 401. So the copy has to be taken shortly
 * after the payment is confirmed; afterwards it is served from storage and IVAC is never asked again.
 *
 * The download is also gated by a RAW Turnstile token in the `x-token` header, the way the file
 * upload is — IVAC's own bundle sends one on this call. Omitting it produces the *same* 500 +
 * INCIDENT-ID as an ownership mismatch, which is why every fetch failed identically and no invoice
 * was ever banked: the error was read as somebody else's token, then as an IVAC outage.
 */
class InvoiceArchive
{
    /**
     * Cloudflare 403s libcurl on its TLS fingerprint, so every fetch goes through cloudscraper.
     */
    private const FETCH_TIMEOUT_SECONDS = 60;

    /**
     * How many live sessions one fetch may burn through. Each attempt costs a captcha solve, so
     * this is a ceiling on a bad row, not a target — a healthy invoice lands on the first token.
     */
    private const MAX_TOKEN_ATTEMPTS = 4;

    public function __construct(
        private readonly RawCaptchaTokenProvider $captcha = new RawCaptchaTokenProvider,
        private readonly string $disk = 'local',
    ) {}

    /**
     * Return the stored PDF bytes for a link, fetching and caching it first if needed.
     *
     * @return array{ok: bool, body: string, error: string|null, from_storage: bool}
     */
    public function get(PaymentLink $link): array
    {
        if ($link->hasStoredInvoice()) {
            $body = Storage::disk($this->disk)->get($link->invoice_path);

            if (is_string($body) && $body !== '') {
                return ['ok' => true, 'body' => $body, 'error' => null, 'from_storage' => true];
            }
        }

        $fetched = $this->fetch($link);

        return [...$fetched, 'from_storage' => false];
    }

    /**
     * Pull one invoice from IVAC and cache it.
     *
     * With no explicit token this walks the live sessions — owner first, then others — because
     * the invoice is txrId-scoped and a session we believe is live can still be refused (IVAC
     * answers 401 on a token whose real expiry ran ahead of our stored `jwt_expires_at`). One
     * such 401 used to end the fetch; retrying on the next token recovers it.
     *
     * @return array{ok: bool, body: string, error: string|null}
     */
    public function fetch(PaymentLink $link, ?string $overrideJwt = null): array
    {
        if (blank($link->reservation_id)) {
            return ['ok' => false, 'body' => '', 'error' => 'This link has no reservation ID.'];
        }

        $script = base_path('app/Scripts/fetch_invoice.py');
        if (! file_exists($script)) {
            return ['ok' => false, 'body' => '', 'error' => 'The invoice fetcher script is missing from the server.'];
        }

        $tokens = $overrideJwt !== null ? [$overrideJwt] : $this->candidateJwts($link->account_phone);
        if ($tokens === []) {
            return ['ok' => false, 'body' => '', 'error' => 'No unexpired IVAC session token is available for '.$link->account_phone.'.'];
        }

        $result = ['ok' => false, 'body' => '', 'error' => 'The invoice fetcher could not run.'];

        foreach ($tokens as $jwt) {
            $result = $this->attempt($script, $link, $jwt);

            if ($result['ok']) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * One fetch with one token.
     *
     * @return array{ok: bool, body: string, error: string|null}
     */
    private function attempt(string $script, PaymentLink $link, string $jwt): array
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'ivac_invoice_');
        if ($outputPath === false) {
            return ['ok' => false, 'body' => '', 'error' => 'Could not open a temporary file.'];
        }

        try {
            $process = new Process(['python3', $script, $outputPath]);
            $process->setTimeout(self::FETCH_TIMEOUT_SECONDS);
            $process->setInput(json_encode([
                'url' => 'https://api.ivacbd.com/iams/api/v1/invoice/download?txrId='.urlencode((string) $link->reservation_id),
                'jwt' => $jwt,
                'proxy' => Setting::instance()->captcha_bd_proxy_url ?: null,
                'x_token' => $this->captcha->mint(),
            ]));
            $process->run();

            if (! $process->isSuccessful()) {
                return ['ok' => false, 'body' => '', 'error' => 'The invoice fetcher could not run.'];
            }

            $meta = json_decode(trim($process->getOutput()), true);
            if (! is_array($meta) || ! isset($meta['status'])) {
                return ['ok' => false, 'body' => '', 'error' => 'The invoice fetcher returned no status.'];
            }

            $body = is_file($outputPath) ? (file_get_contents($outputPath) ?: '') : '';
            $status = (int) $meta['status'];

            if (! $this->looksLikePdf($status, $body, (string) ($meta['content_type'] ?? ''))) {
                return ['ok' => false, 'body' => '', 'error' => $this->describeFailure($status, $body)];
            }

            $this->store($link, $body);

            return ['ok' => true, 'body' => $body, 'error' => null];
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * Write the PDF to disk and record where it went.
     */
    public function store(PaymentLink $link, string $body): void
    {
        $path = $link->invoiceStoragePath();
        Storage::disk($this->disk)->put($path, $body);

        $link->forceFill([
            'invoice_path' => $path,
            'invoice_fetched_at' => now(),
        ])->save();
    }

    private function looksLikePdf(int $status, string $body, string $contentType): bool
    {
        return $status === 200
            && $body !== ''
            && (str_contains(strtolower($contentType), 'pdf') || str_starts_with($body, '%PDF'));
    }

    /**
     * Turn IVAC's JSON error into something readable, falling back to the bare status.
     */
    private function describeFailure(int $status, string $body): string
    {
        $decoded = json_decode($body, true);

        return (is_array($decoded) && is_string($decoded['message'] ?? null))
            ? $decoded['message']
            : "IVAC returned status {$status}.";
    }

    /**
     * Live tokens to try, the owner's first.
     *
     * Falling back to other accounts is the useful path, not a long shot: IVAC scopes the invoice
     * to the `txrId` in the URL, not to the bearer, so ANY account's live JWT downloads it. The
     * 500 that made a mismatched token look rejected was the missing `x-token` all along. So an
     * invoice stays recoverable for as long as one account anywhere is signed in — it does not
     * die with the payer's own 899s session.
     *
     * @return list<string>
     */
    private function candidateJwts(?string $phone): array
    {
        return AccountSession::where('jwt_expires_at', '>', now())
            ->whereNotNull('jwt_token')
            ->orderByRaw('phone = ? DESC', [(string) $phone])
            ->orderByDesc('jwt_expires_at')
            ->limit(self::MAX_TOKEN_ATTEMPTS)
            ->pluck('jwt_token')
            ->all();
    }
}
