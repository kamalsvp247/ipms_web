<?php

namespace App\Services;

use App\Enums\CaptchaProviderType;
use App\Models\CaptchaProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CaptchaSolverService
{
    /**
     * Submit a captcha task to the provider and return the vendor task ID.
     *
     * @throws RuntimeException when the provider returns an error.
     */
    public function createTask(CaptchaProvider $provider, string $siteKey, string $pageUrl, string $captchaType = 'turnstile'): string|int
    {
        $this->rejectInHouse($provider);

        if ($this->isJsonApiProvider($provider)) {
            $response = Http::timeout(30)->post($this->jsonApiBaseUrl($provider).'/createTask', [
                'clientKey' => $provider->api_key,
                'task' => [
                    'type' => $this->jsonApiTaskType($provider, $captchaType),
                    'websiteURL' => $pageUrl,
                    'websiteKey' => $siteKey,
                ],
            ])->json();

            if (($response['errorId'] ?? 1) !== 0) {
                throw new RuntimeException('Provider createTask error: '.($response['errorDescription'] ?? json_encode($response)));
            }

            return $response['taskId'];
        }

        // 2captcha-compatible form API (CaptchaAI, SolveCaptcha)
        $isRecaptcha = $captchaType === 'recaptcha';
        $body = Http::timeout(30)->asForm()->post($this->formApiBaseUrl($provider).'/in.php', [
            'key' => $provider->api_key,
            'method' => $isRecaptcha ? 'userrecaptcha' : 'turnstile',
            ($isRecaptcha ? 'googlekey' : 'sitekey') => $siteKey,
            'pageurl' => $pageUrl,
            'json' => 1,
        ])->json();

        if (($body['status'] ?? 0) !== 1) {
            throw new RuntimeException('Provider createTask error: '.($body['request'] ?? json_encode($body)));
        }

        return $body['request'];
    }

    /**
     * Poll the provider for the task result.
     *
     * Returns the raw token string when ready, null when still processing.
     *
     * @throws RuntimeException when the provider returns a hard error (not CAPCHA_NOT_READY).
     */
    public function getTaskResult(CaptchaProvider $provider, string|int $taskId): ?string
    {
        $this->rejectInHouse($provider);

        if ($this->isJsonApiProvider($provider)) {
            $response = Http::timeout(30)->post($this->jsonApiBaseUrl($provider).'/getTaskResult', [
                'clientKey' => $provider->api_key,
                'taskId' => $taskId,
            ])->json();

            if (($response['errorId'] ?? 0) !== 0) {
                throw new RuntimeException('Provider getTaskResult error: '.($response['errorDescription'] ?? 'unknown'));
            }

            if (($response['status'] ?? '') !== 'ready') {
                return null;
            }

            return $response['solution']['gRecaptchaResponse'] ?? $response['solution']['token'] ?? null;
        }

        // 2captcha-compatible form API (CaptchaAI, SolveCaptcha)
        $body = Http::timeout(30)->asForm()->post($this->formApiBaseUrl($provider).'/res.php', [
            'key' => $provider->api_key,
            'action' => 'get',
            'id' => $taskId,
            'json' => 1,
        ])->json();

        $result = $body['request'] ?? 'unknown';

        if ($result === 'CAPCHA_NOT_READY') {
            return null;
        }

        if (($body['status'] ?? 0) !== 1) {
            throw new RuntimeException('Provider getTaskResult error: '.$result);
        }

        return $result;
    }

    /**
     * Fetch the account balance from the provider.
     *
     * Returns the balance as a float, or throws RuntimeException on error.
     */
    public function getBalance(CaptchaProvider $provider): float
    {
        if ($provider->type->isInHouse()) {
            throw new RuntimeException('The in-house solver is self-hosted and has no vendor balance.');
        }

        if ($this->isJsonApiProvider($provider)) {
            $response = Http::timeout(15)->post($this->jsonApiBaseUrl($provider).'/getBalance', [
                'clientKey' => $provider->api_key,
            ])->json();

            if (($response['errorId'] ?? 1) !== 0) {
                throw new RuntimeException('Balance error: '.($response['errorDescription'] ?? json_encode($response)));
            }

            return (float) ($response['balance'] ?? 0);
        }

        // 2captcha-compatible form API (CaptchaAI, SolveCaptcha)
        $body = Http::timeout(15)->asForm()->post($this->formApiBaseUrl($provider).'/res.php', [
            'key' => $provider->api_key,
            'action' => 'getbalance',
            'json' => 1,
        ])->json();

        if (($body['status'] ?? 0) !== 1) {
            throw new RuntimeException('Balance error: '.($body['request'] ?? json_encode($body)));
        }

        return (float) ($body['request'] ?? 0);
    }

    /**
     * The in-house solver speaks no vendor task protocol — it is solved inline by
     * SolveCaptchaJob. Reaching here means a caller routed it down the vendor path,
     * where it would otherwise be misread as a 2captcha-compatible provider and its
     * (empty) API key posted to captchaai.
     *
     * @throws RuntimeException always, for an in-house provider.
     */
    private function rejectInHouse(CaptchaProvider $provider): void
    {
        if ($provider->type->isInHouse()) {
            throw new RuntimeException('The in-house solver is synchronous and has no task API; SolveCaptchaJob solves it inline.');
        }
    }

    private function isJsonApiProvider(CaptchaProvider $provider): bool
    {
        return in_array($provider->type, [
            CaptchaProviderType::CapMonster,
            CaptchaProviderType::TwoCaptcha,
            CaptchaProviderType::CapSolver,
        ], true);
    }

    private function jsonApiBaseUrl(CaptchaProvider $provider): string
    {
        return match ($provider->type) {
            CaptchaProviderType::CapMonster => 'https://api.capmonster.cloud',
            CaptchaProviderType::TwoCaptcha => 'https://api.2captcha.com',
            CaptchaProviderType::CapSolver => 'https://api.capsolver.com',
            default => '',
        };
    }

    private function formApiBaseUrl(CaptchaProvider $provider): string
    {
        return match ($provider->type) {
            CaptchaProviderType::SolveCaptcha => 'https://api.solvecaptcha.com',
            default => 'https://ocr.captchaai.com',
        };
    }

    private function jsonApiTaskType(CaptchaProvider $provider, string $captchaType): string
    {
        if ($captchaType === 'recaptcha') {
            return 'NoCaptchaTaskProxyless';
        }

        return match ($provider->type) {
            CaptchaProviderType::CapSolver => 'AntiTurnstileTaskProxyLess',
            default => 'TurnstileTaskProxyless',
        };
    }
}
