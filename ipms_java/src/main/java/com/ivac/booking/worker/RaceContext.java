package com.ivac.booking.worker;

import com.ivac.booking.util.SessionExpiryValidator;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;
import java.util.function.Consumer;

public class RaceContext {

    // Phase completion signals
    private final AtomicBoolean otpVerified = new AtomicBoolean(false);
    private final AtomicBoolean slotReserved = new AtomicBoolean(false);
    private final AtomicBoolean paymentInitiated = new AtomicBoolean(false);
    private final AtomicBoolean paymentDone = new AtomicBoolean(false);

    // Latch for slot threads to await OTP verification (wakes all waiters immediately on signal)
    private final CountDownLatch otpVerifiedLatch = new CountDownLatch(1);

    // Latch that fires the moment any OTP thread begins the verify HTTP call
    private final CountDownLatch otpVerifyStartedLatch = new CountDownLatch(1);

    // Gate for one-time account setup (PDF upload + booking config). When required, slot
    // reserve must wait until setup completes — IVAC rejects reserve until PDFs are uploaded
    // and booking config is posted, and both need an OTP-verified session.
    private volatile boolean setupRequired = false;
    private final CountDownLatch setupCompleteLatch = new CountDownLatch(1);

    // SMS-channel forgot-password pair (pre-window sendOtp(PHONE)).
    private volatile String smsRequestId;
    private final AtomicReference<String> smsOtp = new AtomicReference<>(null);
    private volatile long smsServerTimeMs = 0L;

    // Email-channel forgot-password pair (pre-window sendOtp(EMAIL) OR in-window parallel email sendOtp).
    private volatile String emailRequestId;
    private final AtomicReference<String> emailOtp = new AtomicReference<>(null);
    private volatile long emailServerTimeMs = 0L;

    // Sign-in pair — SMS channel; OTP issued by IVAC as a side-effect of /auth/signin.
    private volatile String signinRequestId;
    private volatile String signinOtp = null;
    private volatile long signinServerTimeMs = 0L;

    // Server time from OTP verify — used for smart 401 detection in slot phase
    private volatile String otpVerifiedServerTime;

    // Timestamp when slot was reserved — used for payment TTL guard
    private volatile long slotReservedAtMs = 0L;

    // reservationId from the winning slot reserve — equals the callback tran_id (post-payment flow)
    private volatile String reservationId;

    // Wall-clock anchor for the race — used as the speculative slot-probe deadline base
    private volatile long raceStartedAtMs = 0L;

    // Shared 429 backoff — when any slot task gets 429, all slot tasks must wait until this timestamp
    private volatile long slot429BlockedUntilMs = 0L;

    // OTP 429 backoff — when OTP verify gets a non-fatal 429, the tick loop waits until this timestamp
    private volatile long otp429BlockedUntilMs = 0L;

    // Consecutive edge/WAF-throttle 429s (no server-stated wait) per phase — drives the
    // escalating 4s/8s/12s backoff table and resets on any non-edge-throttle result.
    private final AtomicInteger otpEdgeThrottleCount = new AtomicInteger(0);
    private final AtomicInteger slotEdgeThrottleCount = new AtomicInteger(0);

    // Session expiry — set after signin, shared to all threads
    private volatile SessionExpiryValidator sessionExpiryValidator;

    // Access token — central store; updating here propagates to all registered HTTP clients
    private final AtomicReference<String> accessToken = new AtomicReference<>(null);
    private final List<Consumer<String>> tokenListeners = new ArrayList<>();

    // Final result — completed by first payment thread that gets a URL
    private final CompletableFuture<String> paymentResult = new CompletableFuture<>();

    // Cancellers run once, by the thread that wins the slot-reserved CAS, to abort any OTP
    // verify HTTP calls still in flight — a reservation proves OTP is already verified.
    private final List<Runnable> otpVerifyCancellers = new CopyOnWriteArrayList<>();

    /**
     * Marks OTP as verified. Only the first caller wins (CAS).
     *
     * @param serverTime server timestamp from verify response (for smart 401 detection)
     * @return true if this caller was the first to verify (won the CAS)
     */
    public boolean setOtpVerified(String serverTime) {
        if (otpVerified.compareAndSet(false, true)) {
            this.otpVerifiedServerTime = serverTime;
            otpVerifiedLatch.countDown();
            return true;
        }
        return false;
    }

    public boolean isOtpVerified() {
        return otpVerified.get();
    }

    public String getOtpVerifiedServerTime() {
        return otpVerifiedServerTime;
    }

    /**
     * Blocks until OTP is verified or the wall-clock {@code deadlineMs} passes.
     * Returns immediately if OTP is already verified or the deadline has already passed.
     */
    public void awaitOtpVerifiedUntil(long deadlineMs) throws InterruptedException {
        if (otpVerified.get()) {
            return;
        }

        long remaining = deadlineMs - System.currentTimeMillis();
        if (remaining <= 0) {
            return;
        }

        otpVerifiedLatch.await(remaining, TimeUnit.MILLISECONDS);
    }

    /**
     * Signals that an OTP thread has received an OTP code and is about to call verifyOtp.
     * The speculative slot task awaits this before probing — ensures we only hit the slot
     * endpoint once there is a real OTP in-flight, not on an arbitrary time deadline.
     * Safe to call multiple times; only the first call counts (latch is one-shot).
     */
    public void markOtpVerifyStarted() {
        otpVerifyStartedLatch.countDown();
    }

    /**
     * Blocks until an OTP thread signals verify has started, or {@code timeoutMs} elapses.
     */
    public void awaitOtpVerifyStarted(long timeoutMs) throws InterruptedException {
        otpVerifyStartedLatch.await(timeoutMs, TimeUnit.MILLISECONDS);
    }

    /** Returns true if an OTP thread has signalled that it is about to call verifyOtp. */
    public boolean isOtpVerifyStarted() {
        return otpVerifyStartedLatch.getCount() == 0;
    }

    /**
     * Marks whether this cycle must run one-time account setup before slot reserve. When not
     * required, the setup gate is released immediately so slot threads never wait on it.
     */
    public void setSetupRequired(boolean required) {
        this.setupRequired = required;
        if (!required) {
            setupCompleteLatch.countDown();
        }
    }

    /** True if this cycle still needs PDF upload + booking config before slot reserve. */
    public boolean isSetupRequired() {
        return setupRequired;
    }

    /** Signals that one-time account setup has finished (success or give-up), unblocking slots. */
    public void markSetupComplete() {
        setupCompleteLatch.countDown();
    }

    /** Blocks until one-time setup completes or {@code timeoutMs} elapses. */
    public void awaitSetupComplete(long timeoutMs) throws InterruptedException {
        setupCompleteLatch.await(timeoutMs, TimeUnit.MILLISECONDS);
    }

    /** Records the wall-clock time when the race started. Called once by RaceOrchestrator.execute(). */
    public void markRaceStarted() {
        if (raceStartedAtMs == 0L) {
            raceStartedAtMs = System.currentTimeMillis();
        }
    }

    public long getRaceStartedAtMs() {
        return raceStartedAtMs;
    }

    /**
     * Marks slot as reserved. Only the first caller wins (CAS).
     * Slot success implies OTP verified — sets otpVerified as side effect.
     *
     * @return true if this caller won the race
     */
    public boolean setSlotReserved() {
        if (slotReserved.compareAndSet(false, true)) {
            slotReservedAtMs = System.currentTimeMillis();

            // Slot success implies OTP verified on the server
            if (otpVerified.compareAndSet(false, true)) {
                otpVerifiedLatch.countDown();
            }

            // Reservation proves OTP is verified — abort any OTP verify calls still in flight.
            cancelInFlightOtpVerify();

            return true;
        }

        return false;
    }

    /**
     * Registers a canceller invoked once when the slot-reserved CAS is won. Each canceller aborts
     * the in-flight OTP verify calls on one OTP client. Registered before the race starts.
     */
    public void registerOtpVerifyCanceller(Runnable canceller) {
        otpVerifyCancellers.add(canceller);
    }

    private void cancelInFlightOtpVerify() {
        for (Runnable canceller : otpVerifyCancellers) {
            try {
                canceller.run();
            } catch (RuntimeException ignored) {
            }
        }
    }

    public boolean isSlotReserved() {
        return slotReserved.get();
    }

    public long getSlotReservedAtMs() {
        return slotReservedAtMs;
    }

    public String getReservationId() {
        return reservationId;
    }

    public void setReservationId(String id) {
        this.reservationId = id;
    }

    /**
     * CAS gate: only the first caller gets true and should fire the IVAC payment HTTP call.
     * All other slot threads must skip initiatePayment() entirely.
     */
    public boolean tryClaimPayment() {
        return paymentInitiated.compareAndSet(false, true);
    }

    /**
     * Completes the payment result with a gateway URL.
     * Both threads may call this — only the first one completes the future.
     */
    public void completePayment(String gatewayUrl) {
        paymentResult.complete(gatewayUrl);
        paymentDone.set(true);
    }

    public boolean isPaymentDone() {
        return paymentDone.get();
    }

    public CompletableFuture<String> getPaymentResult() {
        return paymentResult;
    }

    public void signalRestart(Exception reason) {
        paymentResult.completeExceptionally(reason);
    }

    // ─── SMS forgot-password pair ──────────────────────────────────────────────

    public String getSmsRequestId() { return smsRequestId; }
    public void   setSmsRequestId(String id) { this.smsRequestId = id; }
    public String getSmsOtp() { return smsOtp.get(); }
    public void   setSmsOtp(String otp) { smsOtp.set(otp); }
    public long   getSmsServerTimeMs() { return smsServerTimeMs; }
    public void   setSmsServerTimeMs(long ms) { this.smsServerTimeMs = ms; }

    // ─── Email forgot-password pair ────────────────────────────────────────────

    public String getEmailRequestId() { return emailRequestId; }
    public void   setEmailRequestId(String id) { this.emailRequestId = id; }
    public String getEmailOtp() { return emailOtp.get(); }
    public void   setEmailOtp(String otp) { emailOtp.set(otp); }
    public long   getEmailServerTimeMs() { return emailServerTimeMs; }
    public void   setEmailServerTimeMs(long ms) { this.emailServerTimeMs = ms; }

    // ─── Sign-in pair (SMS channel) ────────────────────────────────────────────

    public String getSigninRequestId() { return signinRequestId; }
    public void   setSigninRequestId(String id) { this.signinRequestId = id; }
    public String getSigninOtp() { return signinOtp; }
    public void   setSigninOtp(String otp) { this.signinOtp = otp; }
    public long   getSigninServerTimeMs() { return signinServerTimeMs; }
    public void   setSigninServerTimeMs(long ms) { this.signinServerTimeMs = ms; }

    /**
     * Returns true if at least one OTP pair (sms-fp, email-fp, signin) is ready to fire verify.
     */
    public boolean hasAnyOtpReady() {
        return (smsRequestId != null && smsOtp.get() != null)
            || (emailRequestId != null && emailOtp.get() != null)
            || (signinRequestId != null && signinOtp != null);
    }

    public SessionExpiryValidator getSessionExpiryValidator() {
        return sessionExpiryValidator;
    }

    public void setSessionExpiryValidator(SessionExpiryValidator validator) {
        this.sessionExpiryValidator = validator;
    }

    /**
     * Registers a listener that will be called whenever the access token is updated.
     * Call this once per HTTP client before the race starts.
     */
    public void registerTokenListener(Consumer<String> listener) {
        tokenListeners.add(listener);
    }

    /**
     * Updates the central access token and propagates it to all registered HTTP clients.
     */
    public void setAccessToken(String token) {
        accessToken.set(token);
        for (Consumer<String> listener : tokenListeners) {
            listener.accept(token);
        }
    }

    public String getAccessToken() {
        return accessToken.get();
    }

    // Status-change log dedup — shared across concurrent slot tasks / OTP burst drivers
    private final AtomicReference<String> lastSlotLog = new AtomicReference<>(null);
    private final AtomicReference<String> lastOtpLog  = new AtomicReference<>(null);

    /** Returns true if {@code key} differs from the last slot log — caller should emit the log. */
    public boolean tryLogSlot(String key) {
        return !key.equals(lastSlotLog.getAndSet(key));
    }

    /** Returns true if {@code key} differs from the last OTP log — caller should emit the log. */
    public boolean tryLogOtp(String key) {
        return !key.equals(lastOtpLog.getAndSet(key));
    }

    /**
     * Records a 429 event — all slot tasks must not retry before the returned deadline.
     *
     * @param backoffMs how long to block all slot tasks (from now)
     */
    public void record429Backoff(long backoffMs) {
        slot429BlockedUntilMs = System.currentTimeMillis() + backoffMs;
    }

    /**
     * Returns how many milliseconds remain in the shared slot 429 backoff, or 0 if clear.
     */
    public long slot429RemainingMs() {
        return Math.max(0L, slot429BlockedUntilMs - System.currentTimeMillis());
    }

    /**
     * Records an OTP 429 rate-limit — the OTP tick loop must not retry until this expires.
     *
     * @param backoffMs how long to pause OTP verify ticks (from now)
     */
    public void recordOtp429Backoff(long backoffMs) {
        otp429BlockedUntilMs = System.currentTimeMillis() + backoffMs;
    }

    /**
     * Returns how many milliseconds remain in the OTP 429 backoff, or 0 if clear.
     */
    public long otp429RemainingMs() {
        return Math.max(0L, otp429BlockedUntilMs - System.currentTimeMillis());
    }

    /** Increments and returns the OTP verify consecutive edge-throttle count. */
    public int nextOtpEdgeThrottleCount() {
        return otpEdgeThrottleCount.incrementAndGet();
    }

    /** Clears the OTP verify edge-throttle streak — call on any non-edge-throttle result. */
    public void resetOtpEdgeThrottleCount() {
        otpEdgeThrottleCount.set(0);
    }

    /** Increments and returns the slot reserve consecutive edge-throttle count. */
    public int nextSlotEdgeThrottleCount() {
        return slotEdgeThrottleCount.incrementAndGet();
    }

    /** Clears the slot reserve edge-throttle streak — call on any non-edge-throttle result. */
    public void resetSlotEdgeThrottleCount() {
        slotEdgeThrottleCount.set(0);
    }

    /** Returns true if the race is effectively over — no point continuing. */
    public boolean isRaceOver() {
        return paymentDone.get() || paymentResult.isDone() || slotReserved.get() || Thread.currentThread().isInterrupted();
    }
}
