<?php

namespace App\Support;

/**
 * Condenses one logged bot HTTP call into the two strings the accounts page shows: a human
 * phase name and a short response message.
 *
 * Both are derived here rather than in the browser so the work happens once per call at ingest
 * instead of once per row on every poll, and so the stored row stays readable on its own.
 */
class IvacCallSummary
{
    private const MAX_MESSAGE_LENGTH = 255;

    /**
     * Map a request URL to the booking phase it belongs to.
     *
     * Matching is on stable substrings, never whole paths: IVAC bakes a rotating version into
     * `sign-in` and `upload_file` (live values today are `v2677-sign-in` and `upload_file_v2232`)
     * and injects UUIDs into the slot and payment paths, so an equality check would go blind on
     * the next redeploy. The booking-config paths are tested before the bare `/appointment`
     * create call because they are prefixed by it.
     */
    public static function phase(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return match (true) {
            str_contains($path, 'sign-in') => 'Sign in',
            str_contains($path, 'sendOtp'), str_contains($path, 'forgot-password') => 'Send OTP',
            str_contains($path, 'verifySigninOtp') => 'Verify OTP',
            str_contains($path, 'upload_file') => 'Upload PDF',
            str_contains($path, 'appointment-booking-config') => 'Booking config',
            str_contains($path, 'get-booking-config') => 'Get booking config',
            str_ends_with($path, '/appointment') => 'Create appointment',
            str_contains($path, 'reserve-slot') => 'Reserve slot',
            str_contains($path, 'dg-epay'), str_contains($path, '/payment/') => 'Payment',
            str_contains($path, '/profile') => 'Profile',
            str_contains($path, '/api/captcha') => 'Captcha',
            str_contains($path, '/api/otp') => 'OTP poll',
            default => self::fallbackPhase($path),
        };
    }

    /**
     * Pull the one line worth showing out of a response body.
     *
     * A slot reserve is special-cased because its useful answer is the outcome plus the date it
     * applies to (`FULL - 2026-08-19`), not its `message`, which is a long fixed sentence. Every
     * other endpoint answers in one of `message` / `error` / `status`, so those are read in the
     * order IVAC actually populates them. A transport failure has no body at all, and there the
     * error type is the only signal.
     */
    public static function message(?string $responseBody, ?string $errorType = null, ?string $url = null): ?string
    {
        $decoded = $responseBody ? json_decode($responseBody, true) : null;

        if (! is_array($decoded)) {
            $fallback = $errorType ?: (is_string($responseBody) ? trim($responseBody) : null);

            return self::truncate($fallback);
        }

        if ($url && str_contains($url, 'reserve-slot')) {
            if ($reserved = self::reserveOutcome($decoded)) {
                return self::truncate($reserved);
            }
        }

        foreach (['message', 'error', 'errorMessage'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::truncate(trim($value));
            }
        }

        $status = $decoded['status'] ?? null;
        if (is_string($status) && trim($status) !== '') {
            return self::truncate(trim($status));
        }

        return self::truncate($errorType);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function reserveOutcome(array $decoded): ?string
    {
        $status = ($decoded['reservationId'] ?? null) !== null
            ? 'RESERVED'
            : ($decoded['status'] ?? null);

        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        $date = $decoded['appointmentDate'] ?? null;

        return is_string($date) && $date !== ''
            ? trim($status).' · '.$date
            : trim($status);
    }

    /**
     * Name an unrecognised endpoint after its last meaningful path segment, skipping the UUIDs
     * IVAC puts in the middle of a path so the label never degenerates into a raw identifier.
     */
    private static function fallbackPhase(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $segments[$i])) {
                return ucfirst(str_replace(['_', '-'], ' ', $segments[$i]));
            }
        }

        return 'API call';
    }

    private static function truncate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX_MESSAGE_LENGTH);
    }
}
