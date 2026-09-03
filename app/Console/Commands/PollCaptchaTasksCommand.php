<?php

namespace App\Console\Commands;

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class PollCaptchaTasksCommand extends Command
{
    protected $signature = 'captcha:poll-tasks';

    protected $description = 'Continuously polls captcha providers in parallel for task results.';

    private const BATCH_LIMIT = 200;

    private const TASK_TIMEOUT_SECONDS = 60;

    private const SLOT_RESYNC_INTERVAL_SECONDS = 300;

    private int $lastResyncAt = 0;

    public function handle(CaptchaNodeFleet $fleet, CaptchaRaceCoordinator $races): int
    {
        $this->info('Captcha task poller started.');

        while (true) {
            try {
                $this->pollBatch($races);
                // Fleet leases expire at 40s, deliberately inside timeoutStale()'s 60s, so
                // work from a node that died mid-solve is requeued onto a healthy node
                // rather than blanket-failed.
                $fleet->reapExpiredLeases();
                $this->timeoutStale();
                $this->periodicSlotResync();
            } catch (Throwable $e) {
                $this->error('Poll error: '.$e->getMessage());
            }

            usleep(250_000);
        }

        return self::SUCCESS; // @phpstan-ignore-line
    }

    private function pollBatch(CaptchaRaceCoordinator $races): void
    {
        $requests = CaptchaRequest::with('provider')
            ->where('status', CaptchaRequestStatus::Processing)
            ->whereNotNull('vendor_task_id')
            ->whereNotNull('provider_id')
            ->orderBy('updated_at')
            ->limit(self::BATCH_LIMIT)
            ->get()
            // In-house requests are completed inline by SolveCaptchaJob and never carry a
            // vendor task ID, so they should not appear here at all. Filtered defensively:
            // without it an in-house row would fall through to the 2captcha-compatible
            // branch and POST its (empty) API key to captchaai.
            ->filter(fn (CaptchaRequest $r) => $r->provider !== null && ! $r->provider->type->isInHouse())
            ->values();

        if ($requests->isEmpty()) {
            return;
        }

        $responses = Http::pool(function (Pool $pool) use ($requests) {
            return $requests->map(fn (CaptchaRequest $r) => $this->buildPollRequest($pool, $r))->all();
        });

        foreach ($requests as $i => $r) {
            $response = $responses[$i] ?? null;
            $this->processResponse($r, $response, $races);
        }
    }

    private function buildPollRequest(Pool $pool, CaptchaRequest $r)
    {
        $provider = $r->provider;

        if ($this->isJsonApi($provider->type)) {
            return $pool->timeout(15)->post($this->jsonApiBase($provider->type).'/getTaskResult', [
                'clientKey' => $provider->api_key,
                'taskId' => ctype_digit($r->vendor_task_id) ? (int) $r->vendor_task_id : $r->vendor_task_id,
            ]);
        }

        return $pool->timeout(15)->asForm()->post($this->formApiBase($provider->type).'/res.php', [
            'key' => $provider->api_key,
            'action' => 'get',
            'id' => $r->vendor_task_id,
            'json' => 1,
        ]);
    }

    private function processResponse(CaptchaRequest $r, $response, CaptchaRaceCoordinator $races): void
    {
        if ($response instanceof Throwable || $response === null) {
            return;
        }

        try {
            $body = $response->json();
        } catch (Throwable $e) {
            return;
        }

        if (! is_array($body)) {
            return;
        }

        $provider = $r->provider;

        if ($this->isJsonApi($provider->type)) {
            if (($body['errorId'] ?? 0) !== 0) {
                Redis::decr("captcha:provider:{$provider->id}:active");
                $races->settleFailed($r, 'Provider getTaskResult error: '.substr(($body['errorDescription'] ?? json_encode($body)), 0, 400));

                return;
            }

            if (($body['status'] ?? '') !== 'ready') {
                return;
            }

            $token = $body['solution']['gRecaptchaResponse'] ?? $body['solution']['token'] ?? null;

            if ($token === null) {
                Redis::decr("captcha:provider:{$provider->id}:active");
                $races->settleFailed($r, 'Provider returned ready without token.');

                return;
            }

            Redis::decr("captcha:provider:{$provider->id}:active");
            Redis::incr("captcha:provider:{$provider->id}:count");
            $races->settleSolved($r, $token);

            return;
        }

        $result = $body['request'] ?? 'unknown';

        if ($result === 'CAPCHA_NOT_READY') {
            return;
        }

        if (($body['status'] ?? 0) !== 1) {
            Redis::decr("captcha:provider:{$provider->id}:active");
            $races->settleFailed($r, 'Provider getTaskResult error: '.substr((string) $result, 0, 400));

            return;
        }

        Redis::decr("captcha:provider:{$provider->id}:active");
        Redis::incr("captcha:provider:{$provider->id}:count");
        $races->settleSolved($r, (string) $result);
    }

    private function timeoutStale(): void
    {
        $affected = CaptchaRequest::where('status', CaptchaRequestStatus::Processing)
            ->where('updated_at', '<', now()->subSeconds(self::TASK_TIMEOUT_SECONDS))
            ->update([
                'status' => CaptchaRequestStatus::Failed,
                'error_message' => 'Task polling timeout — provider never returned a solution.',
            ]);

        // Bulk update can't DECR per-row, so resync from DB truth when any row timed out
        if ($affected > 0) {
            $this->syncProviderSlots();
        }

        $this->timeoutExhaustedRaces();
    }

    /**
     * Fail on-demand rows whose whole race is dead.
     *
     * The coordinator already releases a delivery slot when the last attempt settles
     * through it, but the bulk timeout above writes straight to the table and so tells it
     * nothing. This is the backstop that covers that path and any future one: a slot with
     * no attempt left alive is a request no provider can ever answer, and holding it Pending
     * only buys the bot a full 65s of pointless polling.
     */
    private function timeoutExhaustedRaces(): void
    {
        CaptchaRequest::where('source', CaptchaRaceCoordinator::SOURCE_DEMAND)
            ->where('status', CaptchaRequestStatus::Pending->value)
            ->where('created_at', '<', now()->subSeconds(self::TASK_TIMEOUT_SECONDS))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('captcha_requests as attempts')
                    ->whereColumn('attempts.race_parent_id', 'captcha_requests.id')
                    ->whereIn('attempts.status', [
                        CaptchaRequestStatus::Pending->value,
                        CaptchaRequestStatus::Processing->value,
                    ]);
            })
            ->update([
                'status' => CaptchaRequestStatus::Failed->value,
                'error_message' => 'Every racing provider failed to return a token.',
            ]);
    }

    /**
     * Resyncs provider slot counters every 5 minutes to recover from counter
     * drift caused by crashes or DB connection failures mid-job.
     */
    private function periodicSlotResync(): void
    {
        $now = time();

        if ($now - $this->lastResyncAt < self::SLOT_RESYNC_INTERVAL_SECONDS) {
            return;
        }

        $this->lastResyncAt = $now;
        $this->syncProviderSlots();
    }

    /**
     * Hard-resets each provider's active slot counter from DB.
     * Called after bulk timeouts to correct any counter drift.
     */
    private function syncProviderSlots(): void
    {
        $activeCounts = CaptchaRequest::where('status', CaptchaRequestStatus::Processing)
            ->whereNotNull('provider_id')
            ->groupBy('provider_id')
            ->selectRaw('provider_id, COUNT(*) as cnt')
            ->pluck('cnt', 'provider_id');

        foreach (CaptchaProvider::all() as $provider) {
            // In-house rows are reconciled here too. This used to be skipped because a
            // local synchronous solve went Pending -> Ready and was never Processing, so
            // resetting the counter zeroed it underneath live solves and their release
            // drove it to -13. A fleet solve IS Processing for the whole lease, so the row
            // count is now the truth and skipping it would instead let drift accumulate.
            Redis::set("captcha:provider:{$provider->id}:active", $activeCounts[$provider->id] ?? 0);
        }
    }

    private function isJsonApi(CaptchaProviderType $type): bool
    {
        return in_array($type, [
            CaptchaProviderType::CapMonster,
            CaptchaProviderType::TwoCaptcha,
            CaptchaProviderType::CapSolver,
        ], true);
    }

    private function jsonApiBase(CaptchaProviderType $type): string
    {
        return match ($type) {
            CaptchaProviderType::CapMonster => 'https://api.capmonster.cloud',
            CaptchaProviderType::TwoCaptcha => 'https://api.2captcha.com',
            CaptchaProviderType::CapSolver => 'https://api.capsolver.com',
            default => '',
        };
    }

    private function formApiBase(CaptchaProviderType $type): string
    {
        return match ($type) {
            CaptchaProviderType::SolveCaptcha => 'https://api.solvecaptcha.com',
            default => 'https://ocr.captchaai.com',
        };
    }
}
