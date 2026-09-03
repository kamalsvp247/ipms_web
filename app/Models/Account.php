<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agent_slot_id',
        'phone',
        'email',
        'tag',
        'appointment_id',
        'appointment_id_updated_at',
        'appointment_dates',
        'pdf_uploaded',
        'pdf_uploaded_date',
        'booking_configured',
        'booking_configured_date',
        'booking_city',
        'ivac_profile_name',
        'profile_name_fixed_at',
        'password',
        'max_retries',
        'retry_delay_ms',
        'signin_tick_shots',
        'signin_tick_interval_ms',
        'otp_tick_shots',
        'otp_tick_interval_ms',
        'slot_tick_shots',
        'slot_tick_interval_ms',
        'payment_tick_shots',
        'payment_tick_interval_ms',
        'single_sign_in',
        'is_active',
        'status',
        'auto_payment',
        'auto_payment_method',
        'auto_payment_wallet',
        'auto_payment_pin',
    ];

    /**
     * A worker slot only ever has work to do for a running account, so leaving one assigned after
     * the account stops running strands capacity on /bot-control and puts an account the operator
     * has finished with back into the slot's config export. Releasing the slot the moment the
     * status leaves "running" keeps the assignment list honest without anyone having to remember.
     *
     * Hooked on the model rather than the controller so every writer is covered — the accounts
     * page, a bulk action, or anything that saves the model later.
     */
    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isDirty('status') && $account->status !== 'running') {
                $account->agent_slot_id = null;
            }
        });
    }

    protected $attributes = [
        'single_sign_in' => true,
        'pdf_uploaded' => false,
        'booking_configured' => false,
        'auto_payment' => false,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'single_sign_in' => 'boolean',
        'appointment_id_updated_at' => 'datetime',
        'appointment_dates' => 'array',
        'pdf_uploaded' => 'boolean',
        'pdf_uploaded_date' => 'date',
        'booking_configured' => 'boolean',
        'booking_configured_date' => 'date',
        'profile_name_fixed_at' => 'datetime',
        'auto_payment' => 'boolean',
        'auto_payment_rearmed_at' => 'datetime',
    ];

    /**
     * The payment PIN is a spending credential — keep it out of every array/JSON payload so it
     * never rides along on the account list. AccountController::show() merges it back in on
     * demand for the edit form, the same way PDFs are handled.
     *
     * @var list<string>
     */
    protected $hidden = [
        'auto_payment_pin',
    ];

    /**
     * Cheap PDF count for list views. The Base64 payload lives in the account_pdfs table (loaded
     * only on demand), so list pages get the count without ever hydrating the heavy rows.
     *
     * @var list<string>
     */
    protected $appends = [
        'pdfs_count',
    ];

    /**
     * PDF count resolution order: a withCount('pdfs') value eager-loaded by the list query (passed
     * in as $value), then an already-loaded relation, then a lightweight count query. Keeps list
     * endpoints free of N+1s while single-account responses still report an accurate count.
     */
    protected function pdfsCount(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): int {
                if ($value !== null) {
                    return (int) $value;
                }
                if ($this->relationLoaded('pdfs')) {
                    return $this->pdfs->count();
                }

                return $this->exists ? $this->pdfs()->count() : 0;
            },
        );
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                try {
                    return Crypt::decrypt($value);
                } catch (\Exception $e) {
                    // Encrypted with a different APP_KEY (e.g. restored from backup).
                    // Return null so the account is still readable; update password via UI to re-encrypt.
                    return null;
                }
            },
            set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
        );
    }

    /**
     * Mirrors the password accessor: encrypted at rest, and a decrypt failure (typically a restored
     * backup under a different APP_KEY) yields null rather than throwing, so the account stays
     * readable and the PIN can simply be re-entered.
     */
    protected function autoPaymentPin(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                try {
                    return Crypt::decrypt($value);
                } catch (\Exception $e) {
                    return null;
                }
            },
            set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
        );
    }

    /**
     * Accounts whose auto payment is switched on AND fully credentialed. Both halves matter: a
     * half-filled account must never reach the driver, which would burn a payment link on a
     * guaranteed failure.
     */
    public function scopeAutoPaymentReady(Builder $query): Builder
    {
        return $query->where('auto_payment', true)
            ->whereNotNull('auto_payment_method')
            ->whereNotNull('auto_payment_wallet')
            ->whereNotNull('auto_payment_pin');
    }

    /**
     * Whether this account can be handed to the auto-payment driver right now.
     */
    public function isAutoPaymentReady(): bool
    {
        return $this->auto_payment
            && filled($this->auto_payment_method)
            && filled($this->auto_payment_wallet)
            && filled($this->getRawOriginal('auto_payment_pin'));
    }

    /**
     * Keyed on phone rather than the id column, matching the identity PaymentLink itself uses in
     * isSupersededForAccount() and the auto-payment sweep — a link ingested before the account
     * existed carries the phone but no account_id.
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class, 'account_phone', 'phone');
    }

    /**
     * Whether this account has already paid in the current booking cycle.
     *
     * A completed payment is a hard stop on auto payment: IVAC keeps reissuing checkout links after
     * one succeeds, and paying a second charges the wallet twice for a single booking. The re-arm
     * watermark is what makes this a per-cycle rule instead of a permanent one.
     *
     * The callback timestamp is compared as well as the link's own age to close a hole in the
     * obvious version: an operator re-arms while a payment is still in flight on an older link, that
     * payment completes, and a watermark that only looked at created_at would declare the account
     * unpaid and let the next link charge it again.
     */
    public function hasCompletedPayment(): bool
    {
        return PaymentLink::query()
            ->where('account_phone', $this->phone)
            ->completed()
            ->when(
                $this->auto_payment_rearmed_at !== null,
                fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->where('created_at', '>', $this->auto_payment_rearmed_at)
                    ->orWhere('callback_submitted_at', '>', $this->auto_payment_rearmed_at)),
            )
            ->exists();
    }

    /**
     * The SQL form of hasCompletedPayment(), for callers that must filter in the database rather
     * than per row — the auto-payment sweep and the account list's paid badge.
     */
    public function scopeAutoPaymentArmed(Builder $query): Builder
    {
        return $query->whereDoesntHave('paymentLinks', fn (Builder $link) => $link
            ->completed()
            ->where(fn (Builder $inner) => $inner
                ->whereNull('accounts.auto_payment_rearmed_at')
                ->orWhereColumn('payment_links.created_at', '>', 'accounts.auto_payment_rearmed_at')
                ->orWhereColumn('payment_links.callback_submitted_at', '>', 'accounts.auto_payment_rearmed_at')));
    }

    /**
     * Clear the paid block so the account can be auto-paid again for a new booking.
     *
     * Deliberately a method rather than a fillable column: this authorises spending, and must never
     * be settable as a stray key on an ordinary account update.
     */
    public function rearmAutoPayment(): void
    {
        $this->forceFill(['auto_payment_rearmed_at' => now()])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentSlot(): BelongsTo
    {
        return $this->belongsTo(AgentSlot::class);
    }

    public function pdfs(): HasMany
    {
        return $this->hasMany(AccountPdf::class);
    }

    /**
     * Constrains a query to accounts the bot may sign in for: those with at least one applicant
     * PDF flagged primary, OR an operator's manual pdf_uploaded override stamped for today's
     * window (IVAC-side setup done outside the portal's own upload path).
     *
     * The bot must never sign in for an account with neither signal: IVAC gates slot reserve on
     * the applicant PDFs being uploaded, and every secondary applicant attaches to the primary's
     * record, so a missing primary (and no override) makes the booking unwinnable. Excluding such
     * accounts from the exported config means the bot never receives them and never spawns a
     * worker (or signs in) for them.
     */
    public function scopeEligibleForBot(Builder $query): Builder
    {
        return $query->where(function (Builder $eligible) {
            $eligible->whereHas('pdfs', fn (Builder $pdf) => $pdf->where('is_primary', true))
                ->orWhere(function (Builder $override) {
                    $override->where('pdf_uploaded', true)
                        ->whereDate('pdf_uploaded_date', self::todayDhaka());
                });
        });
    }

    /**
     * The session row holding this account's current JWT.
     *
     * Keyed on phone, not account_id: every writer upserts on phone
     * (AccountSession::updateOrCreate(['phone' => ...])), so phone is the real identity of a
     * session and there is exactly one row per phone. Matching on account_id instead let a row
     * left behind by a phone this account used earlier win on MAX(id) — the config export then
     * read that stale row, saw an expired token, sent accessToken: null, and the bot signed in
     * again while holding a perfectly good JWT, earning IVAC's 429 sign-in cooldown.
     */
    public function latestSession(): HasOne
    {
        return $this->hasOne(AccountSession::class, 'phone', 'phone')->latestOfMany();
    }

    /**
     * The Dhaka calendar date on which the current booking window opens. Setup completed for an
     * earlier date is stale (IVAC wipes it after each window), so the bot must re-run it.
     */
    private static function todayDhaka(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    /**
     * True only when the PDFs were uploaded for today's window — the boolean guards the reset case,
     * the date guards cross-day staleness. This is what /api/config reports, not the raw boolean.
     */
    public function pdfUploadedToday(): bool
    {
        return $this->pdf_uploaded
            && $this->pdf_uploaded_date?->toDateString() === self::todayDhaka();
    }

    /**
     * True only when the booking config was posted for today's window (see pdfUploadedToday).
     */
    public function bookingConfiguredToday(): bool
    {
        return $this->booking_configured
            && $this->booking_configured_date?->toDateString() === self::todayDhaka();
    }
}
