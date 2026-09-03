<?php

namespace App\Support;

/**
 * Shared parsing for IVAC's /.well-known/ discovery page (appointment.ivacbd.com).
 *
 * Used to fingerprint a candidate origin IP as the real appointment.ivacbd.com
 * backend (BypassIpScanner).
 */
class IvacBundleMarkers
{
    /**
     * IVAC's Vite build always names the entry bundle `m[a-z0-9]{7}-[A-Za-z0-9_-]{8}.js`
     * (e.g. `mr0irw2v-DGz9cfLV.js`) — confirmed against every version recorded in
     * captcha_bundle_versions. Matching this exact shape (rather than any
     * `/assets/*.js`) is what tells a genuine appointment.ivacbd.com origin apart
     * from an unrelated Vite/webpack SPA that happens to answer the probe.
     */
    public static function extractBundleName(string $html): ?string
    {
        if (preg_match('#src="/assets/(m[a-z0-9]{7}-[A-Za-z0-9_-]{8}\.js)"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function extractVersion(string $html): ?string
    {
        if (preg_match('#name="version"\s+content="([^"]+)"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function looksLikeChallenge(string $body): bool
    {
        return stripos($body, 'Just a moment') !== false
            || stripos($body, 'challenge-platform') !== false
            || stripos($body, 'cf-mitigated') !== false;
    }

    /**
     * IVAC serves a Cloudflare-edge "APPOINTMENT BOOKING GUIDELINES" notice for
     * every path (including static assets) outside the live booking window. While
     * it is active nothing is fetchable — discovery and download both hit it.
     */
    public static function looksLikeBookingNotice(string $body): bool
    {
        return stripos($body, 'APPOINTMENT BOOKING GUIDELINES') !== false
            || stripos($body, 'IMPORTANT NOTICE') !== false
            || stripos($body, 'Appointment booking opens at') !== false;
    }
}
