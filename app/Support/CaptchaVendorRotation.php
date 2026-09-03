<?php

namespace App\Support;

use App\Models\CaptchaProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Round-robin over vendor solver threads.
 *
 * The rotation unit is one thread, not one vendor: a lap visits every vendor's first
 * thread before any vendor's second, so load crosses the whole vendor set before it
 * doubles up on anyone, and a vendor provisioned for more parallelism earns
 * proportionally more turns without ever starving a smaller one.
 *
 * The cursor lives in Redis because the pool filler and the queue workers are separate
 * long-running processes that must share one sequence — two private cursors would each
 * be internally fair and jointly lopsided.
 *
 * The in-house fleet is deliberately absent, because this cursor only orders the paths that
 * must choose ONE provider for a single request — SolveCaptchaJob::acquireSlot() and a race's
 * lanes — where the fleet is tried first as free hardware. Pool generation does not come
 * through here at all: it dispatches to every provider's free slots at once, so there is no
 * turn to take. See CaptchaDemandSplit.
 */
class CaptchaVendorRotation
{
    private const CURSOR_KEY = 'captcha:rr_index';

    /**
     * One full lap of thread slots, interleaved across vendors.
     *
     * @param  Collection<int, CaptchaProvider>  $vendors
     * @return list<CaptchaProvider>
     */
    public static function lap(Collection $vendors): array
    {
        $lap = [];
        $deepest = 0;

        foreach ($vendors as $vendor) {
            $deepest = max($deepest, self::threadsFor($vendor));
        }

        for ($thread = 0; $thread < $deepest; $thread++) {
            foreach ($vendors as $vendor) {
                if (self::threadsFor($vendor) > $thread) {
                    $lap[] = $vendor;
                }
            }
        }

        return $lap;
    }

    /**
     * Advances the shared cursor one slot and returns the vendor holding it.
     *
     * @param  Collection<int, CaptchaProvider>  $vendors
     */
    public static function next(Collection $vendors): ?CaptchaProvider
    {
        $lap = self::lap($vendors);

        if ($lap === []) {
            return null;
        }

        return $lap[self::advance(count($lap))];
    }

    /**
     * Vendors in cursor order, each listed once.
     *
     * This is the sequence a first-fit walk should try: the vendor holding the current
     * slot is preferred, and the rest stay in rotation order behind it so a full vendor
     * falls through to the next in line rather than to a random one.
     *
     * @param  Collection<int, CaptchaProvider>  $vendors
     * @return list<CaptchaProvider>
     */
    public static function order(Collection $vendors): array
    {
        $lap = self::lap($vendors);

        if ($lap === []) {
            return [];
        }

        $offset = self::advance(count($lap));
        $rotated = array_merge(array_slice($lap, $offset), array_slice($lap, 0, $offset));

        $order = [];
        $seen = [];

        foreach ($rotated as $vendor) {
            if (isset($seen[$vendor->id])) {
                continue;
            }

            $seen[$vendor->id] = true;
            $order[] = $vendor;
        }

        return $order;
    }

    public static function reset(): void
    {
        Redis::set(self::CURSOR_KEY, 0);
    }

    /**
     * A vendor always holds at least one thread — a row saved as 0 would otherwise drop
     * out of the rotation silently instead of simply being slow.
     */
    private static function threadsFor(CaptchaProvider $vendor): int
    {
        return max(1, (int) $vendor->solver_threads);
    }

    /**
     * INCR returns the post-increment value and is atomic across processes, which is what
     * keeps two workers claiming the same instant from landing on the same vendor.
     */
    private static function advance(int $lapLength): int
    {
        return ((int) Redis::incr(self::CURSOR_KEY) - 1) % $lapLength;
    }
}
