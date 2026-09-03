<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleAccount;
use App\Services\GmailSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GmailWebhookController extends Controller
{
    public function __construct(private readonly GmailSyncService $gmailSyncService) {}

    public function handle(Request $request): Response
    {
        $body = $request->getContent();
        $data = json_decode($body, true);

        if (! isset($data['message']['data'])) {
            return response('', 200);
        }

        $decoded = json_decode(base64_decode($data['message']['data']), true);

        if (! isset($decoded['emailAddress'], $decoded['historyId'])) {
            return response('', 200);
        }

        $account = GoogleAccount::where('email_address', $decoded['emailAddress'])->first();

        if (! $account) {
            return response('', 200);
        }

        $this->gmailSyncService->processHistoryUpdate($account, (string) $decoded['historyId']);

        return response('', 200);
    }
}
