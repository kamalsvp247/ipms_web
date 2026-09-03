<?php

namespace App\Services;

use App\Events\NewGmailMessageReceived;
use App\Models\Account;
use App\Models\GmailMessage;
use App\Models\GoogleAccount;
use App\Models\OtpCode;
use App\Support\OtpMessageParser;
use Carbon\Carbon;

class GmailSyncService
{
    private const SENDER_FILTER = 'from:info@appointment.ivacbd.com';

    public function __construct(private readonly GmailService $gmailService) {}

    /**
     * Perform initial sync: fetch first 50 INBOX messages and register push watch.
     */
    public function initialSync(GoogleAccount $account): void
    {
        $result = $this->gmailService->listMessages($account, null, 50, self::SENDER_FILTER, null);
        $messages = $result['messages'];

        if (! empty($messages)) {
            $ids = array_map(fn ($m) => $m->getId(), $messages);
            $details = $this->gmailService->batchGetMessages($account, $ids);
            $newGmailIds = $this->upsertMessages($account, $details);
            $this->extractOtpsForNewMessages($account, $newGmailIds);
        }

        try {
            $watchResult = $this->gmailService->watchInbox($account);
            $account->update([
                'history_id' => $watchResult['history_id'],
                'watch_expires_at' => Carbon::createFromTimestampMs($watchResult['expiration']),
            ]);
        } catch (\Exception $e) {
            // Pub/Sub topic may not exist yet — connection still succeeds, push sync inactive
            \Illuminate\Support\Facades\Log::warning('Gmail watch registration failed: '.$e->getMessage());
        }
    }

    /**
     * Process a Pub/Sub push notification and sync deltas via history.list.
     */
    public function processHistoryUpdate(GoogleAccount $account, string $newHistoryId): void
    {
        if (empty($account->history_id)) {
            return;
        }

        $histories = $this->gmailService->getHistory($account, $account->history_id);

        $addedIds = [];
        $removedIds = [];

        foreach ($histories as $history) {
            foreach ($history->getMessagesAdded() ?? [] as $added) {
                $addedIds[] = $added->getMessage()->getId();
            }
            foreach ($history->getMessagesDeleted() ?? [] as $deleted) {
                $removedIds[] = $deleted->getMessage()->getId();
            }
        }

        if (! empty($removedIds)) {
            GmailMessage::where('google_account_id', $account->id)
                ->whereIn('gmail_id', $removedIds)
                ->delete();
        }

        if (! empty($addedIds)) {
            $details = $this->gmailService->batchGetMessages($account, array_unique($addedIds));
            $newGmailIds = $this->upsertMessages($account, $details);
            $this->extractOtpsForNewMessages($account, $newGmailIds);

            foreach ($details as $msg) {
                broadcast(new NewGmailMessageReceived($account->user_id, $msg))->toOthers();
            }
        }

        $account->update(['history_id' => $newHistoryId]);
    }

    /**
     * Full fallback sync — re-fetch latest 50 messages via messages.list.
     */
    public function fallbackSync(GoogleAccount $account): void
    {
        $result = $this->gmailService->listMessages($account, null, 50, self::SENDER_FILTER, null);
        $messages = $result['messages'];

        if (empty($messages)) {
            return;
        }

        $ids = array_map(fn ($m) => $m->getId(), $messages);
        $details = $this->gmailService->batchGetMessages($account, $ids);
        $newGmailIds = $this->upsertMessages($account, $details);
        $this->extractOtpsForNewMessages($account, $newGmailIds);
    }

    /**
     * @param  array<string, mixed>[]  $messages
     * @return string[]  Gmail IDs of newly created (not updated) rows
     */
    private function upsertMessages(GoogleAccount $account, array $messages): array
    {
        $newIds = [];

        foreach ($messages as $data) {
            if (! str_contains((string) ($data['from'] ?? ''), 'info@appointment.ivacbd.com')) {
                continue;
            }

            $row = GmailMessage::updateOrCreate(
                ['gmail_id' => $data['gmail_id']],
                [
                    'google_account_id' => $account->id,
                    'thread_id' => $data['thread_id'],
                    'from' => $data['from'],
                    'to_address' => $data['to_address'],
                    'phone' => $this->resolvePhone($data['to_address'] ?? ''),
                    'subject' => $data['subject'],
                    'snippet' => $data['snippet'],
                    'received_at' => $data['received_at'],
                    'is_unread' => $data['is_unread'],
                ]
            );

            if ($row->wasRecentlyCreated) {
                $newIds[] = $data['gmail_id'];
            }
        }

        return $newIds;
    }

    /**
     * Fetch the full body for each new Gmail message, parse the OTP, and insert
     * into otp_codes — skipping any gmail_id that was already processed.
     *
     * @param  string[]  $gmailIds
     */
    private function extractOtpsForNewMessages(GoogleAccount $account, array $gmailIds): void
    {
        if (empty($gmailIds)) {
            return;
        }

        // Skip gmail_ids already ingested
        $alreadyDone = OtpCode::whereIn('source_gmail_id', $gmailIds)
            ->pluck('source_gmail_id')
            ->flip()
            ->all();

        foreach ($gmailIds as $gmailId) {
            if (isset($alreadyDone[$gmailId])) {
                continue;
            }

            try {
                $full = $this->gmailService->getMessage($account, $gmailId);
            } catch (\Exception) {
                continue;
            }

            $body = $full['body'] ?? '';
            if (! OtpMessageParser::isIvacbd((string) $body)) {
                continue;
            }

            $otpCode = OtpMessageParser::extractOtp((string) $body);
            if ($otpCode === null) {
                continue;
            }

            $phone = $this->resolvePhone($full['to_address'] ?? '');
            if ($phone === null) {
                continue;
            }

            OtpCode::create([
                'phone' => $phone,
                'otp_code' => $otpCode,
                'message' => $body,
                'source_gmail_id' => $gmailId,
                'is_ivacbd' => true,
                'fetched_at' => Carbon::now('Asia/Dhaka'),
            ]);
        }
    }

    /**
     * Extract a bare email address from a header value and look up the matching account phone.
     * Gmail ignores dots in the local part, so "a.b.c@gmail.com" == "abc@gmail.com".
     * We try an exact case-insensitive match first, then fall back to dot-stripped comparison.
     */
    private function resolvePhone(string $toAddress): ?string
    {
        if (preg_match('/<([^>]+)>/', $toAddress, $m)) {
            $email = strtolower(trim($m[1]));
        } else {
            $email = strtolower(trim($toAddress));
        }

        if ($email === '') {
            return null;
        }

        $phone = Account::whereRaw('LOWER(email) = ?', [$email])->value('phone');
        if ($phone !== null) {
            return $phone;
        }

        // Dots are insignificant in Gmail local parts — strip and retry
        $stripped = str_replace('.', '', $email);

        return Account::whereRaw("REPLACE(LOWER(email), '.', '') = ?", [$stripped])->value('phone');
    }
}
