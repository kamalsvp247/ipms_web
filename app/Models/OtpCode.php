<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OtpCode extends Model
{
    protected $fillable = [
        'phone',
        'otp_code',
        'message',
        'source_gmail_id',
        'is_ivacbd',
        'is_mfs',
        'fetched_at',
        'consumed_at',
    ];

    protected $appends = ['server_time'];

    protected $casts = [
        'fetched_at' => 'datetime',
        'consumed_at' => 'datetime',
        'is_ivacbd' => 'boolean',
        'is_mfs' => 'boolean',
    ];

    protected function serverTime(): Attribute
    {
        return Attribute::get(fn () => $this->fetched_at?->utc()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function gmailMessage(): BelongsTo
    {
        return $this->belongsTo(\App\Models\GmailMessage::class, 'source_gmail_id', 'gmail_id');
    }

    /**
     * Atomically fetch (and optionally consume) the latest unconsumed OTP for a phone.
     *
     * @param  string  $phone  Account phone number
     * @param  string|null  $channel  'sms' (source_gmail_id IS NULL), 'email' (NOT NULL), or null for any
     * @param  int|null  $sinceMs  Epoch ms; only OTPs with fetched_at >= (since - tolerance) are returned
     * @param  int  $toleranceMs  Backward tolerance in ms to absorb portal/IVAC clock skew (default 2000)
     * @param  bool  $consume  Whether to mark the row consumed (default true)
     */
    public static function consumeForPhone(
        string $phone,
        ?string $channel = null,
        ?int $sinceMs = null,
        int $toleranceMs = 2000,
        bool $consume = true,
    ): ?static {
        return DB::transaction(function () use ($phone, $channel, $sinceMs, $toleranceMs, $consume) {
            $query = static::where('phone', $phone)
                ->whereNull('consumed_at')
                ->whereNotNull('otp_code')
                // Payment OTPs belong to the auto-payment driver alone. Without this a wallet that
                // shares a SIM with the booking account would have its payment code consumed by the
                // sign-in poll — breaking both flows at once.
                ->where('is_mfs', false);

            if ($channel === 'sms') {
                $query->whereNull('source_gmail_id');
            } elseif ($channel === 'email') {
                $query->whereNotNull('source_gmail_id');
            }

            if ($sinceMs !== null) {
                $thresholdMs = max(0, $sinceMs - $toleranceMs);
                $threshold = \Carbon\CarbonImmutable::createFromTimestampMs($thresholdMs)
                    ->setTimezone(config('app.timezone'));
                $query->where('fetched_at', '>=', $threshold);
            }

            $otp = $query->orderBy('fetched_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($otp && $consume) {
                $otp->update(['consumed_at' => now()]);
            }

            return $otp;
        });
    }

    /**
     * Atomically fetch (and consume) the latest unconsumed mobile-financial-service payment OTP
     * for a wallet number.
     *
     * Deliberately separate from consumeForPhone(): a payment OTP and an IVAC sign-in OTP can
     * arrive on the same SIM seconds apart, and handing the wrong one to either consumer breaks
     * that flow. The `is_mfs` filter is what keeps the two streams apart.
     *
     * Unlike consumeForPhone(), the default tolerance is zero. That method's `since` comes from the
     * Java bot on a different machine, so it has real clock skew to absorb; every writer of an MFS
     * row's fetched_at is the portal itself, and the driver's `since` is taken on the same host. A
     * backward window here would buy nothing and would be the only way a code that predates this
     * run's wallet submit could be consumed as this run's.
     *
     * @param  string|list<string>  $phone  Wallet number(s) the SMS may have arrived under
     * @param  int|null  $sinceMs  Epoch ms; only OTPs fetched at or after (since - tolerance) count
     * @param  int  $toleranceMs  Backward tolerance in ms to absorb clock skew
     */
    public static function consumeMfsForPhone(
        string|array $phone,
        ?int $sinceMs = null,
        int $toleranceMs = 0,
        bool $consume = true,
    ): ?static {
        $phones = array_values(array_filter((array) $phone, static fn ($p): bool => (string) $p !== ''));
        if ($phones === []) {
            return null;
        }

        return DB::transaction(function () use ($phones, $sinceMs, $toleranceMs, $consume) {
            $query = static::whereIn('phone', $phones)
                ->where('is_mfs', true)
                ->whereNull('consumed_at')
                ->whereNotNull('otp_code');

            if ($sinceMs !== null) {
                $thresholdMs = max(0, $sinceMs - $toleranceMs);
                $threshold = \Carbon\CarbonImmutable::createFromTimestampMs($thresholdMs)
                    ->setTimezone(config('app.timezone'));
                $query->where('fetched_at', '>=', $threshold);
            }

            $otp = $query->orderBy('fetched_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($otp && $consume) {
                $otp->update(['consumed_at' => now()]);
            }

            return $otp;
        });
    }

    /**
     * Retire every unconsumed payment OTP for a wallet.
     *
     * Called when an automation run gives up its wallet lock. While that lock was held, every MFS
     * code for the wallet belonged to that run, so anything still unconsumed at release is a
     * leftover — an SMS that arrived after the run gave up, a duplicate, a code typed by hand too
     * late. Leaving it behind would put a code with no way of identifying its booking exactly where
     * the next run on that wallet will look.
     *
     * @param  list<string>  $phones  Every spelling the SMS could have been stored under
     * @return int Rows retired
     */
    public static function drainMfsForPhones(array $phones): int
    {
        $phones = array_values(array_filter($phones, static fn ($p): bool => (string) $p !== ''));
        if ($phones === []) {
            return 0;
        }

        return static::whereIn('phone', $phones)
            ->where('is_mfs', true)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
