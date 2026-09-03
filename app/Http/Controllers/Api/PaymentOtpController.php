<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Support\PaymentOtpTicket;
use App\Support\PaymentWalletLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OTP feed for the headless auto-payment driver.
 *
 * Authenticated by a per-run ticket (see PaymentOtpTicket) rather than session or slot auth: the
 * driver is a short-lived Node process, and the ticket binds it to exactly one wallet number for
 * exactly one payment.
 */
class PaymentOtpController extends Controller
{
    public function show(Request $request, string $phone): JsonResponse
    {
        $token = $request->bearerToken() ?? (string) $request->query('token', '');
        if ($token === '') {
            return response()->json(['message' => 'Missing ticket.'], 401);
        }

        $wallet = PaymentOtpTicket::walletFor($token);
        if ($wallet === null) {
            return response()->json(['message' => 'Invalid or expired ticket.'], 401);
        }

        // The ticket names the wallet it may read. Anything else is refused even with a live token.
        if (! hash_equals($wallet, $phone)) {
            return response()->json(['message' => 'Ticket is not valid for that wallet.'], 403);
        }

        $sinceMs = $request->query('since') !== null ? (int) $request->query('since') : null;

        // The account stores the 12-digit Rocket number, but the forwarder posts the number of the
        // SIM that received the message — the 11-digit mobile the check digit is appended to. A
        // lookup on the stored value alone therefore never matches a real provider SMS.
        $otp = OtpCode::consumeMfsForPhone(PaymentWalletLock::candidatePhones($wallet), $sinceMs);

        return response()->json([
            'phone' => $phone,
            'otp_code' => $otp?->otp_code,
            'fetched_at' => $otp?->fetched_at?->toDateTimeString(),
        ]);
    }
}
