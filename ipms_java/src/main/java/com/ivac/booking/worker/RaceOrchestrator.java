package com.ivac.booking.worker;

import com.ivac.booking.Constants;
import com.ivac.booking.PortalClient;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.exception.ConfigNotTodayException;
import com.ivac.booking.exception.RestartSignInException;
import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.exception.RateLimitFatalException;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.model.domain.OtpVerifyResult;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.networking.PortalLogShipper;
import com.ivac.booking.networking.EdgeThrottleFallbackManager;
import com.ivac.booking.service.PortalOtpClient;
import com.ivac.booking.service.captcha.CaptchaService;
import com.ivac.booking.service.captcha.SharedSlotCaptchaService;
import com.ivac.booking.service.otp.OtpService;
import com.ivac.booking.service.otp.OtpServiceImpl;
import com.ivac.booking.service.payment.IPaymentService;
import com.ivac.booking.service.slot.SlotReservationServiceImpl;
import com.ivac.booking.service.slot.ShotOutcome;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.RetryUtil;

import java.io.IOException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.IdentityHashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicReference;

public class RaceOrchestrator {

    /**
     * Internal name + channel for one OTP pair. Used by tick loop to enumerate ready pairs
     * and route stale-OTP resends to the right channel.
     */
    private enum Pair {
        SMS_FP("sms-fp", "sms"),
        EMAIL_FP("email-fp", "email"),
        SIGNIN("signin", "sms");

        final String name;
        final String channel;

        Pair(String name, String channel) {
            this.name = name;
            this.channel = channel;
        }
    }

    private final long otpTimeoutMs;

    private final String phone;

    private final List<OtpService> allOtpServices;
    private final List<IvacHttpClient> allSlotClients;

    private final AppConfig appConfig;
    private final RaceContext context;
    private final AccountConfig account;
    private final CaptchaService captchaService;
    private final OtpService primaryOtpService;
    private final IPaymentService paymentService;
    private final PortalClient portalClient;
    private final EdgeThrottleFallbackManager edgeFallback;

    // Built lazily the first time OTP verify sees an edge-throttle 429, then one per distinct
    // fallback client the manager hands out (IPv6 hops, then the proxy), so a service is reused
    // across ticks instead of rebuilt. Guarded by getOrCreateFallbackOtpService's monitor.
    private final Map<IvacHttpClient, OtpService> fallbackOtpServices = new IdentityHashMap<>();

    public RaceOrchestrator(String phone, RaceContext context, OtpService primaryOtpService,
                            List<OtpService> allOtpServices, List<IvacHttpClient> allSlotClients,
                            CaptchaService captchaService, AccountConfig account, AppConfig appConfig,
                            IPaymentService paymentService, long otpTimeoutMs, PortalClient portalClient,
                            EdgeThrottleFallbackManager edgeFallback) {
        this.phone             = phone;
        this.context           = context;
        this.primaryOtpService = primaryOtpService;
        this.allOtpServices    = allOtpServices;
        this.allSlotClients    = allSlotClients;
        this.captchaService    = captchaService;
        this.account           = account;
        this.appConfig         = appConfig;
        this.paymentService    = paymentService;
        this.otpTimeoutMs      = otpTimeoutMs;
        this.portalClient      = portalClient;
        this.edgeFallback      = edgeFallback;
    }

    public String execute() throws RestartSignInException, ConfigNotTodayException, AuthenticationFatalException, InterruptedException {
        context.markRaceStarted();

        // When a slot is reserved (OTP is implicitly verified), abort any OTP verify calls still
        // in flight on each OTP client instead of letting their virtual threads block on the socket.
        for (OtpService svc : allOtpServices) {
            context.registerOtpVerifyCanceller(() -> {
                int cancelled = svc.cancelInFlightVerify();
                if (cancelled > 0) {
                    ConsoleLogger.log(phone, "Slot reserved — aborted " + cancelled
                        + " in-flight OTP verify call(s)", "OK");
                }
            });
        }

        // 4 coordinator tasks: SMS poll + EMAIL poll + OTP tick + slot tick.
        ExecutorService executor = Executors.newFixedThreadPool(6);

        try {
            startOtpVerifyPhase(executor);
            startSlotTickLoop(executor);

            long raceTimeoutMs = Math.max(1_000L, context.getSessionExpiryValidator().getTimeUntilExpirySeconds() * 1000L);

            try {
                return context.getPaymentResult().get(raceTimeoutMs, TimeUnit.MILLISECONDS);
            } catch (TimeoutException e) {

                if (context.isSlotReserved() && !context.isPaymentDone()) {
                    long reservedAtMs  = context.getSlotReservedAtMs();
                    long remainingMs   = (reservedAtMs + Constants.RESERVATION_TTL_MS) - System.currentTimeMillis();

                    if (remainingMs > 0) {
                        ConsoleLogger.log(phone,
                            "Slot reserved — race timeout reached but payment pending; waiting "
                                + (remainingMs / 1000) + "s more", "WAIT");
                        return context.getPaymentResult().get(remainingMs, TimeUnit.MILLISECONDS);
                    }

                    throw new RestartSignInException("Reservation TTL expired before payment completed");
                }

                throw new RestartSignInException("Race timed out after " + (raceTimeoutMs / 1000) + "s (JWT expired)");
            }

        } catch (TimeoutException e) {
            throw new RestartSignInException("Payment timed out after reservation TTL");
        } catch (ExecutionException e) {
            Throwable cause = e.getCause();

            if (cause instanceof AuthenticationFatalException) throw (AuthenticationFatalException) cause;
            if (cause instanceof ConfigNotTodayException)      throw (ConfigNotTodayException) cause;
            if (cause instanceof RestartSignInException)       throw (RestartSignInException) cause;

            String msg = cause != null ? cause.getMessage() : e.getMessage();
            throw new RestartSignInException("Race failed: " + msg);
        } finally {
            if (context.isSlotReserved() && !context.isPaymentDone()) {
                executor.shutdown();
            } else {
                executor.shutdownNow();
            }
        }
    }

    // -------------------------------------------------------------------------
    // OTP phase
    // -------------------------------------------------------------------------

    private void startOtpVerifyPhase(ExecutorService executor) {
        // Skip OTP phase entirely when pre-marked as verified (JWT reuse with stored verification).
        if (context.isOtpVerified()) {
            return;
        }

        // Background poller per channel — fills in OTPs for pairs that have requestId+serverTime
        // but no OTP yet (e.g. signin pair right after sign-in, or any pair that lost its OTP
        // race during the pre-window dual-channel poll).
        executor.submit(() -> backgroundPoll(executor, "sms"));
        executor.submit(() -> backgroundPoll(executor, "email"));

        startOtpTickLoop(executor);
    }

    private void backgroundPoll(ExecutorService executor, String channel) {
        try {
            long deadline = System.currentTimeMillis() + otpTimeoutMs;

            while (!Thread.currentThread().isInterrupted()
                    && !context.isOtpVerified()
                    && !context.isRaceOver()
                    && System.currentTimeMillis() < deadline) {

                long since = earliestNeededSinceMs(channel);
                if (since < 0) {
                    Thread.sleep(500);
                    continue;
                }

                PortalOtpClient.OtpResult result = primaryOtpService.pollPortal(channel, since, 5_000);
                if (result == null) {
                    continue;
                }

                ConsoleLogger.log(phone, "Portal OTP captured (channel=" + channel + "): " + result.otpCode, "OK");
                applyOtpToWaitingPairs(channel, result.otpCode, result.fetchedAtMs);
            }
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    /**
     * Returns the smallest `sinceMs` across all pairs on this channel that have a requestId and
     * serverTime but no OTP yet. Returns -1 if no pair on this channel currently needs an OTP.
     */
    private long earliestNeededSinceMs(String channel) {
        long smallest = Long.MAX_VALUE;
        boolean any = false;
        if ("sms".equals(channel)) {
            if (context.getSmsRequestId() != null && context.getSmsServerTimeMs() > 0 && context.getSmsOtp() == null) {
                smallest = Math.min(smallest, context.getSmsServerTimeMs());
                any = true;
            }
            if (context.getSigninRequestId() != null && context.getSigninServerTimeMs() > 0 && context.getSigninOtp() == null) {
                smallest = Math.min(smallest, context.getSigninServerTimeMs());
                any = true;
            }
        } else if ("email".equals(channel)) {
            if (context.getEmailRequestId() != null && context.getEmailServerTimeMs() > 0 && context.getEmailOtp() == null) {
                smallest = Math.min(smallest, context.getEmailServerTimeMs());
                any = true;
            }
        }
        return any ? smallest : -1;
    }

    /**
     * Applies a freshly-captured OTP to whichever pair(s) on this channel are still waiting and
     * whose serverTime predates the row's fetched_at. Each pair only gets the first matching OTP —
     * if two pairs share a channel (sms-fp + signin), the older pair (smaller serverTime) wins
     * the first OTP and the next poll attempt picks up the second one for the other pair.
     */
    private void applyOtpToWaitingPairs(String channel, String otp, long fetchedAtMs) {
        if ("sms".equals(channel)) {
            // Prefer the oldest waiting pair (FIFO between sms-fp and signin).
            long smsSince = (context.getSmsRequestId() != null && context.getSmsOtp() == null)
                ? context.getSmsServerTimeMs() : Long.MAX_VALUE;
            long signinSince = (context.getSigninRequestId() != null && context.getSigninOtp() == null)
                ? context.getSigninServerTimeMs() : Long.MAX_VALUE;

            if (smsSince <= signinSince && smsSince != Long.MAX_VALUE
                    && fetchedAtMs + 2_000 >= smsSince) {
                context.setSmsOtp(otp);
            } else if (signinSince != Long.MAX_VALUE && fetchedAtMs + 2_000 >= signinSince) {
                context.setSigninOtp(otp);
            }
        } else if ("email".equals(channel)) {
            if (context.getEmailRequestId() != null && context.getEmailOtp() == null) {
                context.setEmailOtp(otp);
            }
        }
    }

    private void startOtpTickLoop(ExecutorService executor) {
        int numServices = allOtpServices.size();
        int otpTickShots = account.getOtpTickShots();
        long otpTickIntervalMs = account.getOtpTickIntervalMs();
        // Mutable so an edge-throttle 429 can fold the edge-throttle fallback service into rotation
        // mid-race; the tick schedule is rebuilt from this each tick (cheap — small lists).
        AtomicReference<List<OtpService>> activeOtpServicesRef = new AtomicReference<>(allOtpServices);

        executor.submit(() -> {
            try {
                // Wait until at least one pair becomes ready (OTP loaded). If no OTP arrives
                // before otpTimeoutMs and JWT is still valid, keep waiting — only restart
                // when JWT is genuinely about to expire.
                while (!context.hasAnyOtpReady() && !context.isRaceOver() && !context.isOtpVerified()) {
                    long waitDeadline = System.currentTimeMillis() + otpTimeoutMs + 5_000L;
                    while (!context.hasAnyOtpReady() && !context.isRaceOver()
                            && System.currentTimeMillis() < waitDeadline) {
                        Thread.sleep(100);
                    }

                    if (context.isRaceOver() || context.hasAnyOtpReady()) {
                        break;
                    }

                    if (context.getSessionExpiryValidator().isSessionExpired()) {
                        ConsoleLogger.log(phone, "No OTP pair ready + JWT expired — restarting sign-in", "WARN");
                        context.signalRestart(new RestartSignInException("No OTP available after timeout (JWT expired)"));
                        return;
                    }

                    long jwtRemainingSec = context.getSessionExpiryValidator().getTimeUntilExpirySeconds();
                    ConsoleLogger.log(phone, "No OTP after " + (otpTimeoutMs / 1000)
                        + "s — awaiting signin OTP (JWT valid for " + jwtRemainingSec + "s)", "WARN");
                    Thread.sleep(5_000L);
                }

                if (context.isRaceOver()) {
                    return;
                }

                context.markOtpVerifyStarted();

                int tickIndex = 0;

                while (!context.isOtpVerified()
                    && !context.isRaceOver()
                    && !Thread.currentThread().isInterrupted()) {

                    long tickStart = System.currentTimeMillis();

                    long otpBackoffMs = context.otp429RemainingMs();
                    if (otpBackoffMs > 0) {
                        if (context.tryLogOtp("429_backoff")) {
                            ConsoleLogger.log(phone, "OTP tick waiting " + (otpBackoffMs / 1000)
                                + "s (429 backoff)", "WAIT");
                        }
                        Thread.sleep(Math.min(otpBackoffMs, otpTickIntervalMs));
                        continue;
                    }

                    // Snapshot ready pairs for this tick.
                    List<PairSnapshot> readyPairs = snapshotReadyPairs();
                    if (readyPairs.isEmpty()) {
                        if (context.getSessionExpiryValidator().isSessionExpired()) {
                            ConsoleLogger.log(phone, "No OTP pairs ready + JWT expired — restarting sign-in", "WARN");
                            context.signalRestart(new RestartSignInException("No OTP pairs after JWT expiry"));
                            return;
                        }
                        Thread.sleep(otpTickIntervalMs);
                        continue;
                    }

                    List<OtpService> activeServices = activeOtpServicesRef.get();
                    List<List<OtpService>> tickSchedule = buildTickSchedule(activeServices, otpTickShots);
                    int numTicks = tickSchedule.size();
                    List<OtpService> tick = tickSchedule.get(tickIndex % numTicks);
                    tickIndex++;

                    ConsoleLogger.log(phone, "OTP tick " + tickIndex + "/" + numTicks + ": "
                        + otpTickShots + "/" + otpTickShots + " verify requests firing (pairs="
                        + readyPairs.stream().map(p -> p.pair.name).toList() + ")", "AUTH");

                    for (int slotIdx = 0; slotIdx < tick.size(); slotIdx++) {
                        if (context.isOtpVerified() || context.isRaceOver()) {
                            break;
                        }

                        final OtpService service = tick.get(slotIdx);
                        final PairSnapshot ps = readyPairs.get(slotIdx % readyPairs.size());
                        final String label = otpLabel(slotIdx % numServices, numServices, ps.pair);

                        Thread.ofVirtual().start(() -> {
                            try {
                                OtpVerifyResult result = service.verifyOtp(ps.requestId, ps.otpCode, ps.pair.channel);

                                if (result.isVerified()) {
                                    if (context.setOtpVerified(result.getServerTime())) {
                                        ConsoleLogger.log(phone, label + " OTP verified (tick)", "OK");
                                        // Mirror onto the account config so a restart cycle reusing
                                        // this JWT skips the OTP phase and goes straight to slot.
                                        account.setOtpVerified(true);
                                        PortalLogShipper.muteOtpVerifyLogs(phone);
                                        if (portalClient != null) {
                                            portalClient.storeOtpVerified(phone, ps.otpCode, result.getServerTime());
                                        }
                                    }
                                    return;
                                }

                                switch (result.getStatus()) {
                                    case EXPIRED       -> {
                                        context.resetOtpEdgeThrottleCount();
                                        handleStaleOtp(label, "expired", ps.pair);
                                    }
                                    case CODE_MISMATCH -> {
                                        context.resetOtpEdgeThrottleCount();
                                        handleStaleOtp(label, "mismatch", ps.pair);
                                    }
                                    case RATE_LIMITED  -> {
                                        if (result.isEdgeThrottle()) {
                                            int n = context.nextOtpEdgeThrottleCount();
                                            long backoff = RetryUtil.base429BackoffMs(n);
                                            context.recordOtp429Backoff(backoff);
                                            OtpService fallback = getOrCreateFallbackOtpService();
                                            if (fallback != null) {
                                                activeOtpServicesRef.updateAndGet(list -> withFallbackOtpService(list, fallback));
                                            }
                                            if (context.tryLogOtp("edge_429")) {
                                                ConsoleLogger.log(phone, label + " OTP verify edge 429 — escalating backoff "
                                                    + (backoff / 1000) + "s, folding fallback client into rotation", "WAIT");
                                            }
                                        } else {
                                            context.resetOtpEdgeThrottleCount();
                                            context.recordOtp429Backoff(result.getBackoffMs());
                                        }
                                    }
                                    case FAILED        -> {
                                        context.resetOtpEdgeThrottleCount();
                                        if (context.tryLogOtp("failed-" + ps.pair.name)) {
                                            ConsoleLogger.log(phone, label + " OTP verify failed — retrying", "RETRY");
                                        }
                                    }
                                    default            -> {
                                        context.resetOtpEdgeThrottleCount();
                                        if (context.tryLogOtp("unknown-" + ps.pair.name)) {
                                            ConsoleLogger.log(phone, label + " OTP verify unknown status — retrying", "RETRY");
                                        }
                                    }
                                }

                            } catch (RateLimitFatalException e) {
                                if (context.isOtpVerified()) {
                                    ConsoleLogger.log(phone, "OTP verify 429 ignored — already verified by another thread", "WARN");
                                } else {
                                    ConsoleLogger.log(phone, e.getMessage(), "FAIL");
                                    context.signalRestart(new AuthenticationFatalException(e.getMessage()));
                                }
                            } catch (IOException e) {
                                if (!context.isRaceOver() && context.tryLogOtp("io_" + e.getMessage())) {
                                    ConsoleLogger.log(phone, "OTP verify IO error: " + e.getMessage(), "RETRY");
                                }
                            }
                        });
                    }

                    long elapsed = System.currentTimeMillis() - tickStart;
                    long sleepMs = otpTickIntervalMs - elapsed;
                    if (sleepMs > 0 && !context.isOtpVerified() && !context.isRaceOver()) {
                        Thread.sleep(sleepMs);
                    }
                }

            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        });
    }

    private static final class PairSnapshot {
        final Pair pair;
        final String requestId;
        final String otpCode;

        PairSnapshot(Pair pair, String requestId, String otpCode) {
            this.pair = pair;
            this.requestId = requestId;
            this.otpCode = otpCode;
        }
    }

    private List<PairSnapshot> snapshotReadyPairs() {
        List<PairSnapshot> out = new ArrayList<>(3);
        if (context.getSmsRequestId() != null && context.getSmsOtp() != null) {
            out.add(new PairSnapshot(Pair.SMS_FP, context.getSmsRequestId(), context.getSmsOtp()));
        }
        if (context.getEmailRequestId() != null && context.getEmailOtp() != null) {
            out.add(new PairSnapshot(Pair.EMAIL_FP, context.getEmailRequestId(), context.getEmailOtp()));
        }
        if (context.getSigninRequestId() != null && context.getSigninOtp() != null) {
            out.add(new PairSnapshot(Pair.SIGNIN, context.getSigninRequestId(), context.getSigninOtp()));
        }
        return out;
    }

    /**
     * Handles a stale OTP (expired or mismatched). Clears the pair so the tick loop stops
     * retrying it. Triggers an immediate restart if JWT is already expired; otherwise waits
     * for the next tick cycle to detect expiry or receive a new OTP via the background poller.
     */
    private void handleStaleOtp(String label, String reason, Pair pair) {
        if (context.isOtpVerified() || context.isRaceOver()) {
            return;
        }

        if (context.getSessionExpiryValidator().isSessionExpired()) {
            ConsoleLogger.log(phone, label + " OTP " + reason + " + JWT expired — full restart", "RETRY");
            context.signalRestart(new RestartSignInException("OTP " + reason + " + JWT expired"));
            return;
        }

        clearPairOtp(pair);
        long jwtRemainingSec = context.getSessionExpiryValidator().getTimeUntilExpirySeconds();
        if (context.tryLogOtp("stale_" + pair.name)) {
            ConsoleLogger.log(phone, label + " OTP " + reason
                + " — clearing stale pair; awaiting signin OTP or JWT expiry (JWT valid for "
                + jwtRemainingSec + "s)", "WARN");
        }
    }

    /** Clears the OTP for a pair so the tick loop stops retrying a stale code. */
    private void clearPairOtp(Pair pair) {
        switch (pair) {
            case SMS_FP   -> context.setSmsOtp(null);
            case EMAIL_FP -> context.setEmailOtp(null);
            case SIGNIN   -> context.setSigninOtp(null);
        }
    }

    // -------------------------------------------------------------------------
    // Slot phase
    // -------------------------------------------------------------------------

    private void startSlotTickLoop(ExecutorService executor) {
        CompletableFuture<CaptchaToken> initialCaptchaFuture = CompletableFuture.supplyAsync(() -> {
            try {
                return captchaService.fetch();
            } catch (Exception e) {
                return null;
            }
        });
        SharedSlotCaptchaService sharedCaptchaService = new SharedSlotCaptchaService(
            captchaService, appConfig.getCaptchaShelfLifeMs(), initialCaptchaFuture);

        int slotTickShots = account.getSlotTickShots();
        long slotTickIntervalMs = account.getSlotTickIntervalMs();
        // Mutable so an edge-throttle 429 can fold the edge-throttle fallback client into rotation
        // mid-race; the tick schedule is rebuilt from this each tick (cheap — small lists).
        AtomicReference<List<IvacHttpClient>> activeSlotClientsRef = new AtomicReference<>(allSlotClients);

        ConsoleLogger.log(phone, "Slot tick: " + allSlotClients.size() + " client(s) → "
            + slotTickShots + " shots/" + slotTickIntervalMs + "ms", "RACE");

        executor.submit(() -> {
            try {
                if (context.isSetupRequired()) {
                    // First-ever window for this account: hold every reserve request until the
                    // one-time PDF upload + booking config completes. Setup runs only after OTP
                    // verifies, so waiting on setup-complete also implies OTP verify has started.
                    // Bounded by JWT validity — past expiry the cycle re-auths anyway.
                    long setupGateTimeoutMs = Math.max(1_000L,
                        context.getSessionExpiryValidator().getTimeUntilExpirySeconds() * 1000L);
                    ConsoleLogger.log(phone, "Slot reserve holding for one-time account setup "
                        + "(PDF upload + booking config)", "WAIT");
                    context.awaitSetupComplete(setupGateTimeoutMs);
                } else {
                    while (!context.isOtpVerifyStarted() && !context.isRaceOver()) {
                        Thread.sleep(100);
                    }
                }
                if (context.isRaceOver()) {
                    return;
                }

                int tickIndex = 0;

                while (!context.isSlotReserved()
                    && !context.isRaceOver()
                    && !Thread.currentThread().isInterrupted()) {

                    long backoffMs = context.slot429RemainingMs();
                    if (backoffMs > 0) {
                        if (context.tryLogSlot("429_backoff")) {
                            ConsoleLogger.log(phone, "Slot tick waiting " + (backoffMs / 1000)
                                + "s (429 backoff)", "WAIT");
                        }
                        Thread.sleep(Math.min(backoffMs, slotTickIntervalMs));
                        continue;
                    }

                    CaptchaToken captchaToken;
                    try {
                        captchaToken = sharedCaptchaService.fetchIfExpired(null);
                    } catch (IOException e) {
                        if (context.tryLogSlot("captcha_error")) {
                            ConsoleLogger.log(phone, "Slot captcha error: " + e.getMessage(), "RETRY");
                        }
                        Thread.sleep(slotTickIntervalMs);
                        continue;
                    }

                    if (captchaToken == null) {
                        Thread.sleep(slotTickIntervalMs);
                        continue;
                    }

                    final String       captchaValue  = captchaToken.getToken();
                    final CaptchaToken captchaTokenRef = captchaToken;

                    List<IvacHttpClient> activeClients = activeSlotClientsRef.get();
                    List<List<IvacHttpClient>> tickSchedule = buildTickSchedule(activeClients, slotTickShots);
                    int numTicks = tickSchedule.size();
                    List<IvacHttpClient> tick = tickSchedule.get(tickIndex % numTicks);
                    tickIndex++;

                    ConsoleLogger.log(phone, "Slot tick " + tickIndex + "/" + numTicks + ": "
                        + slotTickShots + "/" + slotTickShots + " reserve requests firing", "RACE");

                    // Anchor the inter-tick spacing to the moment the reserve requests fire,
                    // AFTER the (variable-latency) captcha fetch. Anchoring before the fetch
                    // lets a slow fetch on one tick and a cached fetch on the next push two
                    // reserve POSTs closer than slotTickIntervalMs apart, tripping IVAC's 429.
                    long tickStart = System.currentTimeMillis();

                    for (int slotIdx = 0; slotIdx < tick.size(); slotIdx++) {
                        if (context.isSlotReserved() || context.isRaceOver()) {
                            break;
                        }

                        final IvacHttpClient client = tick.get(slotIdx);
                        final String label = "SLOT[T" + tickIndex + "-" + slotIdx + "]";

                        Thread.ofVirtual().start(() -> {
                            try {
                                SlotReservationServiceImpl slotService =
                                    new SlotReservationServiceImpl(account, appConfig, client);
                                ShotOutcome outcome = slotService.fireOneShot(context, captchaValue, label);

                                switch (outcome) {
                                    case RESERVED, ALREADY_PAID -> {
                                        if (context.tryClaimPayment()) {
                                            try {
                                                String payLabel = label.replace("SLOT", "PAY");
                                                String paymentUrl = paymentService.initiatePayment(context, payLabel);
                                                context.completePayment(paymentUrl);
                                            } catch (AuthenticationFatalException e) {
                                                context.signalRestart(e);
                                            } catch (Exception e) {
                                                context.signalRestart(new RestartSignInException(
                                                    label + " payment failed: " + e.getMessage()));
                                            }
                                        }
                                    }
                                    case CAPTCHA_BAD -> sharedCaptchaService.invalidate(captchaTokenRef);
                                    case RESTART     -> context.signalRestart(new RestartSignInException(
                                        label + " session expired during slot tick"));
                                    case DISABLED    -> context.signalRestart(new ConfigNotTodayException(
                                        "All slots and payments are disabled — try again tomorrow"));
                                    case EDGE_THROTTLE_429 -> {
                                        int n = context.nextSlotEdgeThrottleCount();
                                        long backoff = RetryUtil.base429BackoffMs(n);
                                        context.record429Backoff(backoff);
                                        IvacHttpClient fallback = edgeFallback.nextFallbackClient(phone);
                                        if (fallback != null) {
                                            activeSlotClientsRef.updateAndGet(list -> withFallbackClient(list, fallback));
                                        }
                                        if (context.tryLogSlot("edge_429")) {
                                            ConsoleLogger.log(phone, label + " slot edge 429 — escalating backoff "
                                                + (backoff / 1000) + "s, folding fallback client into rotation", "WAIT");
                                        }
                                    }
                                    default          -> { }
                                }
                            } catch (RateLimitFatalException e) {
                                context.signalRestart(new AuthenticationFatalException(e.getMessage()));
                            }
                        });
                    }

                    long elapsed = System.currentTimeMillis() - tickStart;
                    long sleepMs = slotTickIntervalMs - elapsed;
                    if (sleepMs > 0 && !context.isSlotReserved() && !context.isRaceOver()) {
                        // Solve the next tick's captcha during this wait so its latency overlaps
                        // the interval instead of stacking on top of it before the next shot.
                        sharedCaptchaService.prefetch(sleepMs);
                        Thread.sleep(sleepMs);
                    }
                }

            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        });
    }

    /**
     * OTP verify service backed by the account-shared edge-throttle fallback client, built the
     * first time OTP verify hits an edge-throttle 429. The manager hands out a fresh IPv6-bound
     * client on each hop and eventually the proxy, so services are cached per client — one
     * service per distinct fallback client, reused across ticks. Registers each service's
     * in-flight-call canceller too, same as the services built at construction time. Returns
     * null when no fallback is available (IPv6 exhausted and no usable signin_429_proxy_url) —
     * caller just keeps backing off.
     */
    private synchronized OtpService getOrCreateFallbackOtpService() {
        IvacHttpClient fallbackClient = edgeFallback.nextFallbackClient(phone);
        if (fallbackClient == null) {
            return null;
        }

        OtpService cached = fallbackOtpServices.get(fallbackClient);
        if (cached != null) {
            return cached;
        }

        OtpService service = new OtpServiceImpl(account, fallbackClient, appConfig, new PortalOtpClient(phone),
            appConfig.getOtpIntervalDelayMs());
        context.registerOtpVerifyCanceller(() -> {
            int cancelled = service.cancelInFlightVerify();
            if (cancelled > 0) {
                ConsoleLogger.log(phone, "Slot reserved — aborted " + cancelled
                    + " in-flight OTP verify call(s) (" + fallbackClient.getRemoteHost() + ")", "OK");
            }
        });
        fallbackOtpServices.put(fallbackClient, service);
        return service;
    }

    private static List<OtpService> withFallbackOtpService(List<OtpService> base, OtpService fallback) {
        if (base.contains(fallback)) {
            return base;
        }
        List<OtpService> updated = new ArrayList<>(base);
        updated.add(fallback);
        return List.copyOf(updated);
    }

    private static List<IvacHttpClient> withFallbackClient(List<IvacHttpClient> base, IvacHttpClient fallback) {
        if (base.contains(fallback)) {
            return base;
        }
        List<IvacHttpClient> updated = new ArrayList<>(base);
        updated.add(fallback);
        return List.copyOf(updated);
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    public static <T> List<List<T>> buildTickSchedule(List<T> items, int shots) {
        int n = items.size();
        List<List<T>> ticks = new ArrayList<>();

        if (n <= shots) {
            List<T> tick = new ArrayList<>(shots);
            for (int i = 0; i < shots; i++) {
                tick.add(items.get(i % n));
            }
            ticks.add(tick);
        } else {
            int fullGroups = n / shots;
            int remainder  = n % shots;

            for (int g = 0; g < fullGroups; g++) {
                ticks.add(new ArrayList<>(items.subList(g * shots, (g + 1) * shots)));
            }

            if (remainder > 0) {
                List<T> lastTick = new ArrayList<>(items.subList(fullGroups * shots, n));
                List<T> pool     = new ArrayList<>(items.subList(0, shots));
                Collections.shuffle(pool, new Random());
                lastTick.addAll(pool.subList(0, shots - remainder));
                ticks.add(lastTick);
            }
        }

        return ticks;
    }

    private String otpLabel(int serviceIdx, int numServices, Pair pair) {
        String clientLabel = serviceIdx == 0
            ? "PRIMARY" : "BYPASS-" + (serviceIdx - 1);
        return "OTP[" + clientLabel + "/" + pair.name + "]";
    }
}
