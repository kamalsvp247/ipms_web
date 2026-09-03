<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\PreStagedSession;
use App\Support\JwtClaimExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSessionController extends Controller
{
    /**
     * POST /api/account-sessions
     *
     * Called by the Java bot after signin or OTP/slot update.
     * Always upserts by phone (one row per phone) — the latest session data is always current.
     *
     * Logic:
     * - If jwt_token is provided: full upsert replacing all fields for this phone.
     * - If only otp_code / slot_id is provided: partial update on the existing row.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'signed_in_server_time' => 'nullable|string|max:64',
            'jwt_token' => 'nullable|string',
            'otp_code' => 'nullable|string|max:16',
            'otp_verified_server_time' => 'nullable|string|max:64',
            'slot_id' => 'nullable|string|max:64',
            'token_type' => 'nullable|string|max:32',
            'expires_at' => 'nullable|integer',
            'user_id' => 'nullable|string|max:64',
            'request_id' => 'nullable|string|max:64',
            'roles' => 'nullable|array',
            'status_code' => 'nullable|integer',
            'message' => 'nullable|string',
            'success_flag' => 'nullable|boolean',
            'server_time' => 'nullable|string|max:64',
            'jwt_generated_at_ms' => 'nullable|integer',
            'jwt_expires_at_ms' => 'nullable|integer',
        ]);

        $account = Account::where('phone', $validated['phone'])->first();
        $phone = $validated['phone'];

        // Full upsert when a fresh JWT token is present — replaces the entire row for this phone.
        if ($validated['jwt_token'] ?? null) {
            // Prefer JWT iat/exp claims (authoritative — what IVAC uses to validate the token).
            // Fall back to bot-supplied wall-clock values if the JWT can't be decoded.
            $claims = JwtClaimExtractor::extract($validated['jwt_token']);

            $tz = config('app.timezone');

            $jwtGeneratedAt = $claims['iat']
                ?? (isset($validated['jwt_generated_at_ms'])
                    ? \Carbon\Carbon::createFromTimestampMs($validated['jwt_generated_at_ms'], $tz)
                    : null);

            $jwtExpiresAt = $claims['exp']
                ?? (isset($validated['jwt_expires_at_ms'])
                    ? \Carbon\Carbon::createFromTimestampMs($validated['jwt_expires_at_ms'], $tz)
                    : null);

            $session = AccountSession::updateOrCreate(
                ['phone' => $phone],
                [
                    'account_id' => $account?->id,
                    'signed_in_server_time' => $validated['signed_in_server_time'] ?? null,
                    'jwt_token' => $validated['jwt_token'],
                    'jwt_generated_at' => $jwtGeneratedAt,
                    'jwt_expires_at' => $jwtExpiresAt,
                    'is_otp_verified' => false,
                    'otp_code' => $validated['otp_code'] ?? null,
                    'otp_verified_server_time' => $validated['otp_verified_server_time'] ?? null,
                    'slot_id' => null,
                    'token_type' => $validated['token_type'] ?? null,
                    'expires_at' => $validated['expires_at'] ?? null,
                    'user_id' => $validated['user_id'] ?? null,
                    'request_id' => $validated['request_id'] ?? null,
                    'roles' => $validated['roles'] ?? null,
                    'status_code' => $validated['status_code'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'success_flag' => $validated['success_flag'] ?? null,
                    'server_time' => $validated['server_time'] ?? null,
                ],
            );

            return response()->json($session, $session->wasRecentlyCreated ? 201 : 200);
        }

        // Partial update when only otp_code or slot_id arrives.
        if (($validated['otp_code'] ?? null) || ($validated['slot_id'] ?? null)) {
            $session = AccountSession::where('phone', $phone)->first();

            if ($session) {
                $updateData = [];

                if ($validated['otp_code'] ?? null) {
                    $updateData['otp_code'] = $validated['otp_code'];
                    $updateData['otp_verified_server_time'] = $validated['otp_verified_server_time'] ?? null;
                    $updateData['is_otp_verified'] = true;
                }

                if ($validated['slot_id'] ?? null) {
                    $updateData['slot_id'] = $validated['slot_id'];
                }

                if ($validated['token_type'] ?? null) {
                    $updateData['token_type'] = $validated['token_type'];
                }

                if ($validated['expires_at'] ?? null) {
                    $updateData['expires_at'] = $validated['expires_at'];
                }

                if ($validated['user_id'] ?? null) {
                    $updateData['user_id'] = $validated['user_id'];
                }

                if ($validated['request_id'] ?? null) {
                    $updateData['request_id'] = $validated['request_id'];
                }

                if ($validated['roles'] ?? null) {
                    $updateData['roles'] = $validated['roles'];
                }

                if ($validated['status_code'] ?? null) {
                    $updateData['status_code'] = $validated['status_code'];
                }

                if ($validated['message'] ?? null) {
                    $updateData['message'] = $validated['message'];
                }

                if (isset($validated['success_flag']) && $validated['success_flag'] !== null) {
                    $updateData['success_flag'] = $validated['success_flag'];
                }

                if ($validated['server_time'] ?? null) {
                    $updateData['server_time'] = $validated['server_time'];
                }

                $session->update($updateData);

                return response()->json($session);
            }

            // No existing session — create a minimal row.
            $session = AccountSession::create([
                'account_id' => $account?->id,
                'phone' => $phone,
                'signed_in_server_time' => null,
                'jwt_token' => null,
                'otp_code' => $validated['otp_code'] ?? null,
                'otp_verified_server_time' => $validated['otp_verified_server_time'] ?? null,
                'slot_id' => $validated['slot_id'] ?? null,
                'token_type' => $validated['token_type'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'request_id' => $validated['request_id'] ?? null,
                'roles' => $validated['roles'] ?? null,
                'status_code' => $validated['status_code'] ?? null,
                'message' => $validated['message'] ?? null,
                'success_flag' => $validated['success_flag'] ?? null,
                'server_time' => $validated['server_time'] ?? null,
            ]);

            return response()->json($session, 201);
        }

        // No JWT or OTP provided
        return response()->json(['error' => 'jwt_token or otp_code required'], 400);
    }

    /**
     * GET /api/account-sessions
     *
     * Returns latest sessions (one per phone), scoped to the authenticated user's accounts.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = AccountSession::query()->latest();

        if (! $user->isSuperAdmin()) {
            $query->whereIn('phone', $user->visibleAccountPhones());
        }

        if ($request->query('phone')) {
            $query->where('phone', 'like', '%'.$request->query('phone').'%');
        }

        $sessions = $query->paginate($perPage);

        return response()->json($sessions);
    }

    /**
     * GET /api/account-sessions/request-id?phone={phone}
     *
     * Returns the saved request_id for pre-staged OTP flow.
     * Reads from pre_staged_sessions — NOT from account_sessions.
     * Unauthenticated — called by the Java bot before the pre-stage OTP phase.
     */
    public function latestRequestId(Request $request): JsonResponse
    {
        $phone = $request->query('phone');

        if (! $phone) {
            return response()->json(['error' => 'phone required'], 400);
        }

        $session = PreStagedSession::where('phone', $phone)->first();

        return response()->json([
            'request_id' => $session?->request_id,
        ]);
    }

    /**
     * DELETE /api/account-sessions/{account_session}
     * Delete a session record.
     */
    public function destroy(AccountSession $accountSession): JsonResponse
    {
        $accountSession->delete();

        return response()->json(['message' => 'Session deleted']);
    }
}
