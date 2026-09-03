<?php

namespace App\Console\Commands;

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Support\CaptchaDemandSplit;
use App\Support\CaptchaGenerationMode;
use App\Support\CaptchaPoolExpiry;
use App\Support\CaptchaVendorRotation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class FillCaptchaPoolCommand extends Command
{
    protected $signature = 'captcha:fill-pool';

    protected $description = 'Continuously fills the captcha token pool to the configured limit.';

    /**
     * Cadence while providers are producing. Every freed slot waits at most this long to be
     * refilled, and a pass is two indexed queries — cheap enough to run at this rate, and the
     * difference between a pool that fills at provider speed and one that fills at poll speed.
     */
    private const BUSY_SLEEP_US = 50_000;

    /** Cadence when the pool is full or nothing has room. */
    private const IDLE_SLEEP_US = 200_000;

    /**
     * How many spare solves the fleet may run past the deficit, to keep a slow straggler off
     * the critical path at the end of a fill. Small on purpose: it is insurance against one
     * provider setting the finishing time, not a second pool.
     */
    private const TAIL_SLACK = 12;

    /** Counts passes so a short top-up does not always land on the same provider. */
    private int $pass = 0;

    public function handle(CaptchaNodeFleet $fleet): int
    {
        $this->info('Captcha pool filler started.');

        CaptchaVendorRotation::reset();

        while (true) {
            $this->purgeExpired();
            $dispatched = $this->fillPool($fleet);
            usleep($dispatched > 0 ? self::BUSY_SLEEP_US : self::IDLE_SLEEP_US);
        }

        return self::SUCCESS; // @phpstan-ignore-line
    }

    /**
     * Hand every provider with a free slot one more captcha.
     *
     * Only what can start now is dispatched, so the deficit is never committed to a lane
     * before it is known to be free — see CaptchaDemandSplit for why that is what decides
     * time-to-full.
     *
     * @return int how many were dispatched.
     */
    private function fillPool(CaptchaNodeFleet $fleet): int
    {
        $limit = (int) (Redis::get('captcha:pool_limit') ?? 100);

        $counts = CaptchaRequest::where('source', 'pool')
            ->whereIn('status', ['pending', 'processing', 'ready'])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $ready = (int) ($counts['ready'] ?? 0);
        $active = $ready + (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);

        // What the pool is short of, and how much of that is not yet covered by work already
        // in flight. The gap between them is the tail: solves that are supposed to arrive, and
        // whose arrival time is set by whichever provider is holding them.
        $shortfall = max(0, $limit - $ready);
        $needed = max(0, $limit - $active);

        if ($shortfall === 0) {
            return 0;
        }

        // Honours the mode picked when the filler was started: every provider, or only
        // the ones switched on. Re-read each pass so a provider toggled mid-run takes
        // effect without restarting the filler.
        $providers = CaptchaGenerationMode::scope(CaptchaProvider::query())->orderBy('id')->get();

        if ($providers->isEmpty()) {
            return 0;
        }

        $plan = CaptchaDemandSplit::plan(
            needed: $needed,
            providers: $providers,
            fleetLimit: $fleet->queueLimit(),
            inFlight: $this->inFlightByProvider(),
            pass: $this->pass++,
            fleetSlack: min(self::TAIL_SLACK, $shortfall - $needed),
        );

        foreach ($plan as $provider) {
            $req = CaptchaRequest::create([
                'request_id' => Str::uuid()->toString(),
                'type' => CaptchaTokenType::Turnstile,
                'status' => CaptchaRequestStatus::Pending,
                'source' => 'pool',
                // Recorded up front so the next pass, 50ms later, can see this solve is
                // already committed to that provider. Without it the same free slot is
                // dispatched to repeatedly until the worker starts and claims it.
                'provider_id' => $provider->id,
            ]);

            SolveCaptchaJob::dispatch($req->id, $provider->id);
        }

        return count($plan);
    }

    /**
     * Solves already committed per provider: running, plus dispatched and not yet started.
     *
     * @return array<int, int>
     */
    private function inFlightByProvider(): array
    {
        return CaptchaRequest::whereNotNull('provider_id')
            ->whereIn('status', [CaptchaRequestStatus::Pending->value, CaptchaRequestStatus::Processing->value])
            ->groupBy('provider_id')
            ->selectRaw('provider_id, COUNT(*) as cnt')
            ->pluck('cnt', 'provider_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function purgeExpired(): void
    {
        $cutoff = CaptchaPoolExpiry::cutoff();

        $queries = [
            fn () => CaptchaRequest::where('source', 'pool')
                ->where('status', CaptchaRequestStatus::Ready)
                ->where('solved_at', '<', $cutoff)
                ->delete(),

            // Orphaned pending/processing entries (queue worker died mid-job)
            fn () => CaptchaRequest::where('source', 'pool')
                ->whereIn('status', [CaptchaRequestStatus::Pending->value, CaptchaRequestStatus::Processing->value])
                ->where('created_at', '<', now()->subSeconds(120))
                ->delete(),

            // Old failures
            fn () => CaptchaRequest::where('source', 'pool')
                ->where('status', CaptchaRequestStatus::Failed)
                ->where('updated_at', '<', now()->subMinutes(5))
                ->delete(),
        ];

        foreach ($queries as $query) {
            try {
                $query();
            } catch (Throwable) {
                // Deadlock or transient DB error — skip this purge cycle, retry next tick
            }
        }
    }
}
