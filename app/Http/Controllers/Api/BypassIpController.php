<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScanAwsIpv6RegionJob;
use App\Jobs\ScanAwsRegionJob;
use App\Jobs\ScanFrontendOriginJob;
use App\Models\BypassIp;
use App\Models\IpScanResult;
use App\Models\IpScanSession;
use App\Services\AwsIpRangesFetcher;
use App\Services\BypassIpScanner;
use App\Services\CensysOriginLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class BypassIpController extends Controller
{
    private const CLEANUP_SNAPSHOT_KEY = 'bypass_ips:last_cleanup_snapshot';

    public function count(): JsonResponse
    {
        return response()->json(['count' => BypassIp::count()]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('bot.manage');

        return response()->json($this->queryAll());
    }

    public function publicIndex(): JsonResponse
    {
        return response()->json($this->queryAll());
    }

    private function queryAll(): \Illuminate\Database\Eloquent\Collection
    {
        return BypassIp::query()
            ->withCount('slots')
            ->with('slots:id,name,bypass_ip_id')
            ->orderBy('label')
            ->get();
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'label' => 'required|string|max:100',
            'ip' => ['required', 'string', 'max:45', 'regex:/^(\d{1,3}\.){3}\d{1,3}$/'],
        ]);

        $bypassIp = BypassIp::create($data);

        return response()->json($bypassIp, 201);
    }

    public function update(Request $request, BypassIp $bypassIp): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'label' => 'sometimes|required|string|max:100',
            'ip' => ['sometimes', 'required', 'string', 'max:45', 'regex:/^(\d{1,3}\.){3}\d{1,3}$/'],
        ]);

        $bypassIp->update($data);

        return response()->json($bypassIp);
    }

    public function destroy(BypassIp $bypassIp): JsonResponse
    {
        Gate::authorize('bot.manage');

        $bypassIp->delete();

        return response()->json(['deleted' => true]);
    }

    public function ping(BypassIp $bypassIp): JsonResponse
    {
        Gate::authorize('bot.manage');

        $startTime = microtime(true);
        $connected = false;
        $statusCode = null;
        $message = null;
        $successFlag = null;
        $responseTimeMs = null;

        $url = "https://{$bypassIp->ip}/iams/api/v1/auth/sign-in-v4";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Host: api.ivacbd.com',
                'User-Agent: BLITZ-Portal/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CERTINFO => true,
        ]);

        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        $error = curl_error($ch);
        curl_close($ch);

        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);
        $certMatch = BypassIpScanner::certMatchesOrigin($certInfo);

        if ($response !== false && $httpCode >= 200) {
            $connected = true;
            $statusCode = $httpCode;

            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $statusCode = $decoded['statusCode'] ?? $httpCode;
                $message = $decoded['message'] ?? null;
                $successFlag = $decoded['successFlag'] ?? false;
            } else {
                // HTML body = ELB-level 503 (drained/unhealthy node) — never reached the IVAC app.
                $connected = false;
                $message = 'ELB node is drained — no healthy backend targets (HTML 503, not IVAC JSON). Node has the correct cert but cannot serve API traffic.';
            }

            if (! $certMatch) {
                $message = 'Not an ivacbd origin — TLS cert does not match *.ivacbd.com (likely an unrelated AWS ELB).';
            }
        } else {
            $message = "Connection failed: {$error}";
        }

        $bypassIp->update([
            'last_ping_ms' => $connected ? $responseTimeMs : null,
            'last_pinged_at' => now(),
            'response_status' => $statusCode,
            'response_message' => $message,
            'response_flag' => $successFlag,
            'response_time_ms' => $responseTimeMs,
        ]);

        return response()->json([
            'reachable' => $connected,
            'ping_ms' => $connected ? $responseTimeMs : null,
            'response_status' => $statusCode,
            'response_message' => $message,
            'response_flag' => $successFlag,
            'response_time_ms' => $responseTimeMs,
            'cert_match' => $certMatch,
            'last_pinged_at' => $bypassIp->last_pinged_at,
        ]);
    }

    public function batchCheck(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $text = $request->validate(['text' => 'required|string|max:100000'])['text'];

        preg_match_all('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $text, $matches);
        $ips = array_unique($matches[1]);

        $existingIps = BypassIp::pluck('ip')->toArray();

        $valid = [];

        foreach ($ips as $ip) {
            if (in_array($ip, $existingIps)) {
                continue;
            }

            $startTime = microtime(true);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://{$ip}/iams/api/v1/auth/sign-in-v4",
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => ['Host: api.ivacbd.com', 'User-Agent: BLITZ-Portal/1.0'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CERTINFO => true,
            ]);

            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            curl_close($ch);

            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response !== false && BypassIpScanner::certMatchesOrigin($certInfo)) {
                $decoded = json_decode($response, true);
                $firstOctet = explode('.', $ip)[0];
                $valid[] = [
                    'ip' => $ip,
                    'label' => "CF Bypass - {$firstOctet} series",
                    'response_status' => is_array($decoded) ? ($decoded['statusCode'] ?? $httpCode) : $httpCode,
                    'response_message' => is_array($decoded) ? ($decoded['message'] ?? null) : null,
                    'response_time_ms' => $responseTimeMs,
                ];
            }
        }

        return response()->json($valid);
    }

    public function startScan(AwsIpRangesFetcher $aws): JsonResponse
    {
        Gate::authorize('bot.manage');

        IpScanSession::whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);

        $existingIps = BypassIp::pluck('ip')->toArray();

        if (empty($existingIps)) {
            return response()->json(['error' => 'No bypass IPs to derive subnets from.'], 422);
        }

        $cidrs = $aws->cidrsContainingIps($existingIps);

        if (empty($cidrs)) {
            return response()->json(['error' => 'No AWS CIDRs found matching existing bypass IPs.'], 422);
        }

        $session = IpScanSession::create([
            'status' => 'running',
            'region' => 'ap-south-1',
            'subnets' => $cidrs,
            'total_candidates' => $aws->countIps($cidrs),
            'probed_count' => 0,
            'found_count' => 0,
            'started_at' => now(),
        ]);

        ScanAwsRegionJob::dispatch($session->id, 'ap-south-1', 0, $cidrs)
            ->onConnection('redis')
            ->onQueue('scan');

        return response()->json($this->sessionPayload($session));
    }

    public function startAwsScan(Request $request, AwsIpRangesFetcher $aws): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'region' => 'required|string|max:32',
        ]);

        IpScanSession::whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);

        $cidrs = $aws->cidrsForRegion($data['region']);

        if (empty($cidrs)) {
            return response()->json(['error' => "No EC2 CIDRs found for region {$data['region']}."], 422);
        }

        $session = IpScanSession::create([
            'status' => 'running',
            'subnets' => $cidrs,
            'total_candidates' => $aws->countIps($cidrs),
            'probed_count' => 0,
            'found_count' => 0,
            'started_at' => now(),
        ]);

        ScanAwsRegionJob::dispatch($session->id, $data['region'])->onConnection('redis')->onQueue('scan');

        return response()->json($this->sessionPayload($session));
    }

    public function startAwsIpv6Scan(Request $request, AwsIpRangesFetcher $aws): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'region' => 'required|string|max:32',
        ]);

        IpScanSession::whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);

        $cidrs = $aws->ipv6CidrsForRegion($data['region']);

        if (empty($cidrs)) {
            return response()->json(['error' => "No IPv6 CIDRs found for region {$data['region']}."], 422);
        }

        $session = IpScanSession::create([
            'type' => 'ipv6',
            'status' => 'running',
            'subnets' => $cidrs,
            'total_candidates' => $aws->countIpv6Ips($cidrs),
            'probed_count' => 0,
            'found_count' => 0,
            'started_at' => now(),
        ]);

        ScanAwsIpv6RegionJob::dispatch($session->id, $data['region'])->onConnection('redis')->onQueue('scan');

        return response()->json($this->sessionPayload($session));
    }

    public function scanStatus(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $session = IpScanSession::latestActive();

        if (! $session) {
            return response()->json(null);
        }

        return response()->json($this->sessionPayload($session));
    }

    public function stopScan(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $updated = IpScanSession::whereIn('status', ['running', 'pausing', 'paused'])
            ->update(['status' => 'stopping']);

        return response()->json(['stopped' => $updated > 0]);
    }

    public function dismissScan(IpScanSession $ipScanSession): JsonResponse
    {
        Gate::authorize('bot.manage');

        $ipScanSession->delete();

        return response()->json(['dismissed' => true]);
    }

    public function pauseScan(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $updated = IpScanSession::where('status', 'running')
            ->update(['status' => 'pausing']);

        return response()->json(['paused' => $updated > 0]);
    }

    public function resumeScan(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $session = IpScanSession::whereIn('status', ['paused', 'stopped'])->latest()->first();

        if (! $session) {
            return response()->json(['error' => 'No paused or stopped scan found.'], 422);
        }

        $session->update(['status' => 'running']);

        if ($session->type === 'ipv6') {
            ScanAwsIpv6RegionJob::dispatch($session->id, $session->region ?? 'ap-south-1', $session->probed_count, $session->subnets ?? [])
                ->onConnection('redis')
                ->onQueue('scan');
        } elseif ($session->type === 'frontend') {
            ScanFrontendOriginJob::dispatch($session->id, $session->probed_count, $session->subnets ?? [])
                ->onConnection('redis')
                ->onQueue('scan');
        } else {
            ScanAwsRegionJob::dispatch($session->id, $session->region ?? 'ap-south-1', $session->probed_count, $session->subnets ?? [])
                ->onConnection('redis')
                ->onQueue('scan');
        }

        return response()->json($this->sessionPayload($session));
    }

    public function startFrontendScan(AwsIpRangesFetcher $aws): JsonResponse
    {
        Gate::authorize('bot.manage');

        IpScanSession::whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);

        $cidrs = $aws->cidrsForRegion('ap-south-1');

        if (empty($cidrs)) {
            return response()->json(['error' => 'No EC2 CIDRs found for ap-south-1.'], 422);
        }

        $session = IpScanSession::create([
            'type' => 'frontend',
            'status' => 'running',
            'region' => 'ap-south-1',
            'subnets' => $cidrs,
            'total_candidates' => $aws->countIps($cidrs),
            'probed_count' => 0,
            'found_count' => 0,
            'started_at' => now(),
        ]);

        ScanFrontendOriginJob::dispatch($session->id)
            ->onConnection('redis')
            ->onQueue('scan');

        return response()->json($this->sessionPayload($session));
    }

    public function approveResult(IpScanResult $ipScanResult): JsonResponse
    {
        Gate::authorize('bot.manage');

        if ($ipScanResult->status !== 'pending') {
            return response()->json(['error' => 'Result is not pending.'], 422);
        }

        $bypassIp = BypassIp::firstOrCreate(
            ['ip' => $ipScanResult->ip],
            ['label' => $ipScanResult->label]
        );

        $ipScanResult->update(['status' => 'approved']);

        return response()->json(['bypass_ip' => $bypassIp]);
    }

    public function rejectResult(IpScanResult $ipScanResult): JsonResponse
    {
        Gate::authorize('bot.manage');

        $ipScanResult->update(['status' => 'rejected']);

        return response()->json(['rejected' => true]);
    }

    public function approveAllResults(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate(['session_id' => 'required|integer|exists:ip_scan_sessions,id']);

        $pending = IpScanResult::where('scan_session_id', $data['session_id'])
            ->where('status', 'pending')
            ->get();

        $added = 0;
        foreach ($pending as $result) {
            BypassIp::firstOrCreate(
                ['ip' => $result->ip],
                ['label' => $result->label]
            );
            $result->update(['status' => 'approved']);
            $added++;
        }

        return response()->json(['approved' => $added]);
    }

    /**
     * Report whether Censys API credentials are configured, exposing the API ID but
     * never the secret. Lets the Censys panel render its state without the settings gate.
     */
    public function censysConfig(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $setting = \App\Models\Setting::instance();

        return response()->json([
            'api_id' => $setting->censys_api_id,
            'configured' => ! empty($setting->censys_api_id) && ! empty($setting->censys_api_secret),
        ]);
    }

    /**
     * Persist Censys API credentials. A blank secret leaves the stored one untouched
     * so the UI never needs to re-enter it.
     */
    public function saveCensysConfig(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'api_id' => 'required|string|max:255',
            'api_secret' => 'nullable|string|max:255',
        ]);

        $setting = \App\Models\Setting::instance();
        $setting->censys_api_id = $data['api_id'];
        if (! empty($data['api_secret'])) {
            $setting->censys_api_secret = $data['api_secret'];
        }
        $setting->save();

        return response()->json([
            'api_id' => $setting->censys_api_id,
            'configured' => ! empty($setting->censys_api_id) && ! empty($setting->censys_api_secret),
        ]);
    }

    /**
     * Query the Censys API for hosts serving the IVAC origin certificate, validate
     * each returned IP against the live origin probe, and stage the genuine origins
     * as pending scan results for approval. Runs synchronously — the candidate set
     * is small, unlike the brute-force AWS scans.
     */
    public function censysLookup(Request $request, CensysOriginLookup $censys, BypassIpScanner $scanner): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'query' => 'nullable|string|max:1000',
        ]);

        try {
            $ips = $censys->search($data['query'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $existing = BypassIp::pluck('ip')->flip()->all();
        $candidates = array_values(array_filter($ips, fn ($ip) => ! isset($existing[$ip])));

        IpScanSession::whereIn('status', ['running', 'stopping'])
            ->update(['status' => 'stopped', 'completed_at' => now()]);

        $session = IpScanSession::create([
            'type' => 'censys',
            'status' => 'running',
            'subnets' => [],
            'total_candidates' => count($ips),
            'probed_count' => 0,
            'found_count' => 0,
            'started_at' => now(),
        ]);

        $found = empty($candidates) ? [] : $scanner->probe($candidates);

        foreach ($found as $result) {
            try {
                IpScanResult::firstOrCreate(
                    ['ip' => $result['ip']],
                    [
                        'scan_session_id' => $session->id,
                        'label' => "Censys - {$result['ip']}",
                        'response_status' => $result['response_status'],
                        'response_message' => is_array($result['response_message']) ? json_encode($result['response_message']) : $result['response_message'],
                        'response_time_ms' => $result['response_time_ms'],
                        'status' => 'pending',
                    ]
                );
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already staged by an earlier lookup — skip.
            }
        }

        $session->update([
            'status' => 'completed',
            'probed_count' => count($candidates),
            'found_count' => count($found),
            'completed_at' => now(),
        ]);

        return response()->json($this->sessionPayload($session->fresh()));
    }

    private function sessionPayload(IpScanSession $session): array
    {
        $configuredIps = BypassIp::pluck('ip')->flip()->all();

        $results = $session->results()->orderByDesc('created_at')->get()
            ->reject(fn ($r) => isset($configuredIps[$r->ip]))
            ->values();

        return [
            'id' => $session->id,
            'type' => $session->type ?? 'ipv4',
            'status' => $session->status,
            'meta' => $session->meta ?? [],
            'subnets' => $session->subnets ?? [],
            'total_candidates' => $session->total_candidates,
            'probed_count' => $session->probed_count,
            'found_count' => $results->where('status', '!=', 'rejected')->count(),
            'started_at' => $session->started_at,
            'completed_at' => $session->completed_at,
            'results' => $results->map(fn ($r) => [
                'id' => $r->id,
                'ip' => $r->ip,
                'label' => $r->label,
                'response_status' => $r->response_status,
                'response_message' => $r->response_message,
                'response_time_ms' => $r->response_time_ms,
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]),
        ];
    }

    public function cleanup(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $query = BypassIp::where(function ($q) {
            $q->whereNotIn('response_status', [500, 503])
                ->orWhereNull('response_status')
                ->orWhere(function ($q2) {
                    $q2->where('response_status', 500)
                        ->where(function ($q3) {
                            $q3->whereNull('response_message')
                                ->orWhere('response_message', 'not like', '%Request method%GET%is not supported%');
                        });
                });
        });

        $snapshot = $query->get()->map->only($this->snapshotColumns())->all();

        $deleted = $query->delete();

        if ($deleted > 0) {
            Cache::put(self::CLEANUP_SNAPSHOT_KEY, $snapshot, now()->addDay());
        }

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Restore the IPs removed by the most recent cleanup. IPs whose address
     * has since been re-added are skipped so existing rows are never clobbered.
     */
    public function restoreCleanup(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $snapshot = Cache::get(self::CLEANUP_SNAPSHOT_KEY, []);

        if (empty($snapshot)) {
            return response()->json(['restored' => 0, 'message' => 'Nothing to restore.']);
        }

        $existingIps = BypassIp::whereIn('ip', array_column($snapshot, 'ip'))->pluck('ip')->flip()->all();

        $restored = 0;
        foreach ($snapshot as $row) {
            if (isset($existingIps[$row['ip']])) {
                continue;
            }

            BypassIp::create($row);
            $restored++;
        }

        Cache::forget(self::CLEANUP_SNAPSHOT_KEY);

        return response()->json(['restored' => $restored]);
    }

    /**
     * @return array<int, string>
     */
    private function snapshotColumns(): array
    {
        return ['label', 'ip', 'is_default', 'last_ping_ms', 'last_pinged_at', 'response_status', 'response_message', 'response_flag', 'response_time_ms'];
    }

    public function assignToSlot(Request $request, BypassIp $bypassIp): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'slot_id' => 'required|integer|exists:agent_slots,id',
        ]);

        \App\Models\AgentSlot::where('id', $data['slot_id'])
            ->update(['bypass_ip_id' => $bypassIp->id]);

        return response()->json(['assigned' => true]);
    }

    public function unassignFromSlot(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'slot_id' => 'required|integer|exists:agent_slots,id',
        ]);

        \App\Models\AgentSlot::where('id', $data['slot_id'])
            ->update(['bypass_ip_id' => null]);

        return response()->json(['unassigned' => true]);
    }

    public function batchProbeFrontend(Request $request, BypassIpScanner $scanner): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'text' => 'required|string|max:50000',
        ]);

        preg_match_all('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $data['text'], $matches);
        $ips = array_values(array_unique($matches[1]));

        if (empty($ips)) {
            return response()->json(['error' => 'No IPs found in the pasted text.'], 422);
        }

        $found = $scanner->probeFrontendChunk($ips, 5000, 10000);
        $foundMap = array_flip(array_column($found, 'ip'));

        $results = array_map(function (string $ip) use ($found, $foundMap): array {
            if (isset($foundMap[$ip])) {
                $r = $found[$foundMap[$ip]];

                return [
                    'ip' => $ip,
                    'hit' => true,
                    'status_code' => $r['response_status'],
                    'message' => $r['response_message'],
                    'response_time_ms' => $r['response_time_ms'],
                ];
            }

            return ['ip' => $ip, 'hit' => false, 'status_code' => null, 'message' => null, 'response_time_ms' => null];
        }, $ips);

        return response()->json([
            'results' => $results,
            'hit_count' => count($found),
        ]);
    }
}
