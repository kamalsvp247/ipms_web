<?php

namespace App\Services\Payment;

use App\Jobs\AutoPaymentJob;
use App\Models\OtpCode;
use App\Models\PaymentAutomationAttempt;
use App\Models\PaymentLink;
use App\Support\PaymentOtpTicket;
use App\Support\PaymentWalletLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the headless dg-epay checkout for one attempt and records the outcome.
 *
 * The browser work itself lives in app/Scripts/dgepay_payment_driver.cjs; this class owns the
 * things that must not live in Node — credential decryption, the concurrency cap, the OTP ticket
 * lifecycle, and writing the resulting callback URL where the Java bot is already polling.
 */
class PaymentAutomationService
{
    private const SCRIPT = 'Scripts/dgepay_payment_driver.cjs';

    /**
     * Named slots capping how many headless browsers run at once. Each Chrome costs real CPU, so an
     * unbounded fan-out would take the portal down.
     */
    private const SLOT_KEY_PREFIX = 'payment:automation:slot:';

    private const DEFAULT_CONCURRENCY = 3;

    private const DRIVER_TIMEOUT_SECONDS = 240;

    /**
     * The driver reaches this stage only once its own OTP has been read and consumed, which is the
     * exact moment the wallet stops being contended and the next account may use it.
     */
    private const WALLET_RELEASE_STAGE = 'otp';

    /**
     * Below this many seconds of checkout life left, a deferred run is failed rather than requeued:
     * there is no longer time to launch a browser, wait for an SMS and confirm.
     */
    private const MIN_USEFUL_SECONDS = 45;

    /**
     * Driver console output from the most recent runDriver() call, retained so a throw on the way
     * out (timeout, unparseable payload) still records the log that explains it.
     */
    private ?string $lastDriverLog = null;

    /**
     * Set when a run is killed because its checkout link expired, so the outcome is recorded as
     * an expiry rather than as whatever partial output the killed driver left behind.
     */
    private ?string $expiryAbortReason = null;

    /**
     * The wallet this run holds, and the number it was taken for. The number is captured rather than
     * re-read at release time so that editing the account mid-run still frees the right wallet.
     */
    private ?Lock $walletLock = null;

    private ?string $lockedWallet = null;

    private ?Lock $slot = null;

    public function __construct(private PaymentCallbackRouter $router) {}

    public function run(PaymentAutomationAttempt $attempt): void
    {
        $this->walletLock = null;
        $this->lockedWallet = null;
        $this->slot = null;

        $link = $attempt->paymentLink;
        $account = $attempt->account;

        if ($link === null || $account === null) {
            $this->fail($attempt, 'resolve', 'Payment link or account is missing.');

            return;
        }

        if (! $account->isAutoPaymentReady()) {
            $this->fail($attempt, 'credentials', 'Account is not auto-payment ready.');

            return;
        }

        // The link may have aged out between dispatch and this worker picking the job up.
        if ($link->isExpired()) {
            $this->fail($attempt, 'expired', 'Checkout link expired before the run started.');

            return;
        }

        if ($link->isSupersededForAccount()) {
            $this->fail($attempt, 'superseded', 'A newer payment link exists for this account.');

            return;
        }

        // Re-read immediately before spending: dispatch and execution can be minutes apart once a
        // run has been deferred behind a busy wallet, and the account may have paid in between.
        if ($account->hasCompletedPayment()) {
            $this->fail(
                $attempt,
                'already_paid',
                'Account already has a completed payment; re-arm auto payment before paying again.',
            );

            return;
        }

        $this->expiryAbortReason = null;

        // Never let the driver outlive the link: its budget is whatever is left of the checkout
        // window, capped at the normal timeout.
        $budgetSeconds = min(self::DRIVER_TIMEOUT_SECONDS, max(15, (int) $link->secondsUntilExpiry()));

        // Long enough to cover launch, navigation, the wallet submit and the driver's full OTP wait;
        // never longer than the checkout window, so a worker killed without running its finally
        // block still frees the wallet before the link it was holding could have been paid.
        $lockTtl = min(300, $budgetSeconds + 60);

        // Taken before the browser starts, not lazily at the wallet step. A run that has already
        // opened the checkout cannot be stood down cleanly: killing it leaves dg-epay serving
        // "SESSION ACTIVE ELSEWHERE" for that link, which isSessionLocked() treats as terminal, so
        // a wallet collision would permanently poison an otherwise payable link.
        $this->walletLock = PaymentWalletLock::acquire($account->auto_payment_wallet, $lockTtl);
        if ($this->walletLock === null) {
            $this->deferOrFail(
                $attempt,
                $link,
                'wallet_busy',
                'The payment wallet is busy with another account\'s checkout; deferred.',
            );

            return;
        }
        $this->lockedWallet = $account->auto_payment_wallet;

        if (! $this->acquireSlot($lockTtl)) {
            $this->releaseWalletLock();
            $this->deferOrFail($attempt, $link, 'queued', 'Automation concurrency limit reached; deferred.');

            return;
        }

        $ticket = PaymentOtpTicket::issue($account->auto_payment_wallet, $budgetSeconds);

        try {
            [$result, $log] = $this->runDriver($attempt, [
                'checkout_url' => $link->gateway_page_url,
                'method' => $account->auto_payment_method,
                'wallet' => $account->auto_payment_wallet,
                'pin' => $account->auto_payment_pin,
                'otp_url' => url('/api/payment-otp/'.$account->auto_payment_wallet),
                'otp_token' => $ticket,
                'started_at_ms' => (int) (microtime(true) * 1000),
                'timeout_ms' => $budgetSeconds * 1000,
            ]);

            $attempt->update(['driver_log' => $this->appendToDriverLog($attempt, $log)]);

            if ($this->expiryAbortReason !== null) {
                // Keep the stage the run actually reached so the checklist still shows how far it
                // got; the error text carries the fact that expiry is what stopped it.
                $this->fail($attempt, $attempt->stage ?? 'expired', $this->expiryAbortReason);
            } else {
                $this->recordResult($attempt, $result);
            }
        } catch (\Throwable $e) {
            $attempt->update(['driver_log' => $this->appendToDriverLog($attempt, (string) $this->lastDriverLog)]);
            // A process killed for expiry throws on its truncated output; report the expiry
            // rather than that noise, and only fall back to the real error otherwise.
            $this->expiryAbortReason !== null
                ? $this->fail($attempt, $attempt->stage ?? 'expired', $this->expiryAbortReason)
                : $this->fail($attempt, 'driver', $e->getMessage());
        } finally {
            PaymentOtpTicket::revoke($ticket);
            // A no-op when the run already reached the OTP stage and released early.
            $this->releaseWalletLock();
            $this->releaseSlot();
        }
    }

    /**
     * Stand a run down because a resource it needs is held by another run.
     *
     * Contention is not a failure, so the attempt goes back to pending with its retry refunded and
     * re-queues itself shortly. The bound on that loop is the checkout window rather than a counter:
     * once too little life is left to complete a payment, the run is failed with an honest reason
     * instead of leaving a pending row nothing will ever finish.
     */
    private function deferOrFail(
        PaymentAutomationAttempt $attempt,
        PaymentLink $link,
        string $stage,
        string $reason,
    ): void {
        $remaining = $link->secondsUntilExpiry();

        if ($remaining !== null && $remaining <= self::MIN_USEFUL_SECONDS) {
            $this->fail($attempt, $stage, $reason.' No usable time left in the checkout window.');

            return;
        }

        $attempt->update([
            'status' => PaymentAutomationAttempt::STATUS_PENDING,
            'attempts' => max(0, $attempt->attempts - 1),
            'stage' => $stage,
            'last_error' => $reason,
            'started_at' => null,
        ]);

        if ($remaining === null) {
            // No creation timestamp to bound a retry loop with; leave it to the sweep.
            return;
        }

        // A fresh dispatch, never $job->release(): AutoPaymentJob sets tries = 1 precisely because
        // it spends money, so a released job would die instantly as MaxAttemptsExceeded. The jitter
        // keeps workers that collided on one wallet from waking together and colliding again.
        AutoPaymentJob::dispatch($attempt->id)->delay(now()->addSeconds(random_int(8, 15)));
    }

    /**
     * Execute the Node driver, passing the job on stdin so credentials never appear in argv
     * (where any local process could read them from /proc).
     *
     * @param  array<string, mixed>  $job
     * @return array{0: array<string, mixed>, 1: string} Parsed result and the captured console log
     */
    protected function runDriver(PaymentAutomationAttempt $attempt, array $job): array
    {
        $this->lastDriverLog = null;

        $process = new Process(
            ['node', app_path(self::SCRIPT)],
            base_path(),
            [
                // Chrome derives its crashpad database path from HOME and dies on a CHECK when
                // that path is not writable; www-data's real home is root-owned. The Chrome build
                // itself is shared with the captcha solver rather than downloaded twice.
                'HOME' => storage_path('app/payment-automation'),
                'PUPPETEER_CACHE_DIR' => storage_path('app/puppeteer'),
            ],
            json_encode($job, JSON_UNESCAPED_SLASHES),
            self::DRIVER_TIMEOUT_SECONDS + 20,
        );

        try {
            // Stream the output rather than waiting for exit, so each stage marker the driver
            // emits lands on the attempt row while the run is still in progress. Without this the
            // log page sits on "running" with an empty checklist for the whole run.
            $process->run(function (string $type, string $buffer) use ($attempt, $process): void {
                $this->absorbStageMarkers($attempt, $buffer);
                $this->abortIfLinkExpired($attempt, $process);
            });
        } catch (ProcessTimedOutException $e) {
            $this->lastDriverLog = $this->composeLog($process->getOutput(), $process->getErrorOutput());
            throw new \RuntimeException('Payment driver timed out.');
        }

        $this->lastDriverLog = $this->composeLog($process->getOutput(), $process->getErrorOutput());

        return [
            $this->parseResult($process->getOutput(), $process->getErrorOutput()),
            $this->lastDriverLog,
        ];
    }

    /**
     * Stages past which the payment is already committed at the gateway.
     *
     * Once the confirm/callback phase is reached the money may have moved, so an expired link is
     * no longer a reason to kill the run — we must let it finish and capture the callback URL, or
     * the portal would lose the record of a payment that actually happened.
     */
    private const COMMITTED_STAGES = ['confirm', 'await_callback', 'callback'];

    /**
     * Kill a run whose checkout link has expired, unless it is already committed.
     *
     * Without this the driver would keep working a dead checkout for its full budget and hold a
     * concurrency slot the whole time.
     */
    private function abortIfLinkExpired(PaymentAutomationAttempt $attempt, Process $process): void
    {
        if ($this->expiryAbortReason !== null) {
            return;
        }

        if (in_array((string) $attempt->stage, self::COMMITTED_STAGES, true)) {
            return;
        }

        $link = $attempt->paymentLink;
        if ($link === null || ! $link->isExpired()) {
            return;
        }

        $this->expiryAbortReason = sprintf(
            'Checkout link expired after %d minutes; stopped at stage "%s" before reaching the callback.',
            PaymentLink::EXPIRY_MINUTES,
            $attempt->stage ?? 'unknown',
        );

        $process->stop(5);
    }

    /**
     * Write any stage markers found in a chunk of driver output onto the attempt.
     *
     * Only the last marker in the chunk matters — intermediate ones are already superseded — and
     * a failed write is swallowed, because losing a progress update must never abort a payment
     * that is otherwise going fine.
     */
    protected function absorbStageMarkers(PaymentAutomationAttempt $attempt, string $buffer): void
    {
        if (preg_match_all('/<<<STAGE>>>(.*?)<<<\/STAGE>>>/', $buffer, $matches) < 1) {
            return;
        }

        $stage = trim((string) end($matches[1]));
        if ($stage === '' || $stage === $attempt->stage) {
            return;
        }

        try {
            $attempt->forceFill(['stage' => $stage])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }

        // Everything from here on — PIN, confirm, callback — is per-checkout and shares nothing with
        // another account, so the wallet can be handed over while this run is still working.
        if ($stage === self::WALLET_RELEASE_STAGE) {
            $this->releaseWalletLock();
        }
    }

    /**
     * Hand the wallet back, draining whatever OTPs are left over from this run.
     *
     * Safe to call repeatedly and from either the stage stream or the run's finally block: the
     * property is cleared before the release, so a second call does nothing, and the cache lock's
     * own owner token means an already-expired-and-retaken lock cannot be released out from under
     * its new holder.
     */
    private function releaseWalletLock(): void
    {
        if ($this->walletLock === null) {
            return;
        }

        $lock = $this->walletLock;
        $wallet = (string) $this->lockedWallet;
        $this->walletLock = null;
        $this->lockedWallet = null;

        try {
            // Drain before releasing: the next run can start the moment the lock drops, and doing
            // this afterwards would race it for the codes it is meant to be protected from.
            $drained = OtpCode::drainMfsForPhones(PaymentWalletLock::candidatePhones($wallet));
            if ($drained > 0) {
                Log::info('Drained leftover wallet OTPs at lock release', [
                    'wallet' => PaymentWalletLock::normalize($wallet),
                    'rows' => $drained,
                ]);
            }

            $lock->release();
        } catch (\Throwable $e) {
            // This runs inside the driver's output callback, where a throw would kill a payment
            // that is otherwise going fine. A stuck lock expires on its own; a lost payment does not.
            report($e);
        }
    }

    /**
     * Human-readable console log for the attempt row.
     *
     * The JSON result payload is stripped out — it is already stored as structured columns, and
     * leaving it in would bury the narrative lines the log exists to show.
     */
    private function composeLog(string $stdout, string $stderr): string
    {
        $clean = preg_replace('/<<<JSON>>>.*?<<<\/JSON>>>/s', '', $stdout);
        // Stage markers are machine framing already reflected in the `stage` column.
        $clean = trim((string) preg_replace('/^<<<STAGE>>>.*?<<<\/STAGE>>>\R?/m', '', (string) $clean));

        if (trim($stderr) !== '') {
            $clean .= ($clean === '' ? '' : "\n")."[stderr]\n".trim($stderr);
        }

        // Bounded so a runaway driver cannot bloat the row; the tail holds the failure.
        return mb_substr($clean, -20000);
    }

    /**
     * Read the sentinel-delimited JSON result.
     *
     * The driver's own logging shares stdout, so the payload is framed rather than assumed to be
     * the whole stream — the same lesson the captcha solver harness learned.
     *
     * @return array<string, mixed>
     */
    private function parseResult(string $stdout, string $stderr): array
    {
        if (preg_match('/<<<JSON>>>(.*?)<<<\/JSON>>>/s', $stdout, $matches) !== 1) {
            Log::warning('Payment driver produced no result payload', [
                'stdout' => mb_substr($stdout, -2000),
                'stderr' => mb_substr($stderr, -2000),
            ]);

            throw new \RuntimeException('Driver returned no result payload.');
        }

        $decoded = json_decode(trim($matches[1]), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Driver returned malformed JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordResult(PaymentAutomationAttempt $attempt, array $result): void
    {
        $stage = is_string($result['stage'] ?? null) ? $result['stage'] : null;

        if (($result['ok'] ?? false) !== true) {
            $this->fail($attempt, $stage ?? 'driver', (string) ($result['error'] ?? 'Unknown driver failure.'));

            return;
        }

        $callbackUrl = is_string($result['callback_url'] ?? null) ? $result['callback_url'] : '';
        if ($callbackUrl === '') {
            $this->fail($attempt, $stage ?? 'callback', 'Driver reported success without a callback URL.');

            return;
        }

        // Hand off through the same router the browser extension uses, so the bot sees identical
        // state no matter who completed the checkout.
        $routed = $this->router->route($callbackUrl);
        if ($routed['result'] !== PaymentCallbackRouter::RESULT_OK) {
            $this->fail($attempt, 'callback', "Callback URL could not be routed ({$routed['result']}).");

            return;
        }

        // Written straight to the query builder, not through the model. Another process can have
        // touched this row mid-run — a queue-level failure handler does exactly that — and a model
        // update skips any column whose in-memory value already matches, so clearing last_error
        // silently did nothing and a succeeded attempt kept displaying a failure it never had.
        PaymentAutomationAttempt::where('id', $attempt->id)->update([
            'status' => PaymentAutomationAttempt::STATUS_SUCCEEDED,
            'stage' => $stage ?? 'callback',
            'callback_url' => $callbackUrl,
            'last_error' => null,
            'finished_at' => now(),
        ]);

        $attempt->refresh();
    }

    private function fail(PaymentAutomationAttempt $attempt, string $stage, string $error): void
    {
        $attempt->update([
            'status' => PaymentAutomationAttempt::STATUS_FAILED,
            'stage' => $stage,
            // Wide enough to keep the driver's page inventory (URL + form fields + visible text)
            // that a "field not found" failure carries; the column is TEXT, so this still fits.
            'last_error' => mb_substr($error, 0, 3000),
            'driver_log' => $this->appendAttemptHistory($attempt, $stage, $error),
            'finished_at' => now(),
        ]);
    }

    /**
     * Keep every attempt's failure, not just the last one.
     *
     * One row carries all three attempts, so an overwriting last_error loses the run that actually
     * explains the failure: attempt 1 fails on the real problem, takes the gateway's checkout
     * session with it, and attempts 2-3 then report nothing but "session already open".
     */
    private function appendAttemptHistory(PaymentAutomationAttempt $attempt, string $stage, string $error): string
    {
        $entry = sprintf(
            '[attempt %d @ %s] stage=%s: %s',
            $attempt->attempts,
            now()->toDateTimeString(),
            $stage,
            $error,
        );

        return $this->appendToDriverLog($attempt, $entry);
    }

    /**
     * Append a block to the attempt's accumulated driver log, keeping the tail bounded.
     */
    private function appendToDriverLog(PaymentAutomationAttempt $attempt, string $block): string
    {
        $existing = trim((string) $attempt->driver_log);
        $block = trim($block);

        if ($block === '') {
            return $existing;
        }

        return mb_substr(trim($existing === '' ? $block : $existing."\n".$block), -20000);
    }

    /**
     * Take one of the named browser slots.
     *
     * Named locks rather than a counter: an INCR/compare pair raced by several workers can
     * transiently exceed the cap, and a worker killed mid-run leaves the counter permanently one
     * too high — silently throttling all auto payment until the key's TTL wipes it, which in turn
     * zeroes the legitimate in-flight runs and lets the cap overshoot again. Each slot owning its
     * own lock and TTL makes both impossible.
     */
    private function acquireSlot(int $ttlSeconds): bool
    {
        $limit = $this->concurrencyLimit();

        for ($i = 1; $i <= $limit; $i++) {
            $lock = Cache::lock(self::SLOT_KEY_PREFIX.$i, $ttlSeconds);
            if ($lock->get()) {
                $this->slot = $lock;

                return true;
            }
        }

        return false;
    }

    private function releaseSlot(): void
    {
        if ($this->slot === null) {
            return;
        }

        $slot = $this->slot;
        $this->slot = null;

        try {
            $slot->release();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function concurrencyLimit(): int
    {
        $configured = (int) (Redis::get('payment:automation_concurrency') ?? 0);

        return $configured > 0 ? $configured : self::DEFAULT_CONCURRENCY;
    }
}
