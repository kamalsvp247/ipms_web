<?php

namespace App\Jobs;

use App\Enums\CaptchaRequestStatus;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use App\Services\Captcha\CaptchaNodeFleet;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Services\CaptchaSolverService;
use App\Support\CaptchaGenerationMode;
use App\Support\CaptchaVendorRotation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SolveCaptchaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    /** Provider ID claimed by acquireSlot() — used to release the slot in failed(). */
    private ?int $acquiredProviderId = null;

    public function __construct(
        private readonly int $captchaRequestId,
        private readonly ?int $providerId = null,
        private readonly string $queueName = 'captcha',
    ) {
        $this->onConnection('redis')->onQueue($queueName);
    }

    /**
     * Claim a provider slot and submit the task.
     * Re-queues itself with a 1s delay if all provider slots are full,
     * so the request stays pending and generation never stops.
     */
    public function handle(CaptchaSolverService $solver, CaptchaNodeFleet $fleet, CaptchaRaceCoordinator $races): void
    {
        $captchaRequest = CaptchaRequest::find($this->captchaRequestId);

        if (! $captchaRequest || $captchaRequest->status !== CaptchaRequestStatus::Pending) {
            return;
        }

        $provider = $this->acquireSlot($fleet);

        if ($provider === null) {
            // Re-queueing is only correct when a provider could still free up a slot.
            // With no eligible provider it would spin once a second forever, so those
            // cases have to fail the request instead.
            $unavailable = $this->eligibleProviderUnavailableReason($fleet);

            if ($unavailable !== null) {
                $races->settleFailed($captchaRequest, $unavailable);

                return;
            }

            // One racer among several. Waiting a second for a slot to open would deliver a
            // token after its siblings have already answered, and the request is covered by
            // them meanwhile — so drop this lane instead of holding a worker for it.
            if ($captchaRequest->race_parent_id !== null) {
                $races->settleFailed($captchaRequest, 'Every slot on the assigned provider was busy.');

                return;
            }

            // All slots occupied — re-queue and try again in 1s
            self::dispatch($this->captchaRequestId, $this->providerId, $this->queueName)
                ->delay(now()->addSecond());

            return;
        }

        if ($provider->type->isInHouse()) {
            // Hand off to the solver fleet and return. This job used to sit inside a ~5s
            // headless-Chrome solve, which is the only reason sixteen queue workers exist;
            // the work now happens on whichever node leases it.
            $fleet->enqueue($captchaRequest, $provider->id);

            return;
        }

        $setting = Setting::instance();

        try {
            $taskId = $solver->createTask(
                $provider,
                $setting->captcha_site_key,
                $setting->captcha_page_url,
                'turnstile',
            );

            $captchaRequest->update([
                'status' => CaptchaRequestStatus::Processing,
                'provider_id' => $provider->id,
                'vendor_task_id' => (string) $taskId,
            ]);
        } catch (Throwable $e) {
            Redis::decr("captcha:provider:{$provider->id}:active");
            $this->acquiredProviderId = null;

            $captchaRequest->update(['provider_id' => $provider->id]);
            $races->settleFailed($captchaRequest, $e->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $request = CaptchaRequest::find($this->captchaRequestId);

        if (! $request) {
            return;
        }

        // Release the slot regardless of whether DB was updated —
        // acquiredProviderId covers the window between acquireSlot() and the DB update,
        // and provider_id on the request covers crashes after the DB update.
        $providerIdToRelease = $this->acquiredProviderId ?? $request->provider_id;

        if ($providerIdToRelease) {
            Redis::decr("captcha:provider:{$providerIdToRelease}:active");
        }

        if ($request->status !== CaptchaRequestStatus::Failed) {
            // Resolved rather than injected: failed() is invoked by the queue with only the
            // exception, and a racing attempt that dies here still has to release the bot
            // once it turns out to have been the last lane running.
            app(CaptchaRaceCoordinator::class)->settleFailed($request, $exception->getMessage());
        }
    }

    /**
     * Why no provider could be claimed, when the cause is permanent rather than
     * a temporarily full slot pool. Null means retrying is worthwhile.
     */
    private function eligibleProviderUnavailableReason(CaptchaNodeFleet $fleet): ?string
    {
        if (! CaptchaGenerationMode::scope(CaptchaProvider::query())->exists()) {
            return CaptchaProvider::exists()
                ? 'No captcha providers are enabled.'
                : 'No captcha providers configured.';
        }

        $pinned = $this->providerId === null
            ? null
            : CaptchaGenerationMode::scope(CaptchaProvider::where('id', $this->providerId))->first();

        if ($this->providerId === null) {
            return null;
        }

        if ($pinned === null) {
            return CaptchaProvider::whereKey($this->providerId)->exists()
                ? "Captcha provider #{$this->providerId} was disabled before this request was solved."
                : "Captcha provider #{$this->providerId} no longer exists.";
        }

        // Pinned to the fleet with nothing to run on. Re-queueing would spin once a second
        // forever waiting for a node that is not coming back, so fail and let the pool
        // filler route the replacement to a vendor.
        if ($pinned->type->isInHouse() && $fleet->capacity() === 0) {
            return 'No captcha solver nodes are online.';
        }

        return null;
    }

    /**
     * Atomically claims one slot on any available provider using Redis INCR.
     *
     * The in-house fleet is tried first and vendors are the overflow: fleet capacity is
     * already paid for in hardware, so a paid provider should only ever absorb what the
     * nodes could not. Vendors are walked from a shared round-robin cursor rather than
     * shuffled, so a burst of on-demand requests spreads as evenly as the pool filler's
     * — a shuffle is only even on average and clumps over a short window.
     *
     * Returns null when everything is at capacity (caller should re-queue).
     */
    private function acquireSlot(CaptchaNodeFleet $fleet): ?CaptchaProvider
    {
        // The mode applies to a pinned provider too: in active-only mode a row disabled
        // between dispatch and execution must stop solving immediately, otherwise the
        // toggle would only take effect for jobs queued afterwards.
        $providers = $this->providerId
            ? CaptchaGenerationMode::scope(CaptchaProvider::where('id', $this->providerId))->get()
            : CaptchaGenerationMode::scope(CaptchaProvider::query())->orderBy('id')->get();

        $fleetCapacity = $fleet->capacity();

        [$inHouse, $vendors] = $providers->partition(fn (CaptchaProvider $p) => $p->type->isInHouse());

        // With no node online the fleet cannot solve anything. Skipping it here — rather
        // than claiming and failing — is what lets an unpinned request fall through to a
        // vendor instead of stalling.
        if ($fleetCapacity === 0) {
            $inHouse = $inHouse->take(0);
        }

        // A pinned job carries exactly one provider and must not spend a rotation turn —
        // the filler already spent one when it chose that provider.
        $ordered = $this->providerId === null
            ? array_merge($inHouse->all(), CaptchaVendorRotation::order($vendors))
            : $inHouse->concat($vendors)->all();

        foreach ($ordered as $provider) {
            $active = Redis::incr("captcha:provider:{$provider->id}:active");

            if ($active <= $this->slotLimitFor($provider, $fleet)) {
                $this->acquiredProviderId = $provider->id;

                return $provider;
            }

            Redis::decr("captcha:provider:{$provider->id}:active");
        }

        return null;
    }

    /**
     * How many solves may be in flight against one provider.
     *
     * A vendor's ceiling is its own solver_threads, which models what that specific paid
     * API key is allowed to run concurrently — one key may be provisioned for far more
     * parallelism than another, so a single global budget mis-sized every one of them.
     * The in-house fleet's ceiling is the aggregate concurrency its nodes report, plus a
     * small queueing factor — see CaptchaNodeFleet::queueLimit(). It replaces the old
     * fixed captcha:in_house_slots, which described one local sidecar and cannot describe
     * a fleet that grows and shrinks.
     */
    private function slotLimitFor(CaptchaProvider $provider, CaptchaNodeFleet $fleet): int
    {
        if ($provider->type->isInHouse()) {
            return $fleet->queueLimit();
        }

        return max(1, (int) $provider->solver_threads);
    }
}
