<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;

/**
 * How long a solved captcha stays usable before the pool discards it.
 *
 * Chosen on Captcha Control and read by the scheduled purge, the pool filler and every
 * token endpoint. It lives in Redis rather than in config because those are separate
 * long-running processes that all have to agree, and because a mid-session change must
 * take effect without a redeploy. Every call re-reads Redis so a change applies to the
 * next purge rather than to the next restart.
 */
class CaptchaPoolExpiry
{
    /** Applied when the key is unset, matching what Captcha Control shows for a fresh install. */
    public const DEFAULT_SECONDS = 120;

    public const MIN_SECONDS = 30;

    public const MAX_SECONDS = 600;

    private const REDIS_KEY = 'captcha:pool_expiry_seconds';

    /**
     * Clamped to the range Captcha Control accepts: a hand-edited or legacy Redis value
     * outside it would either delete tokens that are still live or never expire stale
     * ones, and both fail silently.
     */
    public static function seconds(): int
    {
        try {
            $stored = Redis::get(self::REDIS_KEY);
        } catch (\Throwable $e) {
            return self::DEFAULT_SECONDS;
        }

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_SECONDS;
        }

        return self::clamp((int) $stored);
    }

    public static function set(int $seconds): void
    {
        Redis::set(self::REDIS_KEY, self::clamp($seconds));
    }

    /**
     * Tokens created at or before this instant are expired.
     */
    public static function cutoff(): CarbonInterface
    {
        return now()->subSeconds(self::seconds());
    }

    private static function clamp(int $seconds): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }
}
