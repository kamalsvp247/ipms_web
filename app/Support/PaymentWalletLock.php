<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises auto-payment runs that share one mobile-financial-service wallet.
 *
 * The OTP an MFS provider sends carries nothing that identifies which checkout it belongs to, and
 * otp_codes rows are matched by wallet number alone. So while two accounts on the same wallet are
 * both waiting for a code, neither run can tell whose SMS it just consumed. This lock makes that
 * situation impossible rather than trying to detect it: only one run at a time may hold a wallet,
 * and it holds it across the whole window in which an OTP for that wallet can arrive.
 *
 * @see PaymentOtpTicket for the companion class that authorises reading those codes
 */
class PaymentWalletLock
{
    private const PREFIX = 'payment:wallet:';

    /**
     * Reduce a stored wallet value to the mobile number that actually receives the SMS.
     *
     * A Rocket account number is the 11-digit mobile plus a trailing check digit, so 018651441471
     * and 018651441472 are different accounts on the same handset — one SIM, one indistinguishable
     * stream of codes. Locking on the raw stored string would leave exactly the race this class
     * exists to close, so the key is deliberately the handset, not the account.
     *
     * Erring toward over-locking is the safe direction: two genuinely separate wallets that collapse
     * to one key merely take turns, while two wallets that should have collapsed and did not can
     * cross their OTPs and charge the wrong booking.
     *
     * @return string Empty when the value cannot be read as a Bangladeshi mobile number
     */
    public static function normalize(string $wallet): string
    {
        $digits = preg_replace('/\D+/', '', $wallet) ?? '';

        // International spellings the forwarder or an operator may have typed.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '88') && str_starts_with(substr($digits, 2), '01')) {
            $digits = substr($digits, 2);
        }

        // Rocket/DBBL account number: the mobile with one check digit appended.
        if (strlen($digits) === 12 && str_starts_with($digits, '01')) {
            $digits = substr($digits, 0, 11);
        }

        $length = strlen($digits);

        return $length === 10 || $length === 11 ? $digits : '';
    }

    public static function key(string $wallet): string
    {
        return self::PREFIX.self::normalize($wallet);
    }

    /**
     * Take the wallet, or return null when another run already holds it.
     *
     * Never blocks: only a handful of payment workers exist, and one parked on a wallet that is
     * busy for minutes would stall every other wallet behind it.
     *
     * @param  int  $ttlSeconds  Lifetime, sized so a worker killed mid-run still frees the wallet
     */
    public static function acquire(string $wallet, int $ttlSeconds): ?Lock
    {
        if (self::normalize($wallet) === '') {
            return null;
        }

        $lock = Cache::lock(self::key($wallet), $ttlSeconds);

        return $lock->get() ? $lock : null;
    }

    /**
     * Every spelling an SMS for this wallet could have been stored under.
     *
     * The account stores the 12-digit Rocket number, but the forwarder posts the number of the SIM
     * that received the message — the 11-digit mobile — so a lookup on the stored value alone finds
     * nothing. Matching the whole family keeps both spellings (and a country-code-prefixed one)
     * reachable without having to normalise historical rows.
     *
     * @return list<string>
     */
    public static function candidatePhones(string $wallet): array
    {
        $mobile = self::normalize($wallet);
        if ($mobile === '') {
            return [];
        }

        $raw = preg_replace('/\D+/', '', $wallet) ?? '';

        return array_values(array_unique(array_filter([
            $mobile,
            '88'.$mobile,
            $raw,
        ])));
    }
}
