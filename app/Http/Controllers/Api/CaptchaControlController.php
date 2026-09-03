<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Support\CaptchaGenerationMode;
use App\Support\CaptchaPoolExpiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

class CaptchaControlController extends Controller
{
    private const SERVICE = 'ipms-captcha-pool';

    public function status(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $active = $this->serviceIsActive();

        $poolCounts = CaptchaRequest::where('source', 'pool')
            ->whereIn('status', ['pending', 'processing', 'ready', 'claimed'])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $providerIds = CaptchaProvider::pluck('id');
        $providerCounts = [];

        foreach ($providerIds as $id) {
            try {
                $val = Redis::get("captcha:provider:{$id}:count");
                if ($val !== null) {
                    $providerCounts[(string) $id] = (int) $val;
                }
            } catch (\Throwable $e) {
                // Redis unavailable — skip provider counts gracefully
            }
        }

        try {
            $poolLimit = (int) (Redis::get('captcha:pool_limit') ?? 100);
            $slotsPerProvider = (int) (Redis::get('captcha:slots_per_provider') ?? 5);
        } catch (\Throwable $e) {
            $poolLimit = 100;
            $slotsPerProvider = 5;
        }

        return response()->json([
            'running' => $active,
            // Which providers generation may draw from, and how many each mode offers —
            // the UI gates its two Start buttons on these.
            'generation_mode' => CaptchaGenerationMode::current(),
            'active_providers' => CaptchaProvider::where('enabled', true)->count(),
            'total_providers' => $providerIds->count(),
            'pool_limit' => $poolLimit,
            'pool_expiry_seconds' => CaptchaPoolExpiry::seconds(),
            'slots_per_provider' => $slotsPerProvider,
            'pool_ready' => (int) ($poolCounts['ready'] ?? 0),
            'pool_pending' => (int) ($poolCounts['pending'] ?? 0),
            'pool_processing' => (int) ($poolCounts['processing'] ?? 0),
            'pool_claimed' => (int) ($poolCounts['claimed'] ?? 0),
            'provider_counts' => $providerCounts,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(CaptchaGenerationMode::options())],
        ]);

        if ($this->serviceIsActive()) {
            return response()->json(['error' => 'already_running'], 409);
        }

        // Set before starting: the filler reads the mode on its first pass.
        $mode = $validated['mode'] ?? CaptchaGenerationMode::ACTIVE;
        CaptchaGenerationMode::set($mode);

        // Starting with nothing to draw from would burn a filler loop producing
        // nothing. Say so instead of appearing to work.
        if (! CaptchaGenerationMode::scope(CaptchaProvider::query())->exists()) {
            return response()->json([
                'error' => CaptchaProvider::exists() ? 'no_active_providers' : 'no_providers',
                'detail' => CaptchaProvider::exists()
                    ? 'Every captcha provider is disabled. Enable one on Captcha Providers, or start with All providers.'
                    : 'No captcha providers exist yet. Add one on Captcha Providers first.',
            ], 409);
        }

        // Reset session counters (including active slots in case they leaked)
        Redis::set('captcha:rr_index', 0);
        $providerIds = CaptchaProvider::pluck('id');
        foreach ($providerIds as $id) {
            Redis::del("captcha:provider:{$id}:count");
            Redis::set("captcha:provider:{$id}:active", 0);
        }

        exec('sudo systemctl start '.escapeshellarg(self::SERVICE).' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return response()->json(['error' => 'failed_to_start', 'detail' => implode("\n", $output)], 500);
        }

        return response()->json([
            'started' => true,
            'mode' => $mode,
            'providers' => CaptchaGenerationMode::scope(CaptchaProvider::query())
                ->orderBy('id')
                ->pluck('name'),
        ]);
    }

    public function stop(): JsonResponse
    {
        Gate::authorize('bot.manage');

        if (! $this->serviceIsActive()) {
            return response()->json(['error' => 'not_running'], 409);
        }

        exec('sudo systemctl stop '.escapeshellarg(self::SERVICE).' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return response()->json(['error' => 'failed_to_stop', 'detail' => implode("\n", $output)], 500);
        }

        // Cancel queued-but-not-yet-processing jobs so worker threads no-op on them.
        // Processing entries are mid-flight at the provider and cannot be cancelled here.
        CaptchaRequest::where('source', 'pool')
            ->where('status', CaptchaRequestStatus::Pending)
            ->delete();

        return response()->json(['stopped' => true]);
    }

    public function poolLimit(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'limit' => 'required|integer|min:1|max:500',
        ]);

        Redis::set('captcha:pool_limit', $validated['limit']);

        return response()->json(['pool_limit' => $validated['limit']]);
    }

    public function slotsPerProvider(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'slots' => 'required|integer|min:1|max:20',
        ]);

        Redis::set('captcha:slots_per_provider', $validated['slots']);

        return response()->json(['slots_per_provider' => $validated['slots']]);
    }

    public function poolExpiry(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'seconds' => 'required|integer|min:'.CaptchaPoolExpiry::MIN_SECONDS.'|max:'.CaptchaPoolExpiry::MAX_SECONDS,
        ]);

        CaptchaPoolExpiry::set($validated['seconds']);

        return response()->json(['pool_expiry_seconds' => CaptchaPoolExpiry::seconds()]);
    }

    public function deletePoolTokens(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $deleted = CaptchaRequest::whereIn('id', $validated['ids'])
            ->where('source', 'pool')
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Deletes ready pool tokens older than the configured pool expiry — the same
     * cutoff the scheduled purge and FillCaptchaPoolCommand already apply, exposed
     * here so an operator can trigger it on demand instead of waiting.
     */
    public function deleteExpiredPoolTokens(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $deleted = CaptchaRequest::where('source', 'pool')
            ->where('status', CaptchaRequestStatus::Ready)
            ->where('solved_at', '<', CaptchaPoolExpiry::cutoff())
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function poolTokens(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $tokens = CaptchaRequest::where('source', 'pool')
            ->where('status', CaptchaRequestStatus::Ready)
            ->with('provider:id,name,type')
            ->select(['id', 'provider_id', 'token', 'solved_at'])
            ->latest('solved_at')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'token' => $r->token,
                'provider_name' => $r->provider?->name ?? '—',
                'provider_type' => $r->provider?->type?->value ?? '—',
                'solved_at' => $r->solved_at?->toIso8601String(),
            ]);

        return response()->json($tokens);
    }

    private function serviceIsActive(): bool
    {
        exec('sudo systemctl is-active '.escapeshellarg(self::SERVICE).' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }
}
