<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the live-bundle encrypt sidecar (app/Scripts/captcha_encrypt_server.cjs).
 *
 * The sidecar keeps the IVAC bundle loaded and runs its real encrypt modules, so a
 * round trip is sub-millisecond. Every method is best-effort: on any failure it returns
 * null/false so callers can fall back to the PHP transformer.
 */
class LiveBundleClient
{
    private string $baseUrl;

    private float $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('captcha.sidecar.url'), '/');
        $this->timeout = (float) config('captcha.sidecar.timeout', 2.0);
    }

    /**
     * Encrypt a token with the live bundle. Returns null on any failure.
     *
     * secret/skip/encLen are optional: when omitted the sidecar uses the live values
     * from encrypt_meta.json for the token type (the normal, self-healing path). Pass
     * them only to override the meta values.
     */
    public function encrypt(string $type, string $token, ?string $secret = null, ?int $skip = null, ?int $encLen = null): ?string
    {
        try {
            $payload = ['type' => $type, 'token' => $token];
            if ($secret !== null) {
                $payload['secret'] = $secret;
            }
            if ($skip !== null) {
                $payload['skip'] = $skip;
            }
            if ($encLen !== null) {
                $payload['encLen'] = $encLen;
            }

            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post("{$this->baseUrl}/encrypt", $payload);

            if ($response->successful()) {
                $encrypted = $response->json('token');
                if (is_string($encrypted) && $encrypted !== '') {
                    return $encrypted;
                }

                // An empty/non-string token is a sidecar fault, not a usable result:
                // treat it as failure so the caller surfaces an error instead of shipping
                // a blank captcha token IVAC will reject.
                Log::warning('Captcha sidecar returned empty/invalid token', [
                    'type' => $type,
                    'token_type' => gettype($encrypted),
                ]);

                return null;
            }

            Log::warning('Captcha sidecar encrypt failed', [
                'type' => $type,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Captcha sidecar unreachable', ['type' => $type, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Sidecar health snapshot, or null when unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function health(): ?array
    {
        try {
            $response = Http::timeout($this->timeout)->acceptJson()->get("{$this->baseUrl}/health");

            return $response->json();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ask the sidecar to reload the bundle + meta. Returns true on success.
     */
    public function reload(): bool
    {
        try {
            // The sidecar evaluates the IVAC bundle synchronously on /reload; give it up to
            // 30s before treating the call as failed (the default 2s frequently times out on
            // a large bundle before Node can respond, causing a false needs-attention alarm).
            return Http::timeout(30)->post("{$this->baseUrl}/reload")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ask the sidecar to pre-evaluate the current on-disk bundle into its staging slot,
     * WITHOUT touching the live encryptor. The sidecar acks immediately and does the
     * ~1.1s eval in the background, so this call returns fast and the staging cost
     * overlaps the caller's own bundle analysis. Best-effort: a failure just means the
     * later promote() falls back to a full reload.
     */
    public function stage(): bool
    {
        try {
            return Http::timeout(5)->post("{$this->baseUrl}/stage")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Promote a previously staged bundle to live. When the staged bundle matches
     * $bundleHash the swap is instant (no re-eval); otherwise the sidecar falls back to a
     * full reload, so this is never slower than reload(). Returns the sidecar's response
     * (ok + whether it promoted instantly vs reloaded), or a failed marker on error.
     *
     * @return array{ok: bool, promoted: bool}
     */
    public function promote(string $bundleHash): array
    {
        try {
            // Up to 30s: a fallback reload synchronously evaluates the ~2 MB bundle.
            $response = Http::timeout(30)->acceptJson()->post("{$this->baseUrl}/promote", [
                'bundle_hash' => $bundleHash,
            ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'promoted' => (bool) $response->json('promoted', false),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Captcha sidecar promote failed', ['error' => $e->getMessage()]);
        }

        return ['ok' => false, 'promoted' => false];
    }
}
