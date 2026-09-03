<?php

namespace App\Support;

/**
 * Recognises payment OTPs from Bangladeshi mobile-financial-service providers (bKash, Nagad,
 * Rocket/DBBL) so the auto-payment driver can read the code out of a forwarded SMS.
 *
 * Kept separate from OtpMessageParser on purpose: that class owns IVAC booking OTPs, and the two
 * streams must never be confused. Callers classify IVAC first and only fall through to here.
 */
class MfsOtpParser
{
    /**
     * Provider fingerprints. A message must name one of these to be treated as MFS at all, which
     * keeps unrelated marketing SMS from being stored as spendable codes.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDERS = [
        'bkash' => ['bkash', 'bKash'],
        'nagad' => ['nagad', 'Nagad'],
        'rocket' => ['rocket', 'Rocket', 'DBBL', 'Dutch-Bangla'],
    ];

    /**
     * Wording that marks the message as a one-time code rather than a balance or promo notice.
     *
     * @var list<string>
     */
    private const OTP_PHRASES = [
        'otp',
        'one time password',
        'one-time password',
        'verification code',
        'security code',
        'pin is',
        'code is',
    ];

    public static function isMfs(string $message): bool
    {
        return self::provider($message) !== null && self::mentionsOtp($message);
    }

    /**
     * Which provider sent this message, or null when none is named.
     */
    public static function provider(string $message): ?string
    {
        foreach (self::PROVIDERS as $provider => $needles) {
            foreach ($needles as $needle) {
                if (stripos($message, $needle) !== false) {
                    return $provider;
                }
            }
        }

        return null;
    }

    /**
     * Pull the numeric code out of an MFS OTP message.
     *
     * Prefers a code that directly follows OTP wording, because these messages also carry an
     * amount and a transaction id that a naive "first number wins" match would grab instead.
     */
    public static function extractOtp(string $message): ?string
    {
        // Keyword first ("your OTP for the transaction is 445566").
        //
        // Two things the obvious pattern gets wrong on real provider wording, both seen live in
        // "Do NOT share your OTP or PIN with anyone. Your bKash OTP for PAYMENT of Tk.5,780.00 to
        // bKash_ACS is 894251.":
        //
        //  - The FIRST keyword is in the do-not-share warning, nowhere near the code, so anchoring
        //    on it and giving up loses the message. Every candidate is tried and the LAST match
        //    wins, because the code always trails the wording that introduces it.
        //  - A money amount sits between the keyword and the code. Treating any digit as a hard
        //    stop makes the code unreachable, so an amount is explicitly allowed to be skipped
        //    while a bare number still terminates the window (that number would be the code).
        $amount = '(?:[\p{Sc}]|Tk\.?|BDT)\s*[\d,]+(?:\.\d+)?';
        $labelled = '/(?:otp|one[\s-]?time\s+password|verification\s+code|security\s+code|pin\s+is)'
            .'(?:\D|'.$amount.'){0,60}?(\d{4,8})(?!\d)/iu';
        if (preg_match_all($labelled, $message, $matches, PREG_SET_ORDER) > 0) {
            return end($matches)[1];
        }

        // Code first ("123456 is your bKash verification code").
        $trailing = '/(\d{4,8})\D{0,40}?(?:otp|one[\s-]?time\s+password|verification\s+code|security\s+code)/i';
        if (preg_match($trailing, $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private static function mentionsOtp(string $message): bool
    {
        foreach (self::OTP_PHRASES as $phrase) {
            if (stripos($message, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }
}
