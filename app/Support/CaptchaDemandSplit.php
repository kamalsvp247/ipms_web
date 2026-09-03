<?php

namespace App\Support;

use App\Models\CaptchaProvider;
use Illuminate\Support\Collection;

/**
 * Which providers a pool-filler pass dispatches to, and how many each takes.
 *
 * The goal is the shortest possible time to a full pool, which makes this a work-conserving
 * allocator rather than a share-out: it hands every provider exactly as many captchas as it
 * has free slots for right now, and nothing more. Whatever a provider finishes, it is handed
 * again on the next pass 50ms later, so the pool fills at the sum of what the providers can
 * actually deliver.
 *
 * That is a deliberate replacement for pinning the whole deficit up front, which was the real
 * brake on time-to-full. Dispatching 250 captchas in one pass pinned ~28 of them to a
 * two-thread vendor — fourteen sequential rounds at vendor latency, minutes long — while the
 * in-house fleet finished its share in seconds and then sat idle, because the deficit was
 * already spoken for. Nothing was overloaded and nothing was broken; the work was simply
 * committed to the slowest lane before anyone knew who would be free.
 *
 * The two tiers are independent producers here, not a priority chain. Both are handed work in
 * the same pass and neither's state can gate the other: a saturated fleet does not delay a
 * vendor, and an idle fleet no longer swallows a whole pass before a vendor sees any of it.
 *
 * In-flight counts come from the caller's own query rather than the Redis slot counters. The
 * counters are the right enforcement point inside SolveCaptchaJob, where 16 workers claim
 * against them atomically, but they can strand high when a worker dies mid-job — and a
 * stranded counter here would quietly stop dispatching to a provider that is in fact idle.
 * Rows cannot strand that way: the lease reaper and the vendor task timeout settle them.
 */
class CaptchaDemandSplit
{
    /**
     * One entry per captcha to dispatch this pass, in dispatch order.
     *
     * @param  int  $needed  Deficit not already covered by work in flight.
     * @param  Collection<int, CaptchaProvider>  $providers  Providers generation may draw from.
     * @param  int  $fleetLimit  In-flight ceiling for the whole fleet — CaptchaNodeFleet::queueLimit().
     * @param  array<int, int>  $inFlight  Solves already committed per provider id.
     * @param  int  $pass  Monotonic pass number, used to rotate which vendor leads.
     * @param  int  $fleetSlack  Extra solves the fleet may run beyond the deficit — see tail().
     * @return list<CaptchaProvider>
     */
    public static function plan(
        int $needed,
        Collection $providers,
        int $fleetLimit,
        array $inFlight = [],
        int $pass = 0,
        int $fleetSlack = 0,
    ): array {
        /** @var list<array{provider: CaptchaProvider, free: int}> $fleetLanes */
        $fleetLanes = [];
        /** @var list<array{provider: CaptchaProvider, free: int}> $vendorLanes */
        $vendorLanes = [];

        foreach ($providers as $provider) {
            $free = max(0, self::capacityFor($provider, $fleetLimit) - max(0, $inFlight[$provider->id] ?? 0));

            if ($free < 1) {
                continue;
            }

            $lane = ['provider' => $provider, 'free' => $free];

            if ($provider->type->isInHouse()) {
                $fleetLanes[] = $lane;
            } else {
                $vendorLanes[] = $lane;
            }
        }

        // Rotated so the vendor leading a truncated pass changes, rather than the lowest id
        // taking every scarce top-up while the rest of the vendors never see one.
        if ($vendorLanes !== []) {
            $lead = $pass % count($vendorLanes);
            $vendorLanes = array_merge(array_slice($vendorLanes, $lead), array_slice($vendorLanes, 0, $lead));
        }

        $plan = self::roundRobin(array_merge($fleetLanes, $vendorLanes), $needed);

        return array_merge($plan, self::tail($fleetLanes, $plan, $fleetSlack));
    }

    /**
     * One slot per provider per round, so every provider with room has a solve in flight before
     * any provider gets a second, and a pass smaller than the fleet's free capacity still
     * reaches the vendors — which the old fleet-budget-first order did not.
     *
     * @param  list<array{provider: CaptchaProvider, free: int}>  $lanes
     * @return list<CaptchaProvider>
     */
    private static function roundRobin(array $lanes, int $needed): array
    {
        if ($needed < 1 || $lanes === []) {
            return [];
        }

        $deepest = 0;

        foreach ($lanes as $lane) {
            $deepest = max($deepest, $lane['free']);
        }

        $plan = [];

        for ($round = 0; $round < $deepest && count($plan) < $needed; $round++) {
            foreach ($lanes as $lane) {
                if ($lane['free'] <= $round) {
                    continue;
                }

                $plan[] = $lane['provider'];

                if (count($plan) >= $needed) {
                    break;
                }
            }
        }

        return $plan;
    }

    /**
     * Spare fleet work dispatched past the deficit, to cover the end of a fill.
     *
     * The last stretch is where time-to-full is decided and where the naive rule is worst. Once
     * work in flight covers what the pool still lacks, the deficit reads zero and the pass
     * dispatches nothing at all — so the final tokens are hostage to whichever provider happens
     * to hold them, and one 15s vendor solve sets the finishing time however idle the fleet is.
     * A measured fill reached 246 of 250 in 29s and then spent 10s waiting on four stragglers.
     *
     * Backing that stretch with spare fleet capacity costs nothing: the hardware is bought and
     * idle, a node solve lands in ~3s, and the worst case is a handful of surplus tokens the
     * pool purges on shelf life. Deliberately fleet-only — overshooting the operator's limit is
     * acceptable on free hardware and never on a paid key.
     *
     * @param  list<array{provider: CaptchaProvider, free: int}>  $fleetLanes
     * @param  list<CaptchaProvider>  $planned
     * @return list<CaptchaProvider>
     */
    private static function tail(array $fleetLanes, array $planned, int $fleetSlack): array
    {
        if ($fleetSlack < 1 || $fleetLanes === []) {
            return [];
        }

        $lane = $fleetLanes[0];

        $alreadyPlanned = 0;

        foreach ($planned as $provider) {
            if ($provider->is($lane['provider'])) {
                $alreadyPlanned++;
            }
        }

        $room = min($fleetSlack, $lane['free'] - $alreadyPlanned);

        return $room < 1 ? [] : array_fill(0, $room, $lane['provider']);
    }

    /**
     * How many solves may be in flight against one provider.
     *
     * A vendor's ceiling is its own solver_threads — what that specific paid key is allowed to
     * run at once. The fleet's is the aggregate its nodes report, which the caller passes in
     * because it moves as nodes come and go. Mirrors SolveCaptchaJob::slotLimitFor(), which
     * enforces the same numbers at claim time.
     */
    private static function capacityFor(CaptchaProvider $provider, int $fleetLimit): int
    {
        if ($provider->type->isInHouse()) {
            return max(0, $fleetLimit);
        }

        return max(1, (int) $provider->solver_threads);
    }
}
