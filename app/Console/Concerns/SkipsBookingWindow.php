<?php

namespace App\Console\Concerns;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Shared booking-window guard for maintenance commands that reload the captcha sidecar.
 *
 * Reloading mid-race would swap the encryptor out from under in-flight slot reserves, so
 * these commands stand down inside the window. The guard is only meaningful if the window
 * is actually a window: a 00:00:00-23:59:59 setting covers the whole day and silently turns
 * every guarded command into a permanent no-op, which is why that case is reported loudly
 * instead of looking like an ordinary skip.
 */
trait SkipsBookingWindow
{
    private const BOOKING_TZ = 'Asia/Dhaka';

    /** A window at least this long leaves no time for maintenance to ever run. */
    private const ALL_DAY_THRESHOLD_SECONDS = 23 * 3600;

    /**
     * True when the current Dhaka time is inside [window_start_time, window_end_time].
     */
    protected function inBookingWindow(Setting $setting): bool
    {
        [$start, $end] = $this->windowBounds($setting);

        if ($start === null || $end === null) {
            return false;
        }

        $now = now(self::BOOKING_TZ)->format('H:i:s');

        return $now >= $start && $now <= $end;
    }

    /**
     * Report the skip, escalating to a warning when the configured window covers so much of
     * the day that this command can never run on its own.
     */
    protected function reportBookingWindowSkip(Setting $setting, string $what): void
    {
        [$start, $end] = $this->windowBounds($setting);

        if ($start !== null && $end !== null && $this->windowSpanSeconds($start, $end) >= self::ALL_DAY_THRESHOLD_SECONDS) {
            $this->warn("Booking window is {$start}-{$end} — that covers the whole day, so {$what} can NEVER run automatically.");
            $this->warn('Narrow window_start_time/window_end_time to the real booking window, or run this command with --force.');

            return;
        }

        $this->info("Inside booking window — deferring {$what} to avoid a mid-race sidecar reload.");
    }

    /**
     * @return array{0: ?string, 1: ?string} normalized H:i:s bounds, or nulls when unset/unparseable
     */
    private function windowBounds(Setting $setting): array
    {
        if (empty($setting->window_start_time) || empty($setting->window_end_time)) {
            return [null, null];
        }

        try {
            return [
                Carbon::parse($setting->window_start_time, self::BOOKING_TZ)->format('H:i:s'),
                Carbon::parse($setting->window_end_time, self::BOOKING_TZ)->format('H:i:s'),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function windowSpanSeconds(string $start, string $end): int
    {
        $toSeconds = static function (string $time): int {
            [$h, $m, $s] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

            return $h * 3600 + $m * 60 + $s;
        };

        return max(0, $toSeconds($end) - $toSeconds($start));
    }
}
