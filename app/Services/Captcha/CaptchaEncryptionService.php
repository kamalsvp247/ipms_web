<?php

namespace App\Services\Captcha;

/**
 * Transforms a raw Turnstile token into the value IVAC expects using the live IVAC
 * bundle via the Node sidecar. The sidecar drives entirely from encrypt_meta.json
 * (written atomically by the analyzer), so encryption self-heals across IVAC
 * algorithm rotations with no PHP porting required.
 *
 * Returns null when the sidecar is unreachable or meta is not ready; callers translate
 * that into a failure response.
 */
class CaptchaEncryptionService
{
    public function __construct(private readonly LiveBundleClient $sidecar) {}

    public function encryptLogin(string $token): ?string
    {
        return $this->sidecar->encrypt('login', $token);
    }

    public function encryptReserve(string $token): ?string
    {
        return $this->sidecar->encrypt('reserve', $token);
    }
}
