<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auto-payment run against one payment link.
 *
 * The row is created before any browser is launched and claimed with a conditional update, so it
 * doubles as the mutex that stops two workers charging the same link twice.
 */
class PaymentAutomationAttempt extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * Give up after this many tries so a structurally broken link cannot loop forever.
     */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'payment_link_id',
        'account_id',
        'method',
        'status',
        'attempts',
        'stage',
        'callback_url',
        'last_error',
        'driver_log',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * dg-epay's one-session-per-checkout refusal, as the driver phrases it.
     *
     * The first run is what takes that session, so every retry after it meets this page instead of
     * the method list — burning the remaining attempts and overwriting the one error that
     * explained the original failure.
     */
    public const SESSION_LOCK_MARKER = 'session is already open in another window';

    /**
     * The stages a run can fail at without the gateway's checkout ever having been opened.
     *
     * Everything else means the method list was on screen, which is the moment dg-epay binds the
     * checkout to that browser.
     *
     * @var list<string>
     */
    public const STAGES_BEFORE_CHECKOUT = ['queued', 'wallet_busy', 'launch', 'navigate', 'method', 'input'];

    public function isRetryable(): bool
    {
        return $this->status !== self::STATUS_SUCCEEDED
            && $this->status !== self::STATUS_RUNNING
            && $this->attempts < self::MAX_ATTEMPTS
            && ! $this->isSessionLocked()
            && ! $this->hasOpenedCheckout();
    }

    /**
     * Whether a previous run got far enough to take the gateway's one checkout session.
     *
     * dg-epay binds a checkout to the first browser that opens it, and that session dies with the
     * run. Retrying afterwards cannot reach anything but SESSION ACTIVE ELSEWHERE: it spends the
     * remaining tries on a page that will never pay, and overwrites last_error with a session clash
     * that hides the failure actually worth reading. Link 1293 failed on a disabled Pay button and
     * was reported as a session clash; 1290-1292 read the same way for three different reasons.
     */
    public function hasOpenedCheckout(): bool
    {
        return $this->stage !== null
            && ! in_array($this->stage, self::STAGES_BEFORE_CHECKOUT, true);
    }

    /**
     * Whether the gateway has locked this checkout to a session we no longer control.
     */
    public function isSessionLocked(): bool
    {
        return $this->last_error !== null
            && str_contains(mb_strtolower($this->last_error), self::SESSION_LOCK_MARKER);
    }
}
