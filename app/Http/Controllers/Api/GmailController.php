<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GmailMessage;
use App\Services\GmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GmailController extends Controller
{
    public function __construct(private readonly GmailService $gmailService) {}

    public function index(Request $request): JsonResponse
    {
        $account = $request->user()->googleAccount;

        if (! $account) {
            return response()->json(['connected' => false]);
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100]) ? $perPage : 25;

        $messages = $account->messages()
            ->where('from', 'like', '%info@appointment.ivacbd.com%')
            ->orderByDesc('received_at')
            ->paginate($perPage);

        return response()->json([
            'connected' => true,
            'email' => $account->email_address,
            'messages' => $messages,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = $request->user()->googleAccount;

        if (! $account) {
            return response()->json(['error' => 'Not connected'], 403);
        }

        $message = $this->gmailService->getMessage($account, $id);

        return response()->json($message);
    }

    public function batchTrash(Request $request): JsonResponse
    {
        $validated = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string']);

        $account = $request->user()->googleAccount;

        if (! $account) {
            return response()->json(['error' => 'Not connected'], 403);
        }

        $this->gmailService->batchTrash($account, $validated['ids']);

        GmailMessage::where('google_account_id', $account->id)
            ->whereIn('gmail_id', $validated['ids'])
            ->delete();

        return response()->json(['success' => true, 'deleted' => count($validated['ids'])]);
    }

    public function batchDelete(Request $request): JsonResponse
    {
        $validated = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string']);

        $account = $request->user()->googleAccount;

        if (! $account) {
            return response()->json(['error' => 'Not connected'], 403);
        }

        $this->gmailService->batchDelete($account, $validated['ids']);

        GmailMessage::where('google_account_id', $account->id)
            ->whereIn('gmail_id', $validated['ids'])
            ->delete();

        return response()->json(['success' => true, 'deleted' => count($validated['ids'])]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $validated = $request->validate(['permanent' => 'boolean']);

        $account = $request->user()->googleAccount;

        if (! $account) {
            return response()->json(['error' => 'Not connected'], 403);
        }

        $permanent = $validated['permanent'] ?? false;
        $totalDeleted = 0;
        $pageToken = null;

        do {
            $result = $this->gmailService->listAllInboxIds($account, $pageToken);
            $ids = $result['messages'];
            $pageToken = $result['nextPageToken'];

            if (empty($ids)) {
                break;
            }

            foreach (array_chunk($ids, 1000) as $chunk) {
                if ($permanent) {
                    $this->gmailService->batchDelete($account, $chunk);
                } else {
                    $this->gmailService->batchTrash($account, $chunk);
                }

                GmailMessage::where('google_account_id', $account->id)
                    ->whereIn('gmail_id', $chunk)
                    ->delete();

                $totalDeleted += count($chunk);
            }
        } while ($pageToken !== null);

        return response()->json(['success' => true, 'deleted' => $totalDeleted]);
    }
}
