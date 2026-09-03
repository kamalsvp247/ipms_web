<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountSession;
use App\Models\BotLog;
use App\Models\CaptchaRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DbBotLogsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortBy = in_array($request->input('sort_by'), ['logged_at', 'received_at', 'duration_ms'], true) ? $request->input('sort_by') : 'logged_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query = BotLog::query()->with('agentSlot:id,name');

        // The "Time" column displays the sent time (logged_at minus duration_ms),
        // not the raw logged_at (received) value, so sort on that same computed
        // value to keep the column's visual order correct. "Received" sorts on
        // the raw logged_at value shown in that column.
        if ($sortBy === 'logged_at') {
            $query->orderByRaw('logged_at - INTERVAL (COALESCE(duration_ms, 0) * 1000) MICROSECOND '.$sortDir);
        } elseif ($sortBy === 'received_at') {
            $query->orderBy('logged_at', $sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        // Tiebreaker on id keeps pagination stable when many rows share the same
        // logged_at / duration_ms value.
        $query->orderBy('id', $sortDir);

        $this->applyUserScope($query, $request);

        if ($request->filled('slot_id')) {
            $query->where('agent_slot_id', $request->integer('slot_id'));
        }

        $this->applyPhoneFilter($query, $request);

        if ($request->filled('status_code')) {
            $query->where('status_code', $request->integer('status_code'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }

        if ($request->filled('exclude_method')) {
            $query->where('method', '!=', $request->string('exclude_method'));
        }

        if ($request->filled('exclude_status_codes')) {
            $codes = array_filter(array_map('intval', explode(',', $request->string('exclude_status_codes'))));
            if (! empty($codes)) {
                $query->where(fn ($q) => $q->whereNotIn('status_code', $codes)->orWhereNull('status_code'));
            }
        }

        $this->applyEndpointsFilter($query, $request);

        if ($request->boolean('has_reservation_id')) {
            // Only constrain reserve-slot rows to those that obtained a slot ID;
            // all other endpoint rows are left untouched and remain visible.
            $query->where(function ($q) {
                $q->where('url', 'not like', '%reserve-slot%')
                    ->orWhere('response_body', 'like', '%"reservationId":"%');
            });
        }

        // Proxy-only: rows the Java bot routed through the Oxylabs edge-throttle
        // fallback are tagged "proxy:<host>" in remote_ip by IvacHttpClient.proxy().
        if ($request->boolean('proxy_only')) {
            $query->where('remote_ip', 'like', 'proxy:%');
        }

        if ($request->filled('from')) {
            $query->where('logged_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('logged_at', '<=', $request->input('to'));
        }

        if ($request->boolean('paginate')) {
            $perPage = max(1, min(500, $request->integer('per_page', 50)));
            $paginator = $query->paginate($perPage);
            $paginator->setCollection($this->attachJwtExpiresAt($paginator->getCollection()));

            return response()->json($paginator);
        }

        return response()->json(['data' => $this->attachJwtExpiresAt($query->get())]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BotLog>  $logs
     * @return \Illuminate\Support\Collection<int, BotLog>
     */
    private function attachJwtExpiresAt(\Illuminate\Support\Collection $logs): \Illuminate\Support\Collection
    {
        $phones = $logs->pluck('account_phone')->filter()->unique()->values();

        if ($phones->isEmpty()) {
            return $logs;
        }

        $jwtExpiresByPhone = AccountSession::query()
            ->whereIn('phone', $phones)
            ->get(['phone', 'jwt_expires_at'])
            ->mapWithKeys(fn (AccountSession $session) => [$session->phone => $session->jwt_expires_at?->toISOString()]);

        return $logs->each(function (BotLog $log) use ($jwtExpiresByPhone) {
            $log->setAttribute('jwt_expires_at', $log->account_phone ? $jwtExpiresByPhone->get($log->account_phone) : null);
            $this->stripMultipartBody($log);
        });
    }

    /**
     * Replaces a multipart file-upload request body (raw PDF bytes) with a compact summary.
     * Historical rows logged the full PDF, which bloats the API response and slows the
     * log-analysis page. Newer bot builds log only a summary, so this covers old rows.
     */
    private function stripMultipartBody(BotLog $log): void
    {
        $body = $log->request_body;
        if (! is_string($body) || $body === '') {
            return;
        }

        $isMultipart = str_contains($body, 'Content-Disposition: form-data')
            || str_starts_with(ltrim($body), '--')
            || str_contains($body, '%PDF');

        if ($isMultipart) {
            $log->setAttribute('request_body', '(multipart file upload, '.strlen($body).' bytes — body hidden)');
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $query = BotLog::query()->where('method', '!=', 'LOG')->where('url', 'not like', '%/otps/%');

        $this->applyUserScope($query, $request);

        if ($request->filled('slot_id')) {
            $query->where('agent_slot_id', $request->integer('slot_id'));
        }

        $this->applyEndpointsFilter($query, $request);

        $summary = $query->selectRaw('status_code, COUNT(*) as count, AVG(duration_ms) as avg_duration_ms')
            ->groupBy('status_code')
            ->orderBy('status_code')
            ->get();

        return response()->json($summary);
    }

    public function phones(Request $request): JsonResponse
    {
        $query = BotLog::query()
            ->whereNotNull('account_phone')
            ->where('method', '!=', 'LOG');

        $this->applyUserScope($query, $request);

        if ($request->filled('slot_id')) {
            $query->where('agent_slot_id', $request->integer('slot_id'));
        }

        $this->applyEndpointsFilter($query, $request);

        if ($request->filled('status_code')) {
            $query->where('status_code', $request->integer('status_code'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }

        $phones = $query->distinct()->orderBy('account_phone')->pluck('account_phone');

        return response()->json($phones);
    }

    public function endpoints(Request $request): JsonResponse
    {
        $query = BotLog::query()->where('method', '!=', 'LOG')->where('url', 'not like', '%/otps/%');

        $this->applyUserScope($query, $request);

        if ($request->filled('slot_id')) {
            $query->where('agent_slot_id', $request->integer('slot_id'));
        }

        $endpoints = $query->selectRaw('url, COUNT(*) as count')
            ->groupBy('url')
            ->orderByDesc('count')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'url' => $row->url,
                'path' => parse_url($row->url, PHP_URL_PATH) ?? $row->url,
                'count' => $row->count,
            ])
            ->unique('path')
            ->values();

        return response()->json($endpoints);
    }

    public function latestPerAccount(Request $request): JsonResponse
    {
        $sub = BotLog::query()
            ->where('method', '!=', 'LOG')
            ->whereNotNull('account_phone')
            ->whereNotNull('agent_slot_id')
            ->selectRaw('agent_slot_id, account_phone, MAX(id) as max_id')
            ->groupBy('agent_slot_id', 'account_phone');

        $this->applyUserScope($sub, $request);

        $rows = BotLog::query()
            ->joinSub($sub, 'latest', fn ($join) => $join->on('bot_logs.id', '=', 'latest.max_id'))
            ->select(['bot_logs.agent_slot_id', 'bot_logs.account_phone', 'bot_logs.url', 'bot_logs.label', 'bot_logs.status_code', 'bot_logs.logged_at'])
            ->get();

        return response()->json($rows);
    }

    public function purge(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $hours = max(1, $request->integer('older_than_hours', 24));
        $deleted = BotLog::where('logged_at', '<', now()->subHours($hours))->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function clear(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $request->validate(['slot_id' => 'required|integer|exists:agent_slots,id']);

        $deleted = BotLog::where('agent_slot_id', $request->integer('slot_id'))->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function destroy(int $id): JsonResponse
    {
        Gate::authorize('bot.manage');

        $log = BotLog::find($id);
        if (! $log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        $log->delete();

        return response()->json(['message' => 'Log deleted']);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $query = BotLog::query();

        if ($request->filled('slot_id')) {
            $query->where('agent_slot_id', $request->integer('slot_id'));
        }

        $this->applyPhoneFilter($query, $request);

        if ($request->filled('status_code')) {
            $query->where('status_code', $request->integer('status_code'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }

        if ($request->filled('exclude_method')) {
            $query->where('method', '!=', $request->string('exclude_method'));
        }

        $this->applyEndpointsFilter($query, $request);

        if ($request->boolean('has_reservation_id')) {
            // Only constrain reserve-slot rows to those that obtained a slot ID;
            // all other endpoint rows are left untouched and remain visible.
            $query->where(function ($q) {
                $q->where('url', 'not like', '%reserve-slot%')
                    ->orWhere('response_body', 'like', '%"reservationId":"%');
            });
        }

        if ($request->boolean('proxy_only')) {
            $query->where('remote_ip', 'like', 'proxy:%');
        }

        $deleted = $query->delete();

        return response()->json(['deleted' => $deleted, 'message' => "Deleted $deleted logs"]);
    }

    private function applyEndpointsFilter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $endpoints = array_filter((array) $request->input('endpoints', []));
        if (empty($endpoints)) {
            return;
        }
        $query->where(function ($q) use ($endpoints) {
            foreach ($endpoints as $ep) {
                $q->orWhere('url', 'like', '%'.trim($ep).'%');
            }
        });
    }

    /**
     * Non-admins only see logs for accounts they manage. Rows not tied to any
     * account (e.g. general console LOG entries) remain visible to everyone.
     */
    private function applyUserScope(Builder $query, Request $request): void
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        $phones = $user->visibleAccountPhones();

        $query->where(function (Builder $q) use ($phones) {
            $q->whereIn('account_phone', $phones)->orWhereNull('account_phone');
        });
    }

    /**
     * Filter by one or more phones. Accepts `phones[]` (exact match, multi-select)
     * and falls back to the legacy single `phone` param (substring match).
     */
    private function applyPhoneFilter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $phones = array_values(array_filter(array_map('trim', (array) $request->input('phones', []))));

        if (! empty($phones)) {
            $query->whereIn('account_phone', $phones);

            return;
        }

        if ($request->filled('phone')) {
            $query->where('account_phone', 'like', '%'.$request->string('phone').'%');
        }
    }

    public function purgeNoise(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $logsDeleted = BotLog::where(function ($q) {
            $q->whereIn('status_code', [504, 502, 503, 401])
                ->orWhere(function ($inner) {
                    $inner->whereNull('status_code')->whereNotNull('error_type');
                });
        })->delete();
        $captchasDeleted = CaptchaRequest::where('status', CaptchaRequestStatus::Failed)->delete();

        return response()->json([
            'logs_deleted' => $logsDeleted,
            'captchas_deleted' => $captchasDeleted,
        ]);
    }
}
