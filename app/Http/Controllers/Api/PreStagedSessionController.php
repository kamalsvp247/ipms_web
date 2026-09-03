<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PreStagedSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreStagedSessionController extends Controller
{
    /**
     * POST /api/pre-staged-sessions/init
     *
     * Called by the Java bot after a successful login.
     * Inserts a new row only if no pre-staged session exists for this phone.
     * If a row already exists the request is a no-op — the existing requestId is preserved.
     * Unauthenticated — bot calls this without a bearer token.
     */
    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'request_id' => 'required|string|max:64',
        ]);

        $session = PreStagedSession::firstOrCreate(
            ['phone' => $validated['phone']],
            ['request_id' => $validated['request_id']],
        );

        $status = $session->wasRecentlyCreated ? 201 : 200;

        return response()->json($session, $status);
    }

    /**
     * PATCH /api/pre-staged-sessions/otp
     *
     * Called by the Java bot after captureOtp() succeeds.
     * Updates otp_code and otp_captured_at on the existing pre-staged row.
     * If no row exists (e.g. user deleted it mid-cycle) the request is ignored gracefully.
     * Unauthenticated.
     */
    public function updateOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'otp_code' => 'required|string|max:16',
        ]);

        $session = PreStagedSession::where('phone', $validated['phone'])->first();

        if (! $session) {
            return response()->json(['message' => 'No pre-staged session found for this phone'], 404);
        }

        $session->update([
            'otp_code' => $validated['otp_code'],
            'otp_captured_at' => now(),
        ]);

        return response()->json($session);
    }

    /**
     * PATCH /api/pre-staged-sessions/clear-otp
     *
     * Called by the Java bot after a successful booking.
     * Clears otp_code and otp_captured_at — the requestId is kept for the next day's pre-stage cycle.
     * Unauthenticated.
     */
    public function clearOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
        ]);

        $session = PreStagedSession::where('phone', $validated['phone'])->first();

        if (! $session) {
            return response()->json(['message' => 'No pre-staged session found for this phone'], 404);
        }

        $session->update([
            'otp_code' => null,
            'otp_captured_at' => null,
        ]);

        return response()->json($session);
    }

    /**
     * GET /api/pre-staged-sessions
     *
     * Returns all pre-staged sessions for the authenticated user's accounts.
     * Authenticated.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('settings.read');

        $user = $request->user();

        $query = PreStagedSession::query()->orderBy('phone');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('phone', $user->visibleAccountPhones());
        }

        return response()->json($query->get());
    }

    /**
     * DELETE /api/pre-staged-sessions/{pre_staged_session}
     *
     * Deletes a pre-staged session row.
     * The bot will recreate it on the next successful login.
     * Authenticated.
     */
    public function destroy(PreStagedSession $preStagedSession): JsonResponse
    {
        Gate::authorize('settings.write');

        $preStagedSession->delete();

        return response()->json(['message' => 'Pre-staged session deleted']);
    }
}
