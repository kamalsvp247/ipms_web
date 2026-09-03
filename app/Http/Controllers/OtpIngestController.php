<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Support\MfsOtpParser;
use App\Support\OtpMessageParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OtpIngestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'msg' => ['required', 'string'],
        ]);

        $phone = trim($validated['phone']);
        $message = $validated['msg'];

        $isIvacbd = OtpMessageParser::isIvacbd($message);
        $otpCode = $isIvacbd ? OtpMessageParser::extractOtp($message) : null;

        // IVAC is classified first and wins outright: a booking OTP must never be reclassified as
        // a payment code, or the bot's OTP poll would come up empty.
        $isMfs = ! $isIvacbd && MfsOtpParser::isMfs($message);
        if ($isMfs) {
            $otpCode = MfsOtpParser::extractOtp($message);
        }

        $record = OtpCode::create([
            'phone' => $phone,
            'otp_code' => $otpCode,
            'message' => $message,
            'is_ivacbd' => $isIvacbd,
            'is_mfs' => $isMfs,
            'fetched_at' => Carbon::now('Asia/Dhaka'),
        ]);

        return response()->json([
            'id' => $record->id,
            'phone' => $record->phone,
            'otp_code' => $record->otp_code,
            'is_ivacbd' => $record->is_ivacbd,
            'is_mfs' => $record->is_mfs,
            'fetched_at' => $record->fetched_at->toDateTimeString(),
        ]);
    }
}
