<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Verify a Turnstile response token against Cloudflare's siteverify endpoint.
     *
     * Fails open (returns true) when no secret key is configured, so the captcha
     * only starts being enforced once a real Turnstile keypair is set in .env.
     */
    public function verify(?string $token, ?string $ip): bool
    {
        $secret = config('turnstile.secret_key');

        if (blank($secret)) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return (bool) $response->json('success');
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification request failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
