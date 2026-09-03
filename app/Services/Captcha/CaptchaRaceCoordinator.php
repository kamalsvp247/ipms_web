<?php

namespace App\Services\Captcha;

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Support\CaptchaGenerationMode;
use App\Support\CaptchaVendorRotation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Races several providers for one on-demand captcha and banks everything that loses.
 *
 * A single provider used to decide the latency of a request the booking window cannot wait
 * for. Solve times are heavy-tailed — a vendor that normally answers in four seconds
 * occasionally takes twenty, and an in-house node can complete the whole Cloudflare
 * challenge and still return nothing at all — so whichever account drew the outlier paid
 * for it in full, then paid again for the retry. Firing at several providers at once makes
 * the delivered latency the minimum of the field instead of a single sample from it.
 *
 * Nothing is thrown away to buy that. A losing attempt still finishes, and its token is
 * written straight into the pool where the next request claims it for free, so the extra
 * spend is pulled-forward inventory rather than a discarded solve. The bill only really
 * grows while demand outruns the pool, which is exactly when latency is worth paying for.
 *
 * Row roles, all living in captcha_requests and told apart by `source`:
 *  - on_demand — the row the bot polls. A pure delivery slot: it is never itself sent to a
 *                provider, which is what keeps the promotion rules below unambiguous.
 *  - race      — one solve attempt, pointing at its delivery slot via race_parent_id.
 *  - pool      — solved inventory, and where every losing attempt ends up.
 */
class CaptchaRaceCoordinator
{
    public const SOURCE_DEMAND = 'on_demand';

    public const SOURCE_RACE = 'race';

    public const SOURCE_POOL = 'pool';

    /** How many attempts a single on-demand request is split into. */
    public const DEFAULT_WIDTH = 3;

    public const MIN_WIDTH = 1;

    public const MAX_WIDTH = 8;

    private const WIDTH_KEY = 'captcha:race_width';

    public function __construct(private readonly CaptchaNodeFleet $fleet) {}

    /**
     * Lives in Redis rather than config because the queue workers, the poller and the web
     * tier are separate long-running processes that must agree on it, and because widening
     * the race during a booking window has to take effect without a redeploy.
     */
    public function width(): int
    {
        $stored = Redis::get(self::WIDTH_KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_WIDTH;
        }

        return max(self::MIN_WIDTH, min(self::MAX_WIDTH, (int) $stored));
    }

    public function setWidth(int $width): void
    {
        Redis::set(self::WIDTH_KEY, max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width)));
    }

    /**
     * Fire one on-demand request at several providers at once.
     *
     * Returns the number of attempts started. Zero means the demand row was failed outright
     * because nothing could run it — a definitive answer the bot reads on its first poll,
     * instead of a 60s wait for a timeout sweep to reach the same conclusion.
     */
    public function launch(CaptchaRequest $demand): int
    {
        $targets = $this->targets();

        if ($targets === []) {
            $demand->update([
                'status' => CaptchaRequestStatus::Failed,
                'error_message' => $this->unavailableReason(),
            ]);

            return 0;
        }

        foreach ($targets as $provider) {
            $attempt = CaptchaRequest::create([
                'request_id' => Str::uuid()->toString(),
                'type' => $demand->type,
                'status' => CaptchaRequestStatus::Pending,
                'source' => self::SOURCE_RACE,
                'race_parent_id' => $demand->id,
            ]);

            // The priority queue, because a pool solve can wait a beat and an account
            // standing at the gate cannot.
            SolveCaptchaJob::dispatch($attempt->id, $provider->id, 'captcha_priority');
        }

        return count($targets);
    }

    /**
     * Record a solved attempt, delivering it to the waiting bot if it got there first.
     *
     * Safe to call for any request: a row that is not part of a race simply goes Ready,
     * which is what every caller did inline before racing existed.
     */
    public function settleSolved(CaptchaRequest $request, string $token): void
    {
        $solvedAt = now();

        if ($request->race_parent_id === null) {
            $request->update([
                'status' => CaptchaRequestStatus::Ready,
                'token' => $token,
                'solved_at' => $solvedAt,
                'lease_expires_at' => null,
            ]);

            return;
        }

        if ($this->promote($request, $token, $solvedAt)) {
            // The token now lives on the row the bot is polling, so this one owns nothing.
            // Deleting beats leaving a second copy behind: a Turnstile token is single-use,
            // and a duplicate sitting in the pool would be handed out and rejected by IVAC.
            $request->delete();

            return;
        }

        // Beaten to it. The solve is finished and paid for either way, so it becomes
        // inventory instead of waste. Phone and slot are dropped with it — they exist to
        // attribute one bot request, and a pool token belongs to whoever claims it next.
        $request->update([
            'status' => CaptchaRequestStatus::Ready,
            'token' => $token,
            'solved_at' => $solvedAt,
            'lease_expires_at' => null,
            'source' => self::SOURCE_POOL,
            'race_parent_id' => null,
            'phone' => null,
            'agent_slot_id' => null,
        ]);
    }

    /**
     * Record a failed attempt and, if it was the last one racing, release the bot.
     */
    public function settleFailed(CaptchaRequest $request, string $error): void
    {
        $request->update([
            'status' => CaptchaRequestStatus::Failed,
            'error_message' => substr($error, 0, 500),
            'lease_expires_at' => null,
        ]);

        if ($request->race_parent_id !== null) {
            $this->failExhaustedDemand($request->race_parent_id, $error);
        }
    }

    /**
     * Store a token nobody is waiting for as pool inventory.
     */
    public function bank(string $token, ?int $providerId = null): CaptchaRequest
    {
        return CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Ready,
            'source' => self::SOURCE_POOL,
            'token' => $token,
            'provider_id' => $providerId,
            'solved_at' => now(),
        ]);
    }

    /**
     * Hand this attempt's token to the delivery slot, if it is still unclaimed.
     *
     * The lock is what makes the race a race: several attempts can finish in the same
     * millisecond, and exactly one of them may transition the slot out of Pending.
     */
    private function promote(CaptchaRequest $attempt, string $token, CarbonInterface $solvedAt): bool
    {
        return (bool) DB::transaction(function () use ($attempt, $token, $solvedAt) {
            $demand = CaptchaRequest::whereKey($attempt->race_parent_id)
                ->lockForUpdate()
                ->first();

            // Gone means the bot consumed or abandoned it; anything other than Pending
            // means a sibling already delivered, or a sweep gave up on the whole race.
            if ($demand === null || $demand->status !== CaptchaRequestStatus::Pending) {
                return false;
            }

            $demand->update([
                'status' => CaptchaRequestStatus::Ready,
                'token' => $token,
                'solved_at' => $solvedAt,
                'provider_id' => $attempt->provider_id,
                'error_message' => null,
            ]);

            return true;
        });
    }

    /**
     * Fail the delivery slot once its last attempt is gone.
     *
     * A demand row carries no provider of its own, so nothing in the vendor poller or the
     * fleet can ever fail it. Without this the bot would poll a race that is already dead
     * for its full 65s budget instead of being told immediately to ask again.
     */
    private function failExhaustedDemand(int $demandId, string $error): void
    {
        $stillRacing = CaptchaRequest::where('race_parent_id', $demandId)
            ->whereIn('status', [
                CaptchaRequestStatus::Pending->value,
                CaptchaRequestStatus::Processing->value,
            ])
            ->exists();

        if ($stillRacing) {
            return;
        }

        CaptchaRequest::whereKey($demandId)
            ->where('status', CaptchaRequestStatus::Pending->value)
            ->update([
                'status' => CaptchaRequestStatus::Failed->value,
                'error_message' => substr('Every racing provider failed: '.$error, 0, 500),
            ]);
    }

    /**
     * The providers this race fires at, best first.
     *
     * Distinct providers come first because independent keys and independent hardware are
     * what actually decorrelate the tail — two attempts on one vendor share its outage.
     * Any width left over goes back to the fleet, and only to the fleet: a node solve costs
     * hardware already bought, and a second one covers the failure mode where a node runs
     * the whole challenge and silently returns no token.
     *
     * @return list<CaptchaProvider>
     */
    private function targets(): array
    {
        $providers = CaptchaGenerationMode::scope(CaptchaProvider::query())->orderBy('id')->get();

        [$inHouse, $vendors] = $providers->partition(fn (CaptchaProvider $p) => $p->type->isInHouse());

        // With no node online the fleet cannot solve anything, so it must not consume a
        // lane — otherwise a race of three would spend two of its attempts on nothing.
        $fleetProvider = $this->fleet->capacity() > 0 ? $inHouse->first() : null;

        $lane = $fleetProvider === null
            ? CaptchaVendorRotation::order($vendors)
            : array_merge([$fleetProvider], CaptchaVendorRotation::order($vendors));

        if ($lane === []) {
            return [];
        }

        $width = $this->width();
        $targets = array_slice($lane, 0, $width);

        while (count($targets) < $width && $fleetProvider !== null) {
            $targets[] = $fleetProvider;
        }

        return $targets;
    }

    /**
     * Why a race could not start at all. Mirrors SolveCaptchaJob's wording so the bot sees
     * one message for one cause regardless of which layer noticed it.
     */
    private function unavailableReason(): string
    {
        if (! CaptchaGenerationMode::scope(CaptchaProvider::query())->exists()) {
            return CaptchaProvider::exists()
                ? 'No captcha providers are enabled.'
                : 'No captcha providers configured.';
        }

        return 'No captcha solver nodes are online.';
    }
}
