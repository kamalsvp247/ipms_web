<?php

namespace App\Support;

/**
 * PHP port of IVAC captcha token encryption — June 2026.
 *
 * Two algorithms extracted from the live IVAC JS bundle (version-dispatched, change per redeploy):
 * - Login  (version 6): polynomial-in-k mod 67 shift schedule, applied additively. skip=7, encLen=23.
 * - Reserve (version 5): three LFSRs (16/17/24-bit) feeding a multiplexer, 6 bits per char, applied additively. skip=4, encLen=22.
 *
 * Both transforms are additive over the 64-char charset: out[i] = CHARSET[(idx + shift[i]) % 64].
 * They differ only in how the per-position shift schedule is generated from the secret.
 */
final class CaptchaTokenTransformer
{
    private const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_';

    private const CHARSET_LEN = 64;

    private function __construct() {}

    /** Apply LOGIN additive transform — for sign-in captcha (type=turnstile). */
    public static function transformLogin(
        string $token,
        string $secret,
        int $skip,
        int $encLen,
    ): string {
        return self::applyAdditive($token, $skip, $encLen, fn (int $len): array => self::loginShifts($secret, $len));
    }

    /** Apply RESERVE additive transform — for slot reservation captcha (type=turnstile_encrypted). */
    public static function transformReserve(
        string $token,
        string $secret,
        int $skip,
        int $encLen,
    ): string {
        return self::applyAdditive($token, $skip, $encLen, fn (int $len): array => self::reserveShifts($secret, $len));
    }

    /**
     * Shared additive window transform: skip prefix, transform next encLen chars
     * by adding the per-position shift modulo the charset length, leave the rest.
     *
     * @param  callable(string, int): int[]  $scheduleFor  Builds the shift schedule from (secret, actualLen).
     */
    private static function applyAdditive(string $token, int $skip, int $encLen, callable $scheduleFor): string
    {
        if ($token === '') {
            return $token;
        }

        $prefixLen = max(0, min($skip, strlen($token)));
        $actualLen = max(0, min($encLen, strlen($token) - $prefixLen));

        if ($actualLen === 0) {
            return $token;
        }

        $shifts = $scheduleFor($actualLen);
        $chars = str_split(substr($token, $prefixLen, $actualLen));

        for ($i = 0; $i < $actualLen; $i++) {
            $idx = strpos(self::CHARSET, $chars[$i]);
            if ($idx !== false) {
                $chars[$i] = self::CHARSET[($idx + $shifts[$i]) % self::CHARSET_LEN];
            }
        }

        return substr($token, 0, $prefixLen).implode('', $chars).substr($token, $prefixLen + $actualLen);
    }

    /**
     * LOGIN shift schedule — mirrors JS N0() (version 6).
     *
     * Build coefficients c[n] = (charCode(secret[n % len]) + n) % 67 for n in 0..max(3, len)-1.
     * For each output position p (k = p + 1): evaluate the polynomial sum(c[j] * k^j) mod 67,
     * then reduce mod 64.
     *
     * @return int[]
     */
    private static function loginShifts(string $secret, int $len): array
    {
        $secretLen = strlen($secret);
        $coeffCount = max(3, $secretLen);

        $coeffs = [];
        for ($n = 0; $n < $coeffCount; $n++) {
            $coeffs[] = (ord($secret[$n % $secretLen]) + $n) % 67;
        }

        $shifts = [];
        for ($k = 1; $k <= $len; $k++) {
            $acc = 0;
            $pow = 1;
            foreach ($coeffs as $coeff) {
                $acc = ($acc + $coeff * $pow) % 67;
                $pow = ($pow * $k) % 67;
            }
            $shifts[] = $acc % self::CHARSET_LEN;
        }

        return $shifts;
    }

    /**
     * RESERVE shift schedule — mirrors JS h0() (version 5).
     *
     * Three LFSRs seeded from the secret:
     *   d (16-bit, taps 0,2,3,5), f (17-bit, taps 0,1,2,7), w (24-bit, taps 0,1,2,22).
     * Per output char, clock all three six times; each clock the d-bit selects (multiplexes)
     * between the f-bit and w-bit. The six selected bits form a value reduced mod 64.
     *
     * @return int[]
     */
    private static function reserveShifts(string $secret, int $len): array
    {
        $d = 74565;
        $f = 424090;
        $w = 773615;

        $secretLen = strlen($secret);
        for ($i = 0; $i < $secretLen; $i++) {
            $c = ord($secret[$i]);
            $d ^= 1 | $c;
            $f ^= ($c << 2) | 1;
            $w ^= 1 | ($c << 4);
        }

        $shifts = [];
        for ($p = 0; $p < $len; $p++) {
            $acc = 0;
            for ($bit = 0; $bit < 6; $bit++) {
                $t = 1 & ($d ^ ($d >> 2) ^ ($d >> 3) ^ ($d >> 5));
                $d = ($d >> 1) | ($t << 15);

                $n = 1 & ($f ^ ($f >> 1) ^ ($f >> 2) ^ ($f >> 7));
                $f = ($f >> 1) | ($n << 16);

                $r = 1 & ($w ^ ($w >> 1) ^ ($w >> 2) ^ ($w >> 22));
                $w = ($w >> 1) | ($r << 23);

                $m = ($t & $n) ^ ((~$t) & $r) & 1;
                $acc = ($acc << 1) | ($m & 1);
            }
            $shifts[] = $acc % self::CHARSET_LEN;
        }

        return $shifts;
    }
}
