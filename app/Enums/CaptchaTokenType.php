<?php

namespace App\Enums;

enum CaptchaTokenType: string
{
    case Turnstile = 'turnstile';
    case TurnstileEncrypted = 'turnstile_encrypted';
    case Recaptcha = 'recaptcha';

    public function label(): string
    {
        return match ($this) {
            self::Turnstile => 'Turnstile (raw)',
            self::TurnstileEncrypted => 'Turnstile (encrypted)',
            self::Recaptcha => 'reCAPTCHA v2',
        };
    }
}
