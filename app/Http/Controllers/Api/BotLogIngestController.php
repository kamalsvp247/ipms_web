<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountSession;
use App\Models\AgentSlot;
use App\Models\BotLog;
use App\Services\AccountLiveStateRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotLogIngestController extends Controller
{
    public function __construct(private AccountLiveStateRecorder $liveStates) {}

    /**
     * Bulk-ingest bot log entries from a worker.
     * Authenticates via Bearer token (slot api_key).
     */
    public function store(Request $request): JsonResponse
    {
        $apiKey = $request->bearerToken();
        $slot = AgentSlot::where('api_key', $apiKey)->first();

        if (! $slot) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $data = $request->validate([
            'logs' => 'required|array|max:250',
            'logs.*.account_phone' => 'nullable|string|max:30',
            'logs.*.label' => 'nullable|string|max:50',
            'logs.*.method' => 'required|string|max:10',
            'logs.*.url' => 'required|string',
            'logs.*.status_code' => 'nullable|integer',
            'logs.*.duration_ms' => 'nullable|integer',
            'logs.*.protocol_version' => 'nullable|string|max:50',
            'logs.*.remote_ip' => 'nullable|string|max:45',
            'logs.*.request_body' => 'nullable|string',
            'logs.*.response_body' => 'nullable|string',
            'logs.*.error_type' => 'nullable|string|max:100',
            'logs.*.error_message' => 'nullable|string',
            'logs.*.logged_at' => 'nullable|date',
        ]);

        $base = [
            'agent_slot_id' => null,
            'account_phone' => null,
            'label' => null,
            'method' => null,
            'url' => null,
            'status_code' => null,
            'duration_ms' => null,
            'protocol_version' => null,
            'remote_ip' => null,
            'request_body' => null,
            'response_body' => null,
            'error_type' => null,
            'error_message' => null,
            'logged_at' => null,
        ];

        $rows = array_filter(
            array_map(fn ($entry) => array_merge($base, $entry, [
                'agent_slot_id' => $slot->id,
                'logged_at' => $entry['logged_at'] ?? now()->format('Y-m-d H:i:s.v'),
            ]), $data['logs']),
            function ($row) {
                $url = $row['url'] ?? '';
                if ($row['method'] === 'LOG' && str_contains($url, 'heartbeat')) {
                    return false;
                }
                // OTP polling produces high-volume noise — POLLING console logs already cover it.
                if ($row['method'] !== 'LOG' && str_contains($url, '/api/otp/')) {
                    return false;
                }
                return true;
            },
        );

        if (! empty($rows)) {
            BotLog::insert(array_values($rows));
        }

        // Deferred past the response so the bot's log shipper never waits on it. The upsert is a
        // durable write and this MySQL runs innodb_flush_log_at_trx_commit=1 with sync_binlog=1, so
        // it costs ~29ms of commit fsync no matter how few rows it touches — measured against a
        // full 250-entry batch, that is most of the ingest again. Nothing downstream reads the
        // result within the request, and the accounts page polls on its own clock, so paying it
        // after the bot has its 200 back is free.
        //
        // Fed the already-filtered rows, not the raw batch, so the live state inherits the OTP-poll
        // noise filter above instead of parking "OTP poll" over the phase the operator wants to see.
        $liveStateRows = array_values($rows);
        $recorder = $this->liveStates;
        dispatch(function () use ($recorder, $liveStateRows, $slot) {
            $recorder->record($liveStateRows, $slot->id);
        })->afterResponse();

        $this->captureReservedVisaInfo($data['logs']);

        return response()->json(['inserted' => count($rows)]);
    }

    /**
     * Scan the ingested batch for slot-reserve responses and persist the reserved visa type
     * + applicant count onto the account session. IVAC returns this on every /reserve-slot
     * response (countByType, e.g. {"MEDICAL": 4}) — the key is the visa type and the value is
     * the number of applicants — regardless of whether the slot was actually reserved. A FULL
     * slot returns 200 with countByType populated but reservationId null, so we key off
     * countByType alone (not reservationId), otherwise the common FULL case never captures the
     * visa type. The payment-links page reads these (joined by phone) since the per-account
     * session JSON is often unpopulated.
     *
     * @param  array<int, array<string, mixed>>  $logs
     */
    private function captureReservedVisaInfo(array $logs): void
    {
        $latestByPhone = [];

        foreach ($logs as $entry) {
            if (($entry['method'] ?? '') === 'LOG') {
                continue;
            }
            if (! str_contains($entry['url'] ?? '', 'reserve-slot')) {
                continue;
            }
            if ((int) ($entry['status_code'] ?? 0) !== 200) {
                continue;
            }

            $phone = $entry['account_phone'] ?? null;
            $body = $entry['response_body'] ?? null;
            if (! $phone || ! $body) {
                continue;
            }

            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                continue;
            }

            $countByType = $decoded['countByType'] ?? null;
            if (! is_array($countByType) || empty($countByType)) {
                continue;
            }

            $visaType = (string) array_key_first($countByType);
            $applicants = (int) $countByType[$visaType];
            $loggedAt = (string) ($entry['logged_at'] ?? '');

            // Keep only the most recent reservation per phone within this batch.
            if (! isset($latestByPhone[$phone]) || $loggedAt >= $latestByPhone[$phone]['logged_at']) {
                $latestByPhone[$phone] = [
                    'reserved_visa_type' => $visaType,
                    'reserved_applicants' => $applicants,
                    'logged_at' => $loggedAt,
                ];
            }
        }

        foreach ($latestByPhone as $phone => $info) {
            AccountSession::where('phone', $phone)->update([
                'reserved_visa_type' => $info['reserved_visa_type'],
                'reserved_applicants' => $info['reserved_applicants'],
            ]);
        }
    }
}
