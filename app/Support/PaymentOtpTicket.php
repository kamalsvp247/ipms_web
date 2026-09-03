<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Short-lived, single-wallet ticket letting the headless payment driver read one MFS OTP.
 *
 * A long-lived shared secret would let anything that ever saw it read every wallet's codes. A
 * ticket is minted per automation run, is bound to one wallet number, and expires with the run,
 * so a leaked value is worthless minutes later and useless against any other wallet.
 */
class PaymentOtpTicket
{
    private const PREFIX = 'payment:otp_ticket:';

    /**
     * Mint a ticket for one wallet.
     *
     * @param  int  $ttlSeconds  Lifetime, normally the driver's own timeout budget
     */
    public static function issue(string $wallet, int $ttlSeconds): string
    {
        $token = Str::random(48);
        Redis::setex(self::PREFIX.$token, $ttlSeconds, $wallet);

        return $token;
    }

    /**
     * The wallet a ticket is bound to, or null when the token is unknown or expired.
     */
    public static function walletFor(string $token): ?string
    {
        $wallet = Redis::get(self::PREFIX.$token);

        return is_string($wallet) && $wallet !== '' ? $wallet : null;
    }

    public static function revoke(string $token): void
    {
        Redis::del(self::PREFIX.$token);
    }
}
