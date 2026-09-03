<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Services\GmailService;
use App\Services\GmailSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GmailService $gmailService,
        private readonly GmailSyncService $gmailSyncService,
    ) {}

    public function redirect(): RedirectResponse
    {
        return redirect($this->gmailService->getAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('gmail.index')->with('error', 'Google authorization was denied.');
        }

        $tokenData = $this->gmailService->exchangeCode($request->input('code'));

        $existing = GoogleAccount::where('user_id', $request->user()->id)->first();

        $account = GoogleAccount::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'email_address' => $this->resolveEmail($tokenData),
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? $existing?->refresh_token ?? '',
                'expires_at' => now()->addSeconds($tokenData['expires_in'] - 30),
                'scope' => $tokenData['scope'] ?? null,
            ]
        );

        $this->gmailSyncService->initialSync($account);

        return redirect()->route('gmail.index')->with('success', 'Google account connected successfully.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account) {
            $this->gmailService->stopWatch($account);
            $this->gmailService->revokeToken($account);
            $account->delete();
        }

        return redirect()->route('gmail.index')->with('success', 'Google account disconnected.');
    }

    private function resolveEmail(array $tokenData): string
    {
        // Prefer id_token payload (no extra HTTP round-trip)
        if (! empty($tokenData['id_token'])) {
            $parts = explode('.', $tokenData['id_token']);
            if (isset($parts[1])) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (! empty($payload['email'])) {
                    return $payload['email'];
                }
            }
        }

        try {
            $client = new \Google\Client;
            $client->setAccessToken($tokenData['access_token']);
            $oauth2 = new \Google\Service\Oauth2($client);

            return $oauth2->userinfo->get()->getEmail() ?? '';
        } catch (\Exception) {
            return '';
        }
    }
}
