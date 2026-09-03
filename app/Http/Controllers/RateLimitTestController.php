<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RateLimitTestController extends Controller
{
    private const LOG_FILE = '/tmp/rate-limit-test.log';

    private const PID_KEY = 'rate_limit_test_pid';

    public function index(): Response
    {
        return Inertia::render('RateLimitTest/Index', [
            'running' => $this->isRunning(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $request->validate(['count' => 'required|integer|min:1|max:500']);

        if ($this->isRunning()) {
            return response()->json(['error' => 'Test already running'], 409);
        }

        $count = (int) $request->input('count');
        $script = base_path('ipms_java/run-rate-limit-test.sh');
        $logFile = self::LOG_FILE;

        file_put_contents($logFile, '');

        $cmd = "bash {$script} {$count} > {$logFile} 2>&1 & echo \$!";
        exec($cmd, $output);
        $pid = trim($output[0] ?? '');

        if ($pid) {
            Cache::put(self::PID_KEY, $pid, now()->addHours(2));
        }

        return response()->json(['pid' => $pid, 'count' => $count]);
    }

    public function stop(): JsonResponse
    {
        $pid = Cache::get(self::PID_KEY);

        if ($pid) {
            exec("kill {$pid} 2>/dev/null");
            exec("pkill -f 'RateLimitRunner' 2>/dev/null");
            exec("pkill -f 'run-rate-limit-test' 2>/dev/null");
            Cache::forget(self::PID_KEY);
        }

        return response()->json(['stopped' => true]);
    }

    public function output(): JsonResponse
    {
        $lines = [];
        if (file_exists(self::LOG_FILE)) {
            $lines = file(self::LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        }

        return response()->json([
            'lines' => array_values(array_slice($lines, -300)),
            'running' => $this->isRunning(),
        ]);
    }

    public function clear(): JsonResponse
    {
        file_put_contents(self::LOG_FILE, '');

        return response()->json(['cleared' => true]);
    }

    private function isRunning(): bool
    {
        $pid = Cache::get(self::PID_KEY);
        if (! $pid) {
            return false;
        }

        exec("ps -p {$pid} -o pid= 2>/dev/null", $out);

        if (empty($out)) {
            Cache::forget(self::PID_KEY);

            return false;
        }

        return true;
    }
}
