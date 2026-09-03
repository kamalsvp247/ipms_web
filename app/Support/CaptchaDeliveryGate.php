<?php

namespace App\Support;

use App\Models\CaptchaProvider;

/**
 * The master switch deciding whether a captcha may leave the portal at all.
 *
 * Reads captcha_providers.enabled directly, and deliberately does NOT scope through
 * CaptchaGenerationMode. The two answer different questions: generation mode says which
 * rows the pool filler may spend on — "All providers" exists so inventory can be built
 * ahead of a window from rows that are still switched off — while this gate says whether
 * anything may be handed to the bot. Scoping delivery through the mode let ALL override
 * the off switch entirely: with every provider disabled a provider row still existed, so
 * the gate stayed open and the bot kept draining the pool.
 *
 * Every operator control writes this one flag, so all of them stop the bot: the header
 * toggle and the Captcha Providers page both POST /api/captcha-providers/bulk-status, and
 * the Algorithm Monitor re-enables providers after a clean extraction — which is exactly
 * the release step that must not be reachable while the analysis is unclean.
 */
class CaptchaDeliveryGate
{
    public static function open(): bool
    {
        return CaptchaProvider::where('enabled', true)->exists();
    }

    public static function shut(): bool
    {
        return ! self::open();
    }

    /**
     * Why the gate is shut. Mirrors CaptchaRaceCoordinator::unavailableReason() so the bot
     * sees one wording for one cause regardless of which layer refused it.
     */
    public static function reason(): string
    {
        return CaptchaProvider::exists()
            ? 'No captcha providers are enabled.'
            : 'No captcha providers configured.';
    }
}
