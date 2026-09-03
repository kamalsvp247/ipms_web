<?php

namespace App\Services\Payment;

use App\Models\PaymentLink;

/**
 * Matches a post-payment redirect URL to its payment link and stores it for the Java bot to poll.
 *
 * Single source of truth for that write, shared by the browser extension's public ingest, the
 * portal's manual paste box, and the auto-payment driver — all three must produce byte-identical
 * state, because PaymentCallbackService on the bot side polls for exactly this.
 */
class PaymentCallbackRouter
{
    public const RESULT_OK = 'ok';

    public const RESULT_NO_TRAN_ID = 'no_tran_id';

    public const RESULT_NOT_FOUND = 'not_found';

    /**
     * Store a callback URL against the link whose reservation_id matches the URL's tran_id.
     *
     * @return array{result: string, link: PaymentLink|null, tran_id: string|null}
     */
    public function route(string $url): array
    {
        $tranId = $this->extractTranId($url);
        if ($tranId === null) {
            return ['result' => self::RESULT_NO_TRAN_ID, 'link' => null, 'tran_id' => null];
        }

        $target = PaymentLink::where('reservation_id', $tranId)->latest('id')->first();
        if ($target === null) {
            return ['result' => self::RESULT_NOT_FOUND, 'link' => null, 'tran_id' => $tranId];
        }

        $target->update([
            'callback_url' => $this->normalizeUrl($url),
            'callback_status' => 'pending',
            'callback_status_code' => null,
            'callback_response' => null,
            'callback_submitted_at' => now(),
            'callback_completed_at' => null,
        ]);

        return ['result' => self::RESULT_OK, 'link' => $target, 'tran_id' => $tranId];
    }

    /**
     * Pull the tran_id query parameter out of a callback URL.
     */
    public function extractTranId(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $tranId = $params['tran_id'] ?? null;

        return is_string($tranId) && $tranId !== '' ? $tranId : null;
    }

    /**
     * Add the scheme back when a hand-pasted URL omits it.
     *
     * The bot builds an OkHttp request straight from this string; a scheme-less value fails to
     * parse and the account then polls forever (one such row is stranded in the June backup).
     */
    private function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        return 'https://'.ltrim($trimmed, '/');
    }
}
