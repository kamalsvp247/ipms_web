<?php

namespace App\Support;

/**
 * PHP port of IVAC captcha token encryption — September 2026.
 *
 * Live algorithm (version 2, module x1): a fixed substitution cipher over the 64-char
 * charset. A permutation table derived from the secret maps each charset index to its
 * replacement: out[i] = CHARSET[PERM[CHARSET.indexOf(in[i])]].
 * Both login and reserve use the same v2 algorithm with skip=4, encLen=26.
 *
 * Legacy algorithms (kept for backward-compat / historical snapshot validation):
 * - Login  (version 6): polynomial-in-k mod 67 shift schedule, additive.
 * - Reserve (version 5): three LFSRs (16/17/24-bit) feeding a multiplexer, additive.
 */
final class CaptchaTokenTransformer
{
    private const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_';

    private const CHARSET_LEN = 64;

    /**
     * V2 permutation table — extracted from live IVAC bundle (module x1).
     * V2_PERM[i] = the charset index that replaces index i.
     */
    private const array V2_PERM = [
        26, 13, 60, 15, 30,  9, 56, 11, 21, 38, 51, 36, 17, 34, 55, 32,
         8, 31, 46, 29, 12, 27, 42, 25,  3, 48, 37, 50,  7, 52, 33, 54,
        62, 41, 24, 43, 58, 45, 28, 47, 49,  2, 23,  0, 53,  6, 19,  4,
        44, 59, 10, 57, 40, 63, 14, 61, 39, 20,  1, 22, 35, 16,  5, 18,
    ];

    private function __construct() {}

    /**
     * Apply V2 substitution transform (live IVAC module x1).
     *
     * Fixed permutation table — each charset index maps directly to its replacement.
     * Skip and encLen control which portion of the token is transformed.
     */
    public static function transformV2(string $token, int $skip, int $encLen): string
    {
        if ($token === '') {
            return $token;
        }

        $prefixLen = max(0, min($skip, strlen($token)));
        $actualLen = max(0, min($encLen, strlen($token) - $prefixLen));

        if ($actualLen === 0) {
            return $token;
        }

        $perm = self::V2_PERM;
        $chars = str_split(substr($token, $prefixLen, $actualLen));

        for ($i = 0; $i < $actualLen; $i++) {
            $idx = strpos(self::CHARSET, $chars[$i]);
            if ($idx !== false) {
                $chars[$i] = self::CHARSET[$perm[$idx]];
            }
        }

        return substr($token, 0, $prefixLen).implode('', $chars).substr($token, $prefixLen + $actualLen);
    }

    /** Apply LOGIN transform — delegates to v2 (current live algorithm). */
    public static function transformLogin(
        string $token,
        string $secret,
        int $skip,
        int $encLen,
    ): string {
        return self::transformV2($token, $skip, $encLen);
    }

    /** Apply RESERVE transform — delegates to v2 (current live algorithm). */
    public static function transformReserve(
        string $token,
        string $secret,
        int $skip,
        int $encLen,
    ): string {
        return self::transformV2($token, $skip, $encLen);
    }

    /** Legacy LOGIN transform (v6 polynomial mod67) — kept for backward-compat tests. */
    public static function transformLoginLegacy(
        string $token,
        string $secret,
        int $skip,
        int $encLen,
    ): string {
        return self::applyAdditive($token, $skip, $encLen, fn (int $len): array => self::loginShifts($secret, $len));
    }

    /** Legacy RESERVE transform (v5 three-LFSR) — kept for backward-compat tests. */
    public static function transformReserveLegacy(
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
