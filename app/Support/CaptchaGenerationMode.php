<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Redis;

/**
 * Which providers captcha generation may draw from.
 *
 * Chosen when the pool filler is started and read by both the filler and every solve
 * job. It lives in Redis rather than in the job payload because the filler, the queue
 * workers and the on-demand path are separate long-running processes that all have to
 * agree, and because a mid-session change must take effect without a redeploy.
 */
class CaptchaGenerationMode
{
    /** Every provider row, enabled or not — the original behaviour. */
    public const ALL = 'all';

    /** Only providers switched on in Captcha Providers. */
    public const ACTIVE = 'active';

    private const REDIS_KEY = 'captcha:generation_mode';

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return [self::ALL, self::ACTIVE];
    }

    /**
     * Defaults to ACTIVE: an unset key must never mean "spend a disabled paid
     * provider's credit", which is what falling back to ALL would allow.
     */
    public static function current(): string
    {
        try {
            return Redis::get(self::REDIS_KEY) === self::ALL ? self::ALL : self::ACTIVE;
        } catch (\Throwable $e) {
            return self::ACTIVE;
        }
    }

    public static function set(string $mode): void
    {
        Redis::set(self::REDIS_KEY, $mode === self::ALL ? self::ALL : self::ACTIVE);
    }

    public static function activeOnly(): bool
    {
        return self::current() === self::ACTIVE;
    }

    /**
     * Restrict a provider query to the rows the current mode may generate from.
     *
     * @param  Builder<\App\Models\CaptchaProvider>  $query
     * @return Builder<\App\Models\CaptchaProvider>
     */
    public static function scope(Builder $query): Builder
    {
        return self::activeOnly() ? $query->where('enabled', true) : $query;
    }
}
