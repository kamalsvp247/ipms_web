<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The daily window during which agents may not edit or delete accounts.
 *
 * Managers and super admins are never locked out — the window exists so an agent cannot pull
 * an account out from under a booking run, while the people who supervise the run still can.
 * Times are wall-clock Dhaka times repeating every day; start later than end wraps past
 * midnight (22:00 to 02:00 locks the four hours around midnight, not the twenty between).
 */
class AccountLockWindow
{
    public const TIMEZONE = 'Asia/Dhaka';

    public const MESSAGE = 'Accounts are locked right now. Ask a manager, or try again after the lock window closes.';

    /**
     * Whether the given user is currently barred from editing or deleting accounts.
     */
    public static function blocks(?User $user): bool
    {
        return $user !== null && $user->isAgent() && self::isActive();
    }

    /**
     * Whether the lock window is open at the given moment (defaults to now, Dhaka time).
     */
    public static function isActive(?CarbonInterface $now = null, ?Setting $settings = null): bool
    {
        $settings ??= Setting::instance();

        if (! $settings->account_lock_enabled) {
            return false;
        }

        $start = self::secondsOfDay($settings->account_lock_start_time);
        $end = self::secondsOfDay($settings->account_lock_end_time);

        // An unset or zero-length window locks nothing, so a half-filled settings row can never
        // silently freeze every agent out.
        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $current = self::secondsOfDay(($now ?? Carbon::now(self::TIMEZONE))->format('H:i:s'));

        return $start < $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);
    }

    /**
     * The window as shared with the front end, which re-evaluates it against its own ticking
     * Dhaka clock so the UI locks and unlocks without a page reload.
     *
     * @return array{enabled: bool, start: string|null, end: string|null}
     */
    public static function payload(?Setting $settings = null): array
    {
        $settings ??= Setting::instance();

        return [
            'enabled' => (bool) $settings->account_lock_enabled,
            'start' => self::normalize($settings->account_lock_start_time),
            'end' => self::normalize($settings->account_lock_end_time),
        ];
    }

    /**
     * A stored time as HH:MM:SS, or null when it is unset or unparseable.
     */
    private static function normalize(mixed $time): ?string
    {
        $seconds = self::secondsOfDay($time);

        if ($seconds === null) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Seconds since midnight for a HH:MM[:SS] value, or null when it is unset or malformed.
     */
    private static function secondsOfDay(mixed $time): ?int
    {
        if ($time instanceof CarbonInterface) {
            $time = $time->format('H:i:s');
        }

        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $parts)) {
            return null;
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];
        $seconds = (int) ($parts[3] ?? 0);

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
