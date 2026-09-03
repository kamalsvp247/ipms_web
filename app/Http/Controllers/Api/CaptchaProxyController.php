<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptchaProvider;
use App\Services\CaptchaSolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptchaProxyController extends Controller
{
    public function __construct(private readonly CaptchaSolverService $solver) {}

    public function createTask(Request $request): JsonResponse
    {
        $request->validate([
            'provider_id' => 'required|exists:captcha_providers,id',
            'websiteURL' => 'required|url',
            'websiteKey' => 'required|string',
            'captchaType' => 'nullable|in:turnstile,recaptcha',
        ]);

        $provider = CaptchaProvider::findOrFail($request->provider_id);
        $captchaType = $request->input('captchaType', 'turnstile');

        try {
            $taskId = $this->solver->createTask($provider, $request->websiteKey, $request->websiteURL, $captchaType);

            return response()->json(['errorId' => 0, 'taskId' => $taskId]);
        } catch (\RuntimeException $e) {
            return response()->json(['errorId' => 1, 'errorDescription' => $e->getMessage()]);
        }
    }

    public function getTaskResult(Request $request): JsonResponse
    {
        $request->validate([
            'provider_id' => 'required|exists:captcha_providers,id',
            'taskId' => 'required',
        ]);

        $provider = CaptchaProvider::findOrFail($request->provider_id);

        try {
            $token = $this->solver->getTaskResult($provider, $request->taskId);

            if ($token === null) {
                return response()->json(['errorId' => 0, 'status' => 'processing']);
            }

            return response()->json([
                'errorId' => 0,
                'status' => 'ready',
                'solution' => ['gRecaptchaResponse' => $token],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['errorId' => 1, 'errorDescription' => $e->getMessage()]);
        }
    }
}
