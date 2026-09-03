<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentSlot;
use App\Models\OtpCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    /**
     * Consume the latest unconsumed OTP for a phone number.
     *
     * Query params (all optional, backwards compatible):
     *   channel = sms | email | any (default any)
     *   since   = epoch ms; only OTPs fetched at or after (since - tolerance) are returned
     *   consume = 1 | 0 (default 1)
     *
     * Authenticates via Bearer token (slot api_key).
     */
    public function show(Request $request, string $phone): JsonResponse
    {
        $apiKey = $request->bearerToken();
        $slot = AgentSlot::where('api_key', $apiKey)->first();

        if (! $slot) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $channelParam = $request->query('channel');
        $channel = in_array($channelParam, ['sms', 'email'], true) ? $channelParam : null;

        $sinceMs = $request->query('since');
        $sinceMs = is_numeric($sinceMs) ? (int) $sinceMs : null;

        $consume = $request->query('consume', '1') !== '0';

        $otp = OtpCode::consumeForPhone($phone, $channel, $sinceMs, 2000, $consume);

        if (! $otp) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'otp_code' => $otp->otp_code,
            'channel' => $otp->source_gmail_id ? 'email' : 'sms',
            'fetched_at' => $otp->fetched_at?->toIso8601String(),
            'fetched_at_ms' => $otp->fetched_at?->valueOf(),
        ]);
    }

}
