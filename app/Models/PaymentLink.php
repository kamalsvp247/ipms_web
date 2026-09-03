<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class PaymentLink extends Model
{
    use HasFactory;

    /**
     * How long a dg-epay checkout link stays usable after IVAC issues it.
     *
     * Single source of truth for the 5-minute rule: it drives the countdown on /payment-links,
     * the countdown on the automation log, whether auto payment will pick a link up, and whether
     * a run in flight gets aborted.
     */
    public const EXPIRY_MINUTES = 5;

    /**
     * Finish the account as soon as one of its links is judged paid.
     *
     * Three writers set `review_status` — the bot's callback report, the manual review dropdown on
     * /payment-links, and the verdict backfill command — and all three save the model, so hooking
     * the model covers every path and any later one. Account::booted() does the same thing for the
     * worker slot, and picks this change up: completing the account also releases its slot.
     */
    protected static function booted(): void
    {
        static::saved(function (self $link): void {
            if ($link->wasRecentlyCreated || $link->wasChanged('review_status')) {
                $link->completeAccount();
            }
        });
    }

    protected $fillable = [
        'account_id',
        'account_phone',
        'reservation_id',
        'gateway_page_url',
        'redirect_gateway_url',
        'callback_url',
        'callback_status',
        'callback_status_code',
        'callback_response',
        'callback_submitted_at',
        'callback_completed_at',
        'invoice_path',
        'invoice_fetched_at',
        'status_code',
        'message',
        'success_flag',
        'is_clicked',
        'is_fake',
        'review_status',
        'server_time',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'success_flag' => 'boolean',
            'is_clicked' => 'boolean',
            'is_fake' => 'boolean',
            'payload' => 'array',
            'callback_submitted_at' => 'datetime',
            'callback_completed_at' => 'datetime',
            'invoice_fetched_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Mark the account behind this link as completed, once the link is genuinely paid.
     *
     * Paid means the Payment Status the UI renders — `succeeded`, set from IVAC's own confirm
     * redirect or by a human overriding it — and seeded fakes are excluded so a demo row can never
     * finish a real account.
     *
     * Only a running account is promoted. `cancelled` is an operator's explicit decision to stop
     * working the account and this rule must not undo it, the same restraint reportCallbackResult
     * shows by only overwriting an `unread` verdict.
     *
     * @return bool Whether an account's status actually changed.
     */
    public function completeAccount(): bool
    {
        if ($this->is_fake || $this->review_status !== 'succeeded') {
            return false;
        }

        $account = $this->resolveAccount();

        if ($account === null || $account->status !== 'running') {
            return false;
        }

        return $account->update(['status' => 'completed']);
    }

    /**
     * The account this link belongs to, by phone before id.
     *
     * Phone is the identity the rest of the payment path uses — Account::paymentLinks() and
     * isSupersededForAccount() are both keyed on it — because a link ingested before its account
     * existed carries the phone with no account_id.
     */
    private function resolveAccount(): ?Account
    {
        $account = filled($this->account_phone)
            ? Account::where('phone', $this->account_phone)->first()
            : null;

        return $account ?? $this->account;
    }

    public function accountSession(): HasOne
    {
        return $this->hasOne(AccountSession::class, 'phone', 'account_phone');
    }

    public function automationAttempt(): HasOne
    {
        return $this->hasOne(PaymentAutomationAttempt::class);
    }

    /**
     * Links where money has actually moved.
     *
     * A stored callback URL means someone — the driver, the browser extension or a human — drove
     * the checkout through to IVAC's redirect, and a success callback status means the bot has
     * since confirmed delivery. Neither says IVAC *accepted* the money, so a payment IVAC declined
     * is excluded outright: a decline is not a payment, and counting one as paid is what blocked
     * an account from ever auto-paying again.
     *
     * Seeded fakes are excluded so a demo row can never mark an account as paid.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('is_fake', false)
            ->where(fn (Builder $inner) => $inner
                ->whereNotNull('callback_url')
                ->orWhere('callback_status', 'success'))
            // A payment IVAC turned down is not money moved. Both halves above are true of a
            // decline — the driver stored a callback URL and the bot reported the 3xx as success —
            // so without this a declined link becomes a permanent paid-block, and the account can
            // never auto-pay the replacement link IVAC immediately issues (account 988, link 1295).
            // Only an explicit refusal is discounted: a link whose verdict was never recorded still
            // counts as paid, because guessing wrong in that direction charges a wallet twice.
            ->where(fn (Builder $inner) => $inner
                ->whereNull('callback_response')
                ->orWhere('callback_response', 'not like', '%'.self::CALLBACK_DECLINED_MARKER.'%'));
    }

    /**
     * Where IVAC sends the browser after it has judged the payment.
     *
     * This redirect is the only place the *payment's* verdict is recorded. `callback_status` says
     * nothing about it — it means our POST of the callback got a 2xx, which is equally true of a
     * decline, so link 1295 read `success` while IVAC was answering `/payment/fail`.
     */
    public const CALLBACK_DECLINED_MARKER = '/payment/fail';

    /**
     * Links IVAC should hold an invoice for.
     *
     * Known declines are excluded rather than confirmations required: a row whose verdict was never
     * recorded is ambiguous, and dropping it would make its invoice vanish from the ZIP with nothing
     * to show why. Attempting it instead costs one failed fetch that is visible in the response.
     */
    public function scopeInvoiceable(Builder $query): Builder
    {
        return $query->where('is_fake', false)
            ->where('callback_status', 'success')
            ->whereNotNull('reservation_id')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('callback_response')
                ->orWhere('callback_response', 'not like', '%'.self::CALLBACK_DECLINED_MARKER.'%'));
    }

    /**
     * Whether IVAC's redirect says this payment was declined.
     */
    public function wasDeclinedByIvac(): bool
    {
        return $this->callback_response !== null
            && str_contains($this->callback_response, self::CALLBACK_DECLINED_MARKER);
    }

    /**
     * Where IVAC sends the browser once it has accepted the money.
     */
    public const CALLBACK_CONFIRMED_MARKER = '/appointment/confirm-payment';

    /**
     * IVAC's verdict on the payment, or null when it has not given one.
     *
     * The bot cannot supply this: it classifies the callback on HTTP status alone and calls every
     * 3xx a success, so `callback_status` is `success` for a decline too. The redirect target is
     * the only place the money's fate is recorded.
     *
     * @return 'succeeded'|'declined'|null  Matching the review_status vocabulary the UI renders.
     */
    public function ivacVerdict(): ?string
    {
        if ($this->callback_response === null) {
            return null;
        }

        if (str_contains($this->callback_response, self::CALLBACK_DECLINED_MARKER)) {
            return 'declined';
        }

        return str_contains($this->callback_response, self::CALLBACK_CONFIRMED_MARKER)
            ? 'succeeded'
            : null;
    }

    /**
     * Whether a cached invoice PDF is on disk for this link.
     */
    public function hasStoredInvoice(): bool
    {
        return $this->invoice_path !== null && Storage::disk('local')->exists($this->invoice_path);
    }

    /**
     * Where this link's invoice is cached, whether or not it has been fetched yet.
     */
    public function invoiceStoragePath(): string
    {
        return 'invoices/'.$this->reservation_id.'.pdf';
    }

    public function isAutomatable(): bool
    {
        return ! $this->is_fake
            && filled($this->reservation_id)
            && filled($this->gateway_page_url)
            && str_contains($this->gateway_page_url, 'dgepay');
    }

    /**
     * When this link stops being usable, or null when it has no creation timestamp.
     */
    public function expiresAt(): ?CarbonInterface
    {
        return $this->created_at?->copy()->addMinutes(self::EXPIRY_MINUTES);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * Seconds of life left, floored at zero. Null when the link has no creation timestamp.
     */
    public function secondsUntilExpiry(): ?int
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt === null ? null : max(0, (int) now()->diffInSeconds($expiresAt, false));
    }

    /**
     * Whether a newer link exists for the same account.
     *
     * IVAC reissues a checkout link on each payment attempt, and only the newest one is worth
     * paying — driving a superseded link burns a browser on a checkout the account has moved on
     * from.
     */
    public function isSupersededForAccount(): bool
    {
        if (blank($this->account_phone)) {
            return false;
        }

        return static::where('account_phone', $this->account_phone)
            ->where('is_fake', false)
            ->where('id', '>', $this->id)
            ->exists();
    }
}
