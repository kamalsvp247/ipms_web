<?php

namespace App\Services\Captcha;

use App\Enums\CaptchaRequestStatus;
use App\Models\CaptchaNode;
use App\Models\CaptchaNodeDailyStat;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\Redis;

/**
 * Work distribution for the in-house captcha solver fleet.
 *
 * A solve costs ~4 CPU-seconds of headless Chrome, so capacity is bought in cores and the
 * only way to scale is more machines. Nodes pull work from here rather than exposing a
 * port, mirroring how the Java bot takes commands off its heartbeat: the portal owns the
 * queue, the lease and every timestamp, and a node needs nothing inbound.
 *
 * Everything a node ever touches is the RAW Turnstile token. Login/reserve encryption stays
 * on the portal in CaptchaRequestController::show(), because it needs the live IVAC bundle
 * and encrypt_meta.json that only the portal host carries.
 */
class CaptchaNodeFleet
{
    /** Shared work queue: DB ids of requests waiting for any node. */
    public const QUEUE_KEY = 'captcha:fleet:queue';

    /** Aggregate concurrency of every available node, recomputed on each heartbeat. */
    private const CAPACITY_KEY = 'captcha:fleet_capacity';

    /** Seconds the cached capacity survives without a heartbeat refreshing it. */
    private const CAPACITY_TTL = 5;

    /**
     * Solve budget handed to a node. Deliberately under LEASE_TTL_SECONDS so a node that
     * exhausts its retries still has room to report the failure before the reaper decides
     * the lease is dead and requeues the work behind its back.
     */
    public const SOLVE_BUDGET_MS = 30_000;

    /**
     * How long a leased request stays owned by its node.
     *
     * Under PollCaptchaTasksCommand::timeoutStale()'s 60s so the reaper — which can requeue
     * onto a healthy node — always wins the race against the blanket timeout, which can
     * only fail the request.
     */
    public const LEASE_TTL_SECONDS = 40;

    /**
     * A request that is Processing with no lease for this long was pushed onto a queue that
     * no longer holds it (a Redis flush, or a crash between the DB write and the LPUSH).
     */
    private const ORPHAN_AFTER_SECONDS = 15;

    /** Past this age an unleased request is abandoned rather than re-pushed forever. */
    private const ORPHAN_MAX_AGE_SECONDS = 90;

    /** An expired lease is retried once; the second expiry fails the request. */
    private const MAX_LEASE_ATTEMPTS = 2;

    /**
     * Sum of what every available node says it can run at once.
     *
     * Cached because acquireSlot() calls this on every solve dispatch, and a stale value is
     * harmless in both directions: too high just means a node queues an extra item, too low
     * means one request goes to a vendor.
     */
    public function capacity(): int
    {
        $cached = Redis::get(self::CAPACITY_KEY);

        if ($cached !== null) {
            return (int) $cached;
        }

        return $this->refreshCapacity();
    }

    /**
     * Recompute and cache aggregate capacity. Called from the heartbeat so the number
     * tracks the fleet without every dispatch paying for a query.
     */
    public function refreshCapacity(): int
    {
        CaptchaNode::available()
            ->where('last_heartbeat_at', '<', now()->subSeconds(90))
            ->update(['status' => 'offline', 'active' => 0, 'queued' => 0]);

        $capacity = (int) CaptchaNode::available()->sum('reported_concurrency');

        Redis::setex(self::CAPACITY_KEY, self::CAPACITY_TTL, $capacity);

        return $capacity;
    }

    /**
     * How many solves may be in flight against the fleet at once.
     *
     * Above raw capacity by a small factor so every node always has something to pull on
     * its next lease and the pipe never drains between polls, but bounded so an on-demand
     * request during the booking window never queues behind a deep pool backlog.
     */
    public function queueLimit(): int
    {
        $capacity = $this->capacity();

        if ($capacity === 0) {
            return 0;
        }

        $factor = (float) (Redis::get('captcha:fleet_queue_factor') ?? 1.5);

        return max($capacity, (int) ceil($capacity * max(1.0, $factor)));
    }

    /**
     * Hand a request to the fleet. Returns immediately — the solve happens on a node.
     *
     * @param  CaptchaNode|null  $target  Pin to one node (used by the per-node test solve).
     */
    public function enqueue(CaptchaRequest $request, int $providerId, ?CaptchaNode $target = null): void
    {
        $request->update([
            'status' => CaptchaRequestStatus::Processing,
            'provider_id' => $providerId,
            'node_id' => null,
            'leased_at' => null,
            'lease_expires_at' => null,
        ]);

        Redis::lpush($this->queueKey($target), (string) $request->id);
    }

    /**
     * Claim up to $capacity queued requests for a node.
     *
     * The RPOP is atomic and the DB update is conditional on the row still being unclaimed,
     * so two nodes racing the same id cannot both win, and a duplicate id left in the queue
     * by the orphan sweep is a harmless no-op.
     *
     * @return list<array{request_id: string, site_key: string, page_url: string, timeout_ms: int}>
     */
    public function lease(CaptchaNode $node, int $capacity): array
    {
        if ($capacity < 1 || ! $node->enabled || $node->isPaused()) {
            return [];
        }

        $setting = Setting::instance();
        $siteKey = (string) $setting->captcha_site_key;
        $pageUrl = (string) $setting->captcha_page_url;

        if ($siteKey === '' || $pageUrl === '') {
            return [];
        }

        $leased = [];

        // The node's own queue first: a targeted test solve must not be stolen by whichever
        // node happens to poll next.
        foreach ([$this->queueKey($node), self::QUEUE_KEY] as $queue) {
            while (count($leased) < $capacity) {
                $id = Redis::rpop($queue);

                if ($id === null || $id === false) {
                    break;
                }

                $claimed = CaptchaRequest::whereKey((int) $id)
                    ->where('status', CaptchaRequestStatus::Processing)
                    ->whereNull('node_id')
                    ->update([
                        'node_id' => $node->id,
                        'leased_at' => now(),
                        'lease_expires_at' => now()->addSeconds(self::LEASE_TTL_SECONDS),
                    ]);

                if ($claimed === 0) {
                    continue;
                }

                $request = CaptchaRequest::find((int) $id);

                if (! $request) {
                    continue;
                }

                $leased[] = [
                    'request_id' => $request->request_id,
                    'site_key' => $siteKey,
                    'page_url' => $pageUrl,
                    'timeout_ms' => self::SOLVE_BUDGET_MS,
                ];
            }
        }

        return $leased;
    }

    /**
     * Record a node's answer for one request.
     *
     * Idempotent by construction: it only transitions rows still Processing and still owned
     * by this node, so a result POST retried after a network blip is a no-op rather than a
     * double release of the provider slot.
     */
    public function complete(CaptchaNode $node, string $requestId, ?string $token, ?string $error): bool
    {
        $request = CaptchaRequest::where('request_id', $requestId)
            ->where('node_id', $node->id)
            ->where('status', CaptchaRequestStatus::Processing)
            ->first();

        if (! $request) {
            return false;
        }

        // Read before settling: a racing attempt that wins is deleted once its token has
        // been moved onto the row the bot polls, and the slot still has to be released.
        $providerId = $request->provider_id;
        $leasedAt = $request->leased_at;

        // Resolved rather than injected because the coordinator depends on this class for
        // fleet capacity, and constructor injection both ways is a cycle the container
        // cannot build.
        $races = app(CaptchaRaceCoordinator::class);

        if (is_string($token) && $token !== '') {
            $solvedAt = now();

            // Stored raw — CaptchaRequestController applies the login/reserve transform at
            // delivery time, exactly as it does for vendor tokens.
            $races->settleSolved($request, $token);

            if ($providerId) {
                Redis::incr("captcha:provider:{$providerId}:count");
            }

            CaptchaNodeDailyStat::record(
                $node->id,
                true,
                $leasedAt?->diffInMilliseconds($solvedAt),
            );
        } else {
            $races->settleFailed($request, $error ?? 'Node returned no token.');

            CaptchaNodeDailyStat::record($node->id, false);
        }

        $this->releaseProviderSlot($providerId);

        return true;
    }

    /**
     * Return dead work to the queue.
     *
     * Covers a node that was stopped or lost mid-solve (lease expired) and work that was
     * marked Processing but never reached the queue (crash between the write and the LPUSH,
     * or a Redis flush). Retried once, then failed so the pool filler stops waiting on it.
     *
     * @return array{requeued: int, failed: int}
     */
    public function reapExpiredLeases(): array
    {
        $requeued = 0;
        $failed = 0;

        $expired = CaptchaRequest::where('status', CaptchaRequestStatus::Processing)
            ->whereNotNull('node_id')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->limit(200)
            ->get();

        $races = app(CaptchaRaceCoordinator::class);

        foreach ($expired as $request) {
            if ($request->lease_attempts + 1 >= self::MAX_LEASE_ATTEMPTS) {
                $races->settleFailed($request, 'Solver node lease expired twice without a result.');

                $this->releaseProviderSlot($request->provider_id);
                $failed++;

                continue;
            }

            $request->update([
                'node_id' => null,
                'leased_at' => null,
                'lease_expires_at' => null,
                'lease_attempts' => $request->lease_attempts + 1,
            ]);

            Redis::lpush(self::QUEUE_KEY, (string) $request->id);
            $requeued++;
        }

        $orphans = CaptchaRequest::where('status', CaptchaRequestStatus::Processing)
            ->whereNull('node_id')
            ->whereNull('vendor_task_id')
            ->where('updated_at', '<', now()->subSeconds(self::ORPHAN_AFTER_SECONDS))
            ->limit(200)
            ->get();

        foreach ($orphans as $request) {
            // A request nobody has leased for this long is not coming back: every consumer
            // has already given up on it (the bot polls for 65s), so failing it releases the
            // provider slot instead of re-pushing forever.
            if ($request->created_at->diffInSeconds(now()) > self::ORPHAN_MAX_AGE_SECONDS) {
                $races->settleFailed($request, 'No solver node picked up this request.');

                $this->releaseProviderSlot($request->provider_id);
                $failed++;

                continue;
            }

            // Touch updated_at so the same row is not re-pushed every pass while it waits
            // its turn in a long queue. A duplicate id already in the queue is harmless —
            // the conditional claim in lease() rejects the second one.
            $request->touch();

            Redis::lpush(self::QUEUE_KEY, (string) $request->id);
            $requeued++;
        }

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    /**
     * Drop everything the fleet has queued. Used when the last node goes away, so the pool
     * filler stops counting dead in-flight work and routes to a vendor instead.
     */
    public function drain(): int
    {
        Redis::del(self::QUEUE_KEY);

        $stranded = CaptchaRequest::where('status', CaptchaRequestStatus::Processing)
            ->whereNull('vendor_task_id')
            ->get();

        $races = app(CaptchaRaceCoordinator::class);

        foreach ($stranded as $request) {
            $races->settleFailed($request, 'No captcha solver nodes are online.');

            $this->releaseProviderSlot($request->provider_id);
        }

        return $stranded->count();
    }

    public function queueDepth(): int
    {
        return (int) Redis::llen(self::QUEUE_KEY);
    }

    private function queueKey(?CaptchaNode $node): string
    {
        return $node === null ? self::QUEUE_KEY : self::QUEUE_KEY.':'.$node->id;
    }

    /**
     * Give a provider slot back. The fleet dispatch path owns this because, unlike a vendor
     * task, no poller ever sees these rows.
     */
    private function releaseProviderSlot(?int $providerId): void
    {
        if ($providerId === null) {
            return;
        }

        if ((int) Redis::decr("captcha:provider:{$providerId}:active") < 0) {
            // A counter driven negative silently widens the concurrency cap, which is how
            // the old syncProviderSlots() bug hid itself. Clamp rather than let it drift.
            Redis::set("captcha:provider:{$providerId}:active", 0);
        }
    }
}
