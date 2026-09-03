<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Http\Controllers\Controller;
use App\Models\CaptchaNode;
use App\Models\CaptchaNodeDailyStat;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use App\Services\Captcha\CaptchaNodeFleet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Captcha solver fleet: node-facing pull API plus the admin console behind it.
 *
 * The node endpoints (heartbeat/lease/result/script) sit outside the session middleware and
 * authenticate on a Bearer node key, exactly like the bot's slot endpoints. Everything else
 * is gated on bot.manage by the route group.
 */
class CaptchaNodeController extends Controller
{
    /** Commands a node knows how to execute off its heartbeat. */
    private const COMMANDS = ['update', 'pause', 'resume', 'restart_browsers', 'reset_stats'];

    public function __construct(private readonly CaptchaNodeFleet $fleet) {}

    // -----------------------------------------------------------------------------
    // Node-facing (Bearer node key)
    // -----------------------------------------------------------------------------

    /**
     * Node check-in. Reports capacity and stats, receives its pending command.
     *
     * The command is consumed on read so a node acts on it exactly once, matching
     * AgentSlotController::heartbeat().
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $node = $this->resolveNode($request);

        if (! $node) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $node->markOnline($request->ip());

        $update = [];

        if (in_array($request->input('worker_state'), ['idle', 'solving', 'paused'], true)) {
            $update['worker_state'] = $request->input('worker_state');
        }

        foreach (['hostname', 'script_version', 'last_error'] as $field) {
            if ($request->filled($field)) {
                $update[$field] = substr((string) $request->input($field), 0, $field === 'script_version' ? 20 : 255);
            }
        }

        foreach (['cpu_cores', 'reported_concurrency', 'active', 'queued', 'solved', 'failed', 'avg_ms'] as $field) {
            if ($request->has($field)) {
                $update[$field] = max(0, (int) $request->input($field));
            }
        }

        if ($update) {
            $node->update($update);
        }

        $command = $node->pending_command;

        if ($command !== null) {
            $node->clearPendingCommand();
        }

        // Recomputed here rather than on dispatch so aggregate capacity tracks the fleet
        // without every solve paying for a query.
        $this->fleet->refreshCapacity();

        $setting = Setting::instance();

        return response()->json([
            'node_id' => $node->id,
            'name' => $node->name,
            'enabled' => $node->enabled,
            'pending_command' => $command,
            'desired_concurrency' => $node->concurrency,
            'script_version' => $this->scriptVersion(),
            'site_key' => $setting->captcha_site_key,
            'page_url' => $setting->captcha_page_url,
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * Claim queued work. The node asks for what it can actually run right now.
     */
    public function lease(Request $request): JsonResponse
    {
        $node = $this->resolveNode($request);

        if (! $node) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $node->markOnline($request->ip());

        $capacity = min(64, max(0, (int) $request->input('capacity', 0)));
        $work = $this->fleet->lease($node, $capacity);

        return response()->json(['work' => $work]);
    }

    /**
     * Report solved or failed work. Batched, and idempotent per request.
     */
    public function result(Request $request): JsonResponse
    {
        $node = $this->resolveNode($request);

        if (! $node) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $validated = $request->validate([
            'results' => ['required', 'array', 'max:64'],
            'results.*.request_id' => ['required', 'string'],
            'results.*.token' => ['nullable', 'string'],
            'results.*.error' => ['nullable', 'string'],
        ]);

        $accepted = 0;

        foreach ($validated['results'] as $result) {
            if ($this->fleet->complete($node, $result['request_id'], $result['token'] ?? null, $result['error'] ?? null)) {
                $accepted++;
            }
        }

        return response()->json(['accepted' => $accepted]);
    }

    /**
     * Serve the solver script so a node can install and self-update without SSH.
     *
     * Versioned by content hash rather than a hand-maintained constant, so a node can
     * always tell whether it is running what the portal ships.
     */
    public function script(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! $this->resolveNode($request)) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $path = $this->scriptPath();

        if (! is_file($path)) {
            return response()->json(['error' => 'Solver script not found on the portal.'], 404);
        }

        return response()->download($path, 'solver.cjs', [
            'Content-Type' => 'text/javascript',
            'X-Script-Version' => $this->scriptVersion(),
        ]);
    }

    // -----------------------------------------------------------------------------
    // Admin console (can:bot.manage)
    // -----------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $nodes = CaptchaNode::orderBy('id')->get();
        $scriptVersion = $this->scriptVersion();

        $nodes->each(function (CaptchaNode $node) {
            $node->markOfflineIfStale();
        });

        $inHouse = CaptchaProvider::where('type', CaptchaProviderType::InHouse)->first();

        return response()->json([
            'nodes' => $nodes->map(fn (CaptchaNode $node) => [
                ...$node->toArray(),
                'update_available' => $node->script_version !== null && $node->script_version !== $scriptVersion,
            ]),
            'script_version' => $scriptVersion,
            'capacity' => $this->fleet->refreshCapacity(),
            'queue_depth' => $this->fleet->queueDepth(),
            'queue_limit' => $this->fleet->queueLimit(),
            'provider' => $inHouse ? ['id' => $inHouse->id, 'enabled' => $inHouse->enabled] : null,
            'install_url' => rtrim(config('app.url'), '/').'/captcha-install.sh',
            'stats' => $this->solveStats(),
        ]);
    }

    /**
     * Solve totals for today, the last 7 days and all time.
     *
     * Read from captcha_node_daily_stats rather than captcha_requests, which is purged
     * within minutes, or the nodes' own counters, which reset whenever a node restarts.
     *
     * @return array{today: array<string, int>, week: array<string, int>, total: array<string, int>, per_node: list<array<string, mixed>>}
     */
    private function solveStats(): array
    {
        $today = CarbonImmutable::today();

        $sum = fn (?string $from): array => (function (object $row): array {
            $solved = (int) $row->solved;
            $failed = (int) $row->failed;
            $attempts = $solved + $failed;

            return [
                'solved' => $solved,
                'failed' => $failed,
                // Blank rather than 0% when nothing ran, so an idle window is not read as
                // a total failure.
                'success_rate' => $attempts > 0 ? (int) round($solved / $attempts * 100) : null,
                'avg_ms' => $solved > 0 ? (int) round((int) $row->total_ms / $solved) : null,
            ];
        })(
            CaptchaNodeDailyStat::query()
                ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
                ->selectRaw('COALESCE(SUM(solved),0) solved, COALESCE(SUM(failed),0) failed, COALESCE(SUM(total_ms),0) total_ms')
                ->first()
        );

        $perNode = CaptchaNodeDailyStat::query()
            ->where('date', '>=', $today->subDays(6)->toDateString())
            ->selectRaw('captcha_node_id, SUM(solved) solved, SUM(failed) failed')
            ->groupBy('captcha_node_id')
            ->orderByDesc('solved')
            ->get();

        $names = CaptchaNode::whereIn('id', $perNode->pluck('captcha_node_id')->filter())->pluck('name', 'id');

        return [
            'today' => $sum($today->toDateString()),
            'week' => $sum($today->subDays(6)->toDateString()),
            'total' => $sum(null),
            'per_node' => $perNode->map(fn ($row) => [
                'node_id' => $row->captcha_node_id,
                'name' => $names[$row->captcha_node_id] ?? 'removed node',
                'solved' => (int) $row->solved,
                'failed' => (int) $row->failed,
            ])->all(),
        ];
    }

    /**
     * Wipe every solve counter the console shows and start the fleet from zero.
     *
     * Three places hold a total, and clearing only some of them leaves the page showing a
     * mix of old and new: the durable daily history, the node-reported columns, and the
     * counters living in each node process. The last of those is why a reset_stats command
     * goes out too — a DB-only wipe is undone by the next heartbeat, which re-reports the
     * node's own lifetime numbers.
     */
    public function resetStats(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $days = CaptchaNodeDailyStat::query()->delete();

        CaptchaNode::query()->update([
            'solved' => 0,
            'failed' => 0,
            'avg_ms' => 0,
            'last_error' => null,
        ]);

        $nodes = CaptchaNode::where('status', 'online')->get();
        $nodes->each(fn (CaptchaNode $node) => $node->publishCommand('reset_stats'));

        return response()->json([
            'deleted_days' => $days,
            'notified_nodes' => $nodes->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:captcha_nodes,name'],
            'profile' => ['nullable', 'in:dedicated,shared'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:64'],
        ]);

        $node = CaptchaNode::create([
            'name' => $validated['name'],
            'api_key' => CaptchaNode::generateApiKey(),
            'profile' => $validated['profile'] ?? 'dedicated',
            'concurrency' => $validated['concurrency'] ?? null,
            'status' => 'offline',
        ]);

        return response()->json($node, 201);
    }

    /**
     * What the installer needs to size the box before the node has ever checked in.
     *
     * Without this the installer sizes CPUQuota, MemoryMax and the browser count from
     * nproc, and the operator's chosen concurrency only lands on the first heartbeat —
     * leaving the systemd unit permanently sized for a number the node no longer runs.
     */
    public function provisioning(Request $request): JsonResponse
    {
        $node = $this->resolveNode($request);

        if (! $node) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        return response()->json([
            'name' => $node->name,
            'profile' => $node->profile,
            'desired_concurrency' => $node->concurrency,
        ]);
    }

    public function update(Request $request, CaptchaNode $captchaNode): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'unique:captcha_nodes,name,'.$captchaNode->id],
            'enabled' => ['sometimes', 'boolean'],
            'profile' => ['sometimes', 'in:dedicated,shared'],
            'concurrency' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:64'],
        ]);

        $captchaNode->update($validated);

        // Concurrency is applied by the node on its next heartbeat; push a command too so
        // a node that just checked in does not wait a full cycle.
        if (array_key_exists('concurrency', $validated) && $validated['concurrency'] !== null) {
            $captchaNode->publishCommand('set_concurrency:'.$validated['concurrency']);
        }

        $this->fleet->refreshCapacity();

        return response()->json($captchaNode->fresh());
    }

    public function destroy(CaptchaNode $captchaNode): JsonResponse
    {
        $captchaNode->delete();
        $this->fleet->refreshCapacity();

        return response()->json(null, 204);
    }

    /**
     * Remove every node that has stopped checking in. Requests they were holding are
     * released by the lease reaper on its next pass.
     */
    public function destroyOffline(): JsonResponse
    {
        CaptchaNode::all()->each(fn (CaptchaNode $node) => $node->markOfflineIfStale());

        $deleted = CaptchaNode::where('status', 'offline')->delete();

        $this->fleet->refreshCapacity();

        return response()->json(['deleted' => $deleted]);
    }

    public function regenerateKey(CaptchaNode $captchaNode): JsonResponse
    {
        $captchaNode->update(['api_key' => CaptchaNode::generateApiKey()]);

        return response()->json($captchaNode->fresh());
    }

    public function sendCommand(Request $request, CaptchaNode $captchaNode): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'command' => ['required', 'string', 'in:'.implode(',', self::COMMANDS)],
        ]);

        $captchaNode->publishCommand($validated['command']);

        return response()->json(['status' => 'queued', 'command' => $validated['command']]);
    }

    public function sendCommandToAll(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $validated = $request->validate([
            'command' => ['required', 'string', 'in:'.implode(',', self::COMMANDS)],
        ]);

        $nodes = CaptchaNode::where('status', 'online')->get();
        $nodes->each(fn (CaptchaNode $node) => $node->publishCommand($validated['command']));

        return response()->json(['status' => 'queued', 'count' => $nodes->count()]);
    }

    /**
     * Solve one captcha on a specific node, through the real fleet path.
     *
     * Deliberately not a direct call to the node: this exercises exactly what production
     * uses — enqueue, lease, result — so a green test means the whole chain works, not just
     * that some Chrome somewhere can mint a token.
     */
    public function testSolve(CaptchaNode $captchaNode): JsonResponse
    {
        Gate::authorize('bot.manage');

        if (! $captchaNode->isOnline()) {
            return response()->json(['status' => 'error', 'message' => 'Node is offline.'], 422);
        }

        $setting = Setting::instance();

        if (! $setting->captcha_site_key || ! $setting->captcha_page_url) {
            return response()->json([
                'status' => 'error',
                'message' => 'Captcha site key and page URL must be set first.',
            ], 422);
        }

        $provider = CaptchaProvider::where('type', CaptchaProviderType::InHouse)->first();

        if (! $provider) {
            return response()->json([
                'status' => 'error',
                'message' => 'No in-house provider row exists. Add one on Captcha Providers.',
            ], 422);
        }

        $request = CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Pending,
            'source' => 'on_demand',
        ]);

        $this->fleet->enqueue($request, $provider->id, $captchaNode);

        return response()->json([
            'status' => 'queued',
            'request_id' => $request->request_id,
            'node' => $captchaNode->name,
        ], 202);
    }

    /**
     * Poll a test solve. Consumes the row so a test never leaks a token into the pool.
     */
    public function testSolveStatus(string $requestId): JsonResponse
    {
        Gate::authorize('bot.manage');

        $request = CaptchaRequest::where('request_id', $requestId)->first();

        if (! $request) {
            return response()->json(['status' => 'gone'], 404);
        }

        if ($request->status === CaptchaRequestStatus::Ready) {
            $token = $request->token;
            $node = $request->node?->name;
            $ms = $request->solved_at && $request->leased_at
                ? $request->leased_at->diffInMilliseconds($request->solved_at)
                : null;

            $request->delete();

            return response()->json(['status' => 'ready', 'token' => $token, 'node' => $node, 'ms' => $ms]);
        }

        if ($request->status === CaptchaRequestStatus::Failed) {
            $message = $request->error_message;
            $request->delete();

            return response()->json(['status' => 'failed', 'message' => $message]);
        }

        return response()->json(['status' => 'pending']);
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    private function resolveNode(Request $request): ?CaptchaNode
    {
        $apiKey = $request->bearerToken();

        if (! $apiKey) {
            return null;
        }

        return CaptchaNode::where('api_key', $apiKey)->first();
    }

    private function scriptPath(): string
    {
        return app_path('Scripts/in_house_captcha_solver.cjs');
    }

    /**
     * Content hash of the shipped solver. A node self-hashes its own copy and reports it,
     * so drift is visible without maintaining a version constant by hand.
     */
    private function scriptVersion(): string
    {
        $path = $this->scriptPath();

        return is_file($path) ? substr(hash_file('sha256', $path), 0, 12) : 'unknown';
    }
}
