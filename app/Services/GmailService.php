<?php

namespace App\Services;

use App\Models\GoogleAccount;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\BatchDeleteMessagesRequest;
use Google\Service\Gmail\BatchModifyMessagesRequest;
use Google\Service\Gmail\WatchRequest;
use Google\Service\Oauth2;

class GmailService
{
    private GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setScopes([
            Gmail::GMAIL_READONLY,
            Gmail::GMAIL_MODIFY,
            Oauth2::USERINFO_EMAIL,
        ]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function exchangeCode(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('OAuth error: '.$token['error_description'] ?? $token['error']);
        }

        return $token;
    }

    public function buildForAccount(GoogleAccount $account): Gmail
    {
        $this->refreshIfNeeded($account);

        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->expires_at->diffInSeconds(now(), false),
        ]);

        return new Gmail($this->client);
    }

    public function refreshIfNeeded(GoogleAccount $account): void
    {
        if (! $account->isTokenExpired()) {
            return;
        }

        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => -1,
        ]);

        $newToken = $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);

        if (isset($newToken['error'])) {
            throw new \RuntimeException('Token refresh failed: '.($newToken['error_description'] ?? $newToken['error']));
        }

        $account->update([
            'access_token' => $newToken['access_token'],
            'expires_at' => now()->addSeconds($newToken['expires_in'] - 30),
        ]);
    }

    public function revokeToken(GoogleAccount $account): void
    {
        $this->client->revokeToken($account->access_token);
    }

    public function watchInbox(GoogleAccount $account): array
    {
        $gmail = $this->buildForAccount($account);
        $request = new WatchRequest([
            'labelIds' => ['INBOX'],
            'topicName' => config('services.google.pubsub_topic'),
        ]);

        $response = $gmail->users->watch('me', $request);

        return [
            'history_id' => $response->getHistoryId(),
            'expiration' => $response->getExpiration(),
        ];
    }

    public function stopWatch(GoogleAccount $account): void
    {
        try {
            $gmail = $this->buildForAccount($account);
            $gmail->users->stop('me');
        } catch (\Exception) {
            // Ignore errors on stop — token may already be revoked
        }
    }

    /**
     * @return array{messages: array, nextPageToken: ?string}
     */
    /**
     * @param  string[]|null  $labelIds  null = no label filter (search all mail)
     */
    public function listMessages(GoogleAccount $account, ?string $pageToken = null, int $maxResults = 25, ?string $query = null, ?array $labelIds = ['INBOX']): array
    {
        $gmail = $this->buildForAccount($account);
        $params = ['maxResults' => $maxResults];

        if ($labelIds !== null) {
            $params['labelIds'] = $labelIds;
        }

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        if ($query) {
            $params['q'] = $query;
        }

        $response = $gmail->users_messages->listUsersMessages('me', $params);

        return [
            'messages' => $response->getMessages() ?? [],
            'nextPageToken' => $response->getNextPageToken(),
        ];
    }

    /**
     * @param  string[]  $messageIds
     * @return array<string, mixed>[]
     */
    public function batchGetMessages(GoogleAccount $account, array $messageIds): array
    {
        $gmail = $this->buildForAccount($account);
        $messages = [];

        foreach ($messageIds as $id) {
            $msg = $gmail->users_messages->get('me', $id, ['format' => 'METADATA', 'metadataHeaders' => ['From', 'To', 'Subject', 'Date']]);
            $messages[] = $this->parseMessageMetadata($msg);
        }

        return $messages;
    }

    public function getMessage(GoogleAccount $account, string $messageId): array
    {
        $gmail = $this->buildForAccount($account);
        $msg = $gmail->users_messages->get('me', $messageId, ['format' => 'FULL']);

        return $this->parseFullMessage($msg);
    }

    /**
     * @param  string[]  $ids
     */
    public function batchTrash(GoogleAccount $account, array $ids): void
    {
        $gmail = $this->buildForAccount($account);
        $request = new BatchModifyMessagesRequest([
            'ids' => $ids,
            'addLabelIds' => ['TRASH'],
            'removeLabelIds' => ['INBOX'],
        ]);
        $gmail->users_messages->batchModify('me', $request);
    }

    /**
     * @param  string[]  $ids
     */
    public function batchDelete(GoogleAccount $account, array $ids): void
    {
        $gmail = $this->buildForAccount($account);
        $request = new BatchDeleteMessagesRequest(['ids' => $ids]);
        $gmail->users_messages->batchDelete('me', $request);
    }

    /**
     * @return array{messages: string[], nextPageToken: ?string}
     */
    public function listAllInboxIds(GoogleAccount $account, ?string $pageToken = null, ?string $query = null): array
    {
        $gmail = $this->buildForAccount($account);
        $params = ['labelIds' => ['INBOX'], 'maxResults' => 1000];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        if ($query) {
            $params['q'] = $query;
        }

        $response = $gmail->users_messages->listUsersMessages('me', $params);
        $ids = array_map(fn ($m) => $m->getId(), $response->getMessages() ?? []);

        return [
            'messages' => $ids,
            'nextPageToken' => $response->getNextPageToken(),
        ];
    }

    /**
     * @return array<string, mixed>[]
     */
    public function getHistory(GoogleAccount $account, string $startHistoryId): array
    {
        $gmail = $this->buildForAccount($account);

        try {
            $response = $gmail->users_history->listUsersHistory('me', [
                'startHistoryId' => $startHistoryId,
                'labelId' => 'INBOX',
            ]);

            return $response->getHistory() ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    private function parseMessageMetadata(\Google\Service\Gmail\Message $message): array
    {
        $headers = [];
        foreach ($message->getPayload()?->getHeaders() ?? [] as $header) {
            $headers[$header->getName()] = $header->getValue();
        }

        return [
            'gmail_id' => $message->getId(),
            'thread_id' => $message->getThreadId(),
            'from' => $headers['From'] ?? null,
            'to_address' => $headers['To'] ?? null,
            'subject' => $headers['Subject'] ?? null,
            'snippet' => $message->getSnippet(),
            'received_at' => isset($headers['Date']) ? $this->parseDate($headers['Date']) : null,
            'is_unread' => in_array('UNREAD', $message->getLabelIds() ?? []),
        ];
    }

    private function parseFullMessage(\Google\Service\Gmail\Message $message): array
    {
        $meta = $this->parseMessageMetadata($message);
        $meta['body'] = $this->extractBody($message->getPayload());

        return $meta;
    }

    private function extractBody(?\Google\Service\Gmail\MessagePart $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        // Prefer text/plain
        if ($payload->getMimeType() === 'text/plain') {
            $data = $payload->getBody()?->getData();

            return $data ? base64_decode(strtr($data, '-_', '+/')) : null;
        }

        foreach ($payload->getParts() ?? [] as $part) {
            if ($part->getMimeType() === 'text/plain') {
                $data = $part->getBody()?->getData();
                if ($data) {
                    return base64_decode(strtr($data, '-_', '+/'));
                }
            }
        }

        // Fall back to HTML
        foreach ($payload->getParts() ?? [] as $part) {
            if ($part->getMimeType() === 'text/html') {
                $data = $part->getBody()?->getData();
                if ($data) {
                    return strip_tags(base64_decode(strtr($data, '-_', '+/')));
                }
            }
        }

        return null;
    }

    private function parseDate(string $dateString): ?string
    {
        try {
            return \Carbon\Carbon::parse($dateString)
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        } catch (\Exception) {
            return null;
        }
    }
}
