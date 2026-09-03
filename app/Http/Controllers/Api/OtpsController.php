<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Support\OtpMessageParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OtpsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_ivacbd' => ['nullable', 'in:0,1'],
            'channel' => ['nullable', 'in:sms,email'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort_by' => ['nullable', 'in:fetched_at,phone'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        $date = $validated['date'] ?? Carbon::now('Asia/Dhaka')->toDateString();
        $start = Carbon::parse($date, 'Asia/Dhaka')->startOfDay();
        $end = Carbon::parse($date, 'Asia/Dhaka')->endOfDay();

        $sortBy = $validated['sort_by'] ?? 'fetched_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $query = OtpCode::query()
            ->whereBetween('fetched_at', [$start, $end])
            ->orderBy($sortBy, $sortDir);
        if ($sortBy !== 'fetched_at') {
            $query->orderBy('fetched_at', 'desc');
        }

        // Agents see only accounts they added; managers add their agents' accounts too;
        // admins are unrestricted (visibleAccountPhones() returns null-scoped for them).
        if (! $request->user()->isSuperAdmin()) {
            $query->whereIn('phone', $request->user()->visibleAccountPhones());
        }

        if (! empty($validated['phone'])) {
            $query->where('phone', $validated['phone']);
        }
        if (isset($validated['is_ivacbd'])) {
            $query->where('is_ivacbd', (bool) $validated['is_ivacbd']);
        }
        if (! empty($validated['channel'])) {
            if ($validated['channel'] === 'email') {
                $query->whereNotNull('source_gmail_id');
            } else {
                $query->whereNull('source_gmail_id');
            }
        }

        $paginated = $query->with('gmailMessage:gmail_id,to_address')->paginate($validated['per_page'] ?? 50);

        $paginated->through(fn ($otp) => array_merge($otp->toArray(), [
            'to_address' => $otp->gmailMessage?->to_address,
        ]));

        return response()->json($paginated);
    }

    public function count(): JsonResponse
    {
        $start = Carbon::now('Asia/Dhaka')->startOfDay();
        $end = Carbon::now('Asia/Dhaka')->endOfDay();

        $rows = OtpCode::whereBetween('fetched_at', [$start, $end])
            ->where('is_ivacbd', true)
            ->whereRaw("otp_code REGEXP '^[0-9]{6}$'")
            ->selectRaw('phone, COUNT(*) as count')
            ->groupBy('phone')
            ->pluck('count', 'phone');

        return response()->json($rows);
    }

    public function destroyNonIvacbd(Request $request): JsonResponse
    {
        $query = OtpCode::where('is_ivacbd', false);

        if (! $request->user()->isSuperAdmin()) {
            $query->whereIn('phone', $request->user()->visibleAccountPhones());
        }

        $deleted = $query->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $query = OtpCode::whereIn('id', $validated['ids']);

        if (! $request->user()->isSuperAdmin()) {
            $query->whereIn('phone', $request->user()->visibleAccountPhones());
        }

        $deleted = $query->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'mode' => ['required', 'in:message,direct'],
            'message' => ['required_if:mode,message', 'nullable', 'string'],
            'otp_code' => ['required_if:mode,direct', 'nullable', 'string', 'regex:/^\d{4,8}$/'],
        ]);

        $phone = trim($validated['phone']);

        if ($validated['mode'] === 'message') {
            $message = $validated['message'];
            $isIvacbd = OtpMessageParser::isIvacbd($message);
            $otpCode = $isIvacbd ? OtpMessageParser::extractOtp($message) : null;
        } else {
            $message = null;
            $isIvacbd = true;
            $otpCode = $validated['otp_code'];
        }

        $record = OtpCode::create([
            'phone' => $phone,
            'otp_code' => $otpCode,
            'message' => $message,
            'is_ivacbd' => $isIvacbd,
            'fetched_at' => Carbon::now('Asia/Dhaka'),
        ]);

        return response()->json([
            'id' => $record->id,
            'phone' => $record->phone,
            'otp_code' => $record->otp_code,
            'is_ivacbd' => $record->is_ivacbd,
            'fetched_at' => $record->fetched_at->toDateTimeString(),
        ], 201);
    }
}
