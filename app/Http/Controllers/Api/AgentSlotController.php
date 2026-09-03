<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AgentSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AgentSlotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $visibleIds = $request->user()?->visibleUserIds();
        $accountsCount = $this->visibleAccountsCount($visibleIds);

        $slots = AgentSlot::withCount($accountsCount)->with('bypassIp')->orderBy('name')->get();

        // Mark stale slots offline before returning
        foreach ($slots as $slot) {
            $slot->markOfflineIfStale();
        }

        $freshSlots = $slots->fresh()->loadCount($accountsCount);

        // Flag slots that have at least one assigned account with a payment link
        $slotIdsWithPaymentLinks = DB::table('payment_links')
            ->join('accounts', 'accounts.phone', '=', 'payment_links.account_phone')
            ->whereNotNull('accounts.agent_slot_id')
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('accounts.user_id', $visibleIds))
            ->whereDate('payment_links.created_at', now()->toDateString())
            ->distinct()
            ->pluck('accounts.agent_slot_id')
            ->flip()
            ->all();

        foreach ($freshSlots as $slot) {
            $slot->has_payment_link = isset($slotIdsWithPaymentLinks[$slot->id]);
        }

        return response()->json($freshSlots);
    }

    /**
     * withCount argument that limits a slot's accounts_count to the accounts the caller may see.
     * Admins get the unfiltered count (visibleUserIds() returns null); a manager or agent would
     * otherwise read another subtree's accounts into their own slot badge on /bot-control.
     *
     * @param  list<int>|null  $visibleIds
     * @return array<int|string, mixed>
     */
    private function visibleAccountsCount(?array $visibleIds): array
    {
        if ($visibleIds === null) {
            return ['accounts'];
        }

        return ['accounts' => fn ($query) => $query->whereIn('user_id', $visibleIds)];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:agent_slots,name',
        ]);

        $slot = AgentSlot::create([
            'name' => $data['name'],
            'api_key' => AgentSlot::generateApiKey(),
            'status' => 'offline',
        ]);

        return response()->json($slot->loadCount('accounts'), 201);
    }

    public function show(AgentSlot $agentSlot): JsonResponse
    {
        $agentSlot->markOfflineIfStale();

        return response()->json($agentSlot->fresh()->loadCount('accounts'));
    }

    public function update(Request $request, AgentSlot $agentSlot): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100|unique:agent_slots,name,'.$agentSlot->id,
        ]);

        $agentSlot->update($data);

        return response()->json($agentSlot->loadCount('accounts'));
    }

    public function destroy(AgentSlot $agentSlot): JsonResponse
    {
        $agentSlot->delete();

        return response()->json(null, 204);
    }

    /**
     * Delete every offline worker in one shot. Assigned accounts are
     * unassigned automatically via the agent_slot_id nullOnDelete FK.
     */
    public function destroyOffline(): JsonResponse
    {
        $slots = AgentSlot::all();
        foreach ($slots as $slot) {
            $slot->markOfflineIfStale();
        }

        $offlineSlots = AgentSlot::where('status', 'offline')->get();
        $accountsUnassigned = Account::whereIn('agent_slot_id', $offlineSlots->pluck('id'))->count();

        foreach ($offlineSlots as $slot) {
            $slot->delete();
        }

        return response()->json([
            'deleted' => $offlineSlots->count(),
            'accounts_unassigned' => $accountsUnassigned,
        ]);
    }

    public function regenerateKey(AgentSlot $agentSlot): JsonResponse
    {
        $agentSlot->update(['api_key' => AgentSlot::generateApiKey()]);

        return response()->json($agentSlot->loadCount('accounts'));
    }

    /**
     * Heartbeat endpoint — called by the bot every 30s.
     * Authenticates via Bearer token (slot api_key).
     * Accepts optional worker_state; returns pending_command.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $apiKey = $request->bearerToken();
        $slot = AgentSlot::where('api_key', $apiKey)->first();

        if (! $slot) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $slot->markOnline($request->ip());

        $update = [];
        if (in_array($request->input('worker_state'), ['idle', 'running', 'stopping'])) {
            $update['worker_state'] = $request->input('worker_state');
        }
        if ($request->filled('bot_version')) {
            $update['bot_version'] = substr($request->input('bot_version'), 0, 50);
        }
        if ($update) {
            $slot->update($update);
        }

        // Consume the command atomically — read then clear so the bot acts on it exactly once.
        $command = $slot->pending_command;
        if ($command !== null) {
            $slot->clearPendingCommand();
        }

        return response()->json([
            'slot_id' => $slot->id,
            'name' => $slot->name,
            'status' => $slot->status,
            'worker_state' => $slot->worker_state,
            'pending_command' => $command,
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * HTTP fallback command poll — Bearer slot auth.
     */
    public function getCommand(Request $request): JsonResponse
    {
        $apiKey = $request->bearerToken();
        $slot = AgentSlot::where('api_key', $apiKey)->first();

        if (! $slot) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        return response()->json(['pending_command' => $slot->pending_command]);
    }

    /**
     * Send a command to a single agent slot.
     */
    public function sendCommand(Request $request, AgentSlot $agentSlot): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'command' => 'required|in:start,stop,restart,reload_config,process_restart',
        ]);

        $agentSlot->publishCommand($data['command']);

        return response()->json(['issued' => true, 'command' => $data['command'], 'slot_id' => $agentSlot->id]);
    }

    /**
     * Send a command to all agent slots.
     */
    public function sendCommandToAll(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'command' => 'required|in:start,stop,restart,reload_config,process_restart',
        ]);

        $slots = AgentSlot::all();

        foreach ($slots as $slot) {
            $slot->publishCommand($data['command']);
        }

        return response()->json(['issued' => true, 'command' => $data['command'], 'slots' => $slots->count()]);
    }
}
