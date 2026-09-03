package com.ivac.booking.worker;

import com.ivac.booking.PortalClient;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.exception.CaptchaInvalidException;
import com.ivac.booking.exception.ConfigNotTodayException;
import com.ivac.booking.exception.RestartSignInException;
import com.ivac.booking.exception.SignInLimitReachedException;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.model.domain.SendOtpResult;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.networking.PortalLogShipper;
import com.ivac.booking.networking.EdgeThrottleFallbackManager;
import com.ivac.booking.service.PortalOtpClient;
import com.ivac.booking.service.captcha.CaptchaService;
import com.ivac.booking.service.captcha.PortalCaptchaClient;
import com.ivac.booking.service.otp.OtpService;
import com.ivac.booking.service.otp.OtpServiceImpl;
import com.ivac.booking.service.payment.IPaymentService;
import com.ivac.booking.service.payment.PaymentCallbackService;
import com.ivac.booking.service.payment.PaymentServiceImpl;
import com.ivac.booking.service.setup.AccountSetupService;
import com.ivac.booking.service.setup.PortalSetupClient;
import com.ivac.booking.service.signin.SigninService;
import com.ivac.booking.service.signin.SigninServiceImpl;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.RateLimitObserver;
import com.ivac.booking.util.SessionExpiryValidator;
import com.ivac.booking.util.TimeSync;
import com.ivac.booking.util.TimeWindowUtil;
import com.ivac.booking.model.domain.SigninResult;

import java.io.IOException;
import java.time.LocalDate;
import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;

public class AccountWorker implements Runnable {

    private static final ZoneId DHAKA_ZONE = ZoneId.of("Asia/Dhaka");

    // A stored JWT is only reused when it outlives this margin — anything shorter would expire
    // mid-race and force a restart anyway.
    private static final long JWT_REUSE_GUARD_MS = 30_000L;

    private final AccountConfig account;
    private final AppConfig appConfig;
    private final PortalCaptchaClient portalCaptcha;
    private final PortalClient portalClient;

    // Per-account daily sign-in cap tracking (AccountConfig.maxRetries). Counts only
    // successful fresh sign-ins; resets when the Dhaka calendar day rolls over. In-process
    // only — a bot restart re-fetches config and starts the count from zero.
    private int signInsToday;
    private LocalDate signInDay;

    // FP pairs cached across cycles. When sign-in fails and the next cycle starts in-window,
    // the unconsumed FP OTPs from the previous cycle can still be verified by the race.
    private String cachedSmsRequestId;
    private String cachedSmsOtp;
    private long   cachedSmsServerTimeMs;
    private String cachedEmailRequestId;
    private String cachedEmailOtp;
    private long   cachedEmailServerTimeMs;

    public AccountWorker(AccountConfig account, AppConfig appConfig,
                         PortalCaptchaClient portalCaptcha, PortalClient portalClient) {
        this.account = account;
        this.appConfig = appConfig;
        this.portalCaptcha = portalCaptcha;
        this.portalClient = portalClient;
    }

    @Override
    public void run() {
        String phone = account.getPhone();
        IvacHttpClient primaryClient = IvacHttpClient.direct(phone);

        List<String> bypassIps = appConfig.getBypassIps();
        List<IvacHttpClient> bypassClients = new ArrayList<>(bypassIps.size());

        for (String ip : bypassIps) {
            bypassClients.add(IvacHttpClient.bypass(ip, phone));
        }

        List<IvacHttpClient> allSlotClients;
        if (bypassClients.isEmpty()) {
            allSlotClients = new ArrayList<>(1);
            allSlotClients.add(primaryClient);
        } else {
            allSlotClients = new ArrayList<>(bypassClients);
        }

        PortalOtpClient portalOtp = new PortalOtpClient(phone);
        long otpIntervalDelayMs = appConfig.getOtpIntervalDelayMs();

        List<IvacHttpClient> sendOtpClients = bypassClients.isEmpty() ? List.of(primaryClient) : bypassClients;
        OtpService primaryOtpService = new OtpServiceImpl(account, primaryClient, sendOtpClients, appConfig, portalOtp, otpIntervalDelayMs);

        List<OtpService> allOtpServices;
        if (bypassClients.isEmpty()) {
            allOtpServices = new ArrayList<>(1);
            allOtpServices.add(primaryOtpService);
        } else {
            allOtpServices = new ArrayList<>(bypassClients.size());
            for (IvacHttpClient bypassClient : bypassClients) {
                allOtpServices.add(new OtpServiceImpl(account, bypassClient, appConfig, portalOtp, otpIntervalDelayMs));
            }
        }

        // Shared across every phase (sign-in, OTP verify, slot, payment) for this account, so
        // the edge-throttle escalation (IPv6 hops, then proxy) is account-wide and each
        // fallback client is only stood up (and prewarmed) once. Seeded with the sources this
        // account already egresses from so a hop never picks an address the edge is throttling.
        Set<String> initialSources = new HashSet<>();
        initialSources.add(primaryClient.getRemoteHost());
        for (IvacHttpClient bypassClient : bypassClients) {
            initialSources.add(bypassClient.getRemoteHost());
        }
        EdgeThrottleFallbackManager edgeFallback =
            new EdgeThrottleFallbackManager(appConfig.getSignin429ProxyUrl(), initialSources);

        List<IvacHttpClient> signinClients = buildSigninClients(primaryClient, bypassClients);
        SigninService signinService = new SigninServiceImpl(account, appConfig, signinClients, portalCaptcha, edgeFallback);
        CaptchaService captchaService = makePortalCaptchaService();

        try {
            int cycle = 0;
            while (!Thread.currentThread().isInterrupted()) {
                if (isWindowClosed(appConfig.getWindowEndTime())) {
                    ConsoleLogger.log(phone, "Booking window closed — stopping worker", "WARN");
                    return;
                }

                cycle++;
                ConsoleLogger.log(phone, "=== Cycle " + cycle + " starting [" + allSlotClients.size()
                    + " slot client(s), " + allOtpServices.size() + " OTP client(s)] ===", "AUTH");

                try {
                    runCycle(phone, primaryOtpService, allOtpServices, signinService, primaryClient,
                        allSlotClients, captchaService, edgeFallback);
                    ConsoleLogger.log(phone, "=== Cycle " + cycle + " complete — payment obtained ===", "OK");
                    return;

                } catch (RestartSignInException e) {
                    ConsoleLogger.log(phone, "Restarting cycle " + cycle + ": " + e.getMessage(), "RETRY");

                } catch (SignInLimitReachedException e) {
                    ConsoleLogger.log(phone, e.getMessage() + " — stopping worker", "WARN");
                    return;

                } catch (ConfigNotTodayException e) {
                    ConsoleLogger.log(phone, "Stopping — " + e.getMessage(), "FAIL");
                    return;

                } catch (AuthenticationFatalException e) {
                    ConsoleLogger.log(phone, "Stopping — fatal auth error: " + e.getMessage(), "FAIL");
                    return;

                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                    return;
                }
            }
        } finally {
            closeAll(primaryClient);

            for (IvacHttpClient c : bypassClients) {
                closeAll(c);
            }

            closeAll(portalOtp);
        }
    }

    /**
     * Blocks a fresh sign-in once the account has reached its per-day cap (maxRetries).
     * The counter resets when the Dhaka calendar day rolls over. A cap of 0 disables the limit.
     */
    private void checkDailySignInLimit(String phone) throws SignInLimitReachedException {
        int cap = account.getMaxRetries();
        if (cap <= 0) {
            return;
        }

        LocalDate today = LocalDate.now(DHAKA_ZONE);
        if (!today.equals(signInDay)) {
            signInDay = today;
            signInsToday = 0;
        }

        if (signInsToday >= cap) {
            throw new SignInLimitReachedException(
                "Daily sign-in limit reached (" + signInsToday + "/" + cap + ")");
        }
    }

    /**
     * Records a successful fresh sign-in against the per-day cap.
     */
    private void recordSignIn(String phone) {
        int cap = account.getMaxRetries();
        if (cap <= 0) {
            return;
        }

        signInsToday++;
        ConsoleLogger.log(phone, "Sign-in " + signInsToday + "/" + cap + " for today", "AUTH");
    }

    /**
     * True when the account already holds a JWT worth reusing instead of signing in again.
     * The guard margin keeps a token that is about to expire from being adopted mid-race.
     */
    static boolean hasReusableJwt(AccountConfig account, long nowMs) {
        return account.getAccessToken() != null
            && account.getJwtExpiresAtMs() > nowMs + JWT_REUSE_GUARD_MS;
    }

    /**
     * Writes a freshly minted session onto the account config so later cycles in this process see
     * it. Without this the token lives only in the per-cycle RaceContext and the portal, so a
     * restart re-signs in and eats IVAC's sign-in cooldown.
     */
    static void adoptSession(AccountConfig account, SigninResult signinResult) {
        account.setAccessToken(signinResult.getAccessToken());
        account.setJwtExpiresAtMs(signinResult.getSessionExpiresAtMs());
        account.setOtpVerified(false);

        if (signinResult.getSigninRequestId() != null) {
            account.setSigninRequestId(signinResult.getSigninRequestId());
            account.setSigninServerTimeMs(signinResult.getServerTimeMs() > 0
                ? signinResult.getServerTimeMs() : System.currentTimeMillis());
        }
    }

    private void runCycle(String phone, OtpService primaryOtpService,
                          List<OtpService> allOtpServices, SigninService signinService, IvacHttpClient primaryClient,
                          List<IvacHttpClient> allSlotClients, CaptchaService captchaService,
                          EdgeThrottleFallbackManager edgeFallback)
        throws RestartSignInException, ConfigNotTodayException, AuthenticationFatalException,
               SignInLimitReachedException, InterruptedException {

        // Fresh cycle — re-open OTP verify log shipping (a previous cycle may have muted it).
        PortalLogShipper.unmuteOtpVerifyLogs(phone);

        String email = account.getEmail();
        String windowStart = appConfig.getWindowStartTime();
        int sendOtpLeadSeconds = appConfig.getForgotPasswordLeadSeconds();
        // sendOtpLeadSeconds == 0 is a portal-level kill switch for forgot-password sendOtp:
        // skip every FP call across pre-window, in-window-signin and JWT-reuse paths. The race
        // then verifies OTPs solely against signinRequestId (the OTP IVAC sends at sign-in).
        boolean useForgotPasswordSendOtp = sendOtpLeadSeconds > 0;

        long secondsUntilWindow = TimeWindowUtil.secondsUntilWindowStart(windowStart);
        boolean alreadyInWindow = secondsUntilWindow <= 0;
        long windowStartMs = System.currentTimeMillis() + (secondsUntilWindow * 1000L);

        CompletableFuture<CaptchaToken> captchaFutureSignin;

        RaceContext context = new RaceContext();
        for (IvacHttpClient c : allSlotClients) {
            context.registerTokenListener(c::setAccessToken);
        }

        // Fired in the in-window/no-cache branch to arm the email channel while signin runs.
        CompletableFuture<SendOtpResult> inWindowEmailFuture = null;

        if (alreadyInWindow) {
            captchaFutureSignin = portalCaptcha.requestCaptcha("turnstile", phone);

            if (useForgotPasswordSendOtp) {
                // Re-use any unconsumed FP pairs cached from a previous failed cycle.
                applyCachedFpPairs(phone, context);

                // If no email cache, fire EMAIL sendOtp in parallel with sign-in so the email channel
                // is armed even though the pre-window dual flow was skipped.
                if (context.getEmailRequestId() == null) {
                    inWindowEmailFuture = fireEmailSendOtpAsync(phone, primaryOtpService, email, windowStartMs, "in-window-signin");
                }
            } else {
                ConsoleLogger.log(phone, "forgot_password_lead_seconds=0 — skipping forgot-password sendOtp (signin OTP only)", "AUTH");
            }
        } else if (!useForgotPasswordSendOtp) {
            ConsoleLogger.log(phone, "forgot_password_lead_seconds=0 — skipping forgot-password sendOtp; waiting for window open", "AUTH");
            captchaFutureSignin = portalCaptcha.requestCaptcha("turnstile", phone);
            // signinService.signinAtWindowTime() handles the T-100ms probe; no pre-window sleep here.
        } else {
            long waitForSendOtpSec = secondsUntilWindow - sendOtpLeadSeconds;

            if (waitForSendOtpSec > 0) {
                String sendOtpAt = TimeWindowUtil.formatFutureTime(waitForSendOtpSec * 1000L);
                ConsoleLogger.log(phone, "Waiting " + waitForSendOtpSec + "s before sendOtp (at " + sendOtpAt + ") [captcha fetching in background]", "WAIT");
                Thread.sleep(waitForSendOtpSec * 1000L);
            }

            captchaFutureSignin = portalCaptcha.requestCaptcha("turnstile", phone);

            // Pre-window dual-channel FP — fire both sendOtp calls in parallel,
            // then poll the portal for each channel concurrently.
            dualSendOtpAndPoll(phone, primaryOtpService, email, windowStartMs, context, "pre-window");
            cacheFpPairs(context);
        }

        // Reuse stored JWT from portal if still valid (IVAC blocks re-sign-in during 15-min window)
        String storedToken  = account.getAccessToken();
        long   storedExpiry = account.getJwtExpiresAtMs();
        boolean jwtStillValid = hasReusableJwt(account, System.currentTimeMillis());

        SigninResult signinResult;
        boolean setupTaskLaunched = false;

        if (jwtStillValid) {
            long remainingMs = storedExpiry - System.currentTimeMillis();
            ConsoleLogger.log(phone, "Reusing stored JWT — " + (remainingMs / 1000) + "s remaining", "AUTH");

            if (account.isOtpVerified()) {
                // OTP already verified for this JWT — skip OTP phase entirely and go straight to slot.
                ConsoleLogger.log(phone, "OTP already verified — skipping OTP phase; proceeding straight to slot", "AUTH");
                context.markOtpVerifyStarted();
                context.setOtpVerified(null);

                // Restart with a valid, OTP-verified session: resume one-time setup (PDF upload +
                // booking config) RIGHT NOW — no re-login, and without waiting for the booking
                // window. Put the JWT on the clients first so the upload calls carry it, then let
                // the setup task run during the pre-window wait below.
                context.setAccessToken(storedToken);
                context.setSessionExpiryValidator(new SessionExpiryValidator(storedExpiry));
                launchSetupTask(phone, context, allSlotClients, storedExpiry);
                setupTaskLaunched = true;

                long waitMs = windowStartMs - System.currentTimeMillis();
                if (waitMs > 0) {
                    ConsoleLogger.log(phone, "Window not open yet — waiting " + (waitMs / 1000) + "s", "WAIT");
                    Thread.sleep(waitMs);
                }

                signinResult = new SigninResult(storedToken, storedExpiry, null, 0L);

            } else {
                // OTP not yet verified — fire FP OTPs for the race and restore signinRequestId from portal.
                if (alreadyInWindow && useForgotPasswordSendOtp) {
                    dualSendOtpAndPoll(phone, primaryOtpService, email, windowStartMs, context, "in-window-jwt-reuse");
                    cacheFpPairs(context);
                }

                long waitMs = windowStartMs - System.currentTimeMillis();
                if (waitMs > 0) {
                    ConsoleLogger.log(phone, "Window not open yet — waiting " + (waitMs / 1000) + "s", "WAIT");
                    Thread.sleep(waitMs);
                }

                // Restore signinRequestId from portal so the race has a third OTP pair to try.
                signinResult = new SigninResult(storedToken, storedExpiry,
                    account.getSigninRequestId(), account.getSigninServerTimeMs());
            }

        } else {
            checkDailySignInLimit(phone);

            ConsoleLogger.log(phone, context.hasAnyOtpReady()
                ? "OTP pre-fetched — captcha submitting, preparing to sign in"
                : "No pre-fetched OTP — captcha submitting, preparing to sign in (OTP will arrive after sign-in)", "AUTH");

            RateLimitObserver.getInstance().onWindowStart(windowStart);
            ConsoleLogger.log(phone, "Signing in at window open via API (with captcha)", "AUTH");

            try {
                signinResult = signinService.signinAtWindowTime(windowStartMs, captchaFutureSignin);
            } catch (CaptchaInvalidException e) {
                ConsoleLogger.log(phone, "Captcha rejected — fetching fresh token and retrying sign-in", "RETRY");
                try {
                    CompletableFuture<CaptchaToken> freshFuture = portalCaptcha.requestCaptcha("turnstile", phone);
                    signinResult = signinService.signinAtWindowTime(windowStartMs, freshFuture);
                } catch (AuthenticationFatalException ae) {
                    throw ae;
                } catch (InterruptedException re) {
                    Thread.currentThread().interrupt();
                    throw re;
                } catch (Exception re) {
                    throw new RestartSignInException("Sign-in failed after captcha refresh: " + re.getMessage());
                }
            } catch (AuthenticationFatalException e) {
                throw e;
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                throw e;
            } catch (Exception e) {
                throw new RestartSignInException("Sign-in failed: " + e.getMessage());
            }

            recordSignIn(phone);

            // Carry the fresh session on the in-memory config so a restart cycle reuses it instead
            // of signing in again — IVAC answers a re-sign-in inside its cooldown with
            // 429 "You can log in after N minute(s)", which would block the worker while it is
            // holding a perfectly good JWT. RaceContext is rebuilt per cycle, so AccountConfig is
            // the only carrier across cycles.
            adoptSession(account, signinResult);

            // Store JWT in portal async for reuse on next bot restart
            if (portalClient != null) {
                final long now = System.currentTimeMillis();
                final long expiresAtMs = signinResult.getSessionExpiresAtMs();
                final String freshToken = signinResult.getAccessToken();
                final String reqId = signinResult.getSigninRequestId();
                final long serverTimeMs = signinResult.getServerTimeMs();
                CompletableFuture.runAsync(() ->
                    portalClient.storeJwt(phone, freshToken, now, expiresAtMs, reqId, serverTimeMs)
                );
            }
        }

        context.setAccessToken(signinResult.getAccessToken());

        if (signinResult.getSigninRequestId() != null) {
            context.setSigninRequestId(signinResult.getSigninRequestId());
            context.setSigninServerTimeMs(signinResult.getServerTimeMs() > 0
                ? signinResult.getServerTimeMs() : System.currentTimeMillis());
        }

        // Collect the parallel in-window email sendOtp result (if any) — race orchestrator's
        // background email poller picks up the OTP via the portal once the requestId+serverTime
        // are applied.
        if (inWindowEmailFuture != null) {
            try {
                SendOtpResult emailResult = inWindowEmailFuture.get(2, java.util.concurrent.TimeUnit.SECONDS);
                if (emailResult != null) {
                    context.setEmailRequestId(emailResult.getRequestId());
                    context.setEmailServerTimeMs(emailResult.getServerTimeMs());
                    ConsoleLogger.log(phone, "In-window email sendOtp armed — requestId: "
                        + emailResult.getRequestId(), "AUTH");
                }
            } catch (Exception e) {
                ConsoleLogger.log(phone, "In-window email sendOtp not ready in time — race will rely on SMS only", "WARN");
            }
        }
        context.setSessionExpiryValidator(new SessionExpiryValidator(signinResult.getSessionExpiresAtMs()));

        // One-time prerequisites for slot reserve: upload applicant PDFs + post booking config.
        // Guarded by persisted flags (pdfUploaded / bookingConfigured), so a JWT-reuse cycle or a
        // bot restart skips already-completed work. For the reuse+OTP-verified path this already
        // ran above (upload starts immediately on restart, before the window) — only the
        // not-yet-verified paths need it launched here, waiting for the race to verify OTP.
        if (!setupTaskLaunched) {
            launchSetupTask(phone, context, allSlotClients, signinResult.getSessionExpiresAtMs());
        }

        ConsoleLogger.log(phone, "Race context ready — pairs: sms-fp=" + (context.getSmsOtp() != null)
            + ", email-fp=" + (context.getEmailOtp() != null)
            + ", signin=" + (context.getSigninRequestId() != null), "AUTH");

        String ipmsWebBaseUrl = appConfig.getIpmsWebBaseUrl();
        IPaymentService paymentService = new PaymentServiceImpl(account, appConfig, allSlotClients, ipmsWebBaseUrl, portalCaptcha, edgeFallback);

        RaceOrchestrator orchestrator = new RaceOrchestrator(phone, context, primaryOtpService, allOtpServices,
            allSlotClients, captchaService, account, appConfig, paymentService,
            appConfig.getOtpTimeoutMs(), portalClient, edgeFallback);

        ConsoleLogger.log(phone, "Starting race: OTP tick (" + allOtpServices.size() + " service(s), "
            + account.getOtpTickShots() + "/" + account.getOtpTickIntervalMs() + "ms) + slot tick ("
            + allSlotClients.size() + " client(s), "
            + account.getSlotTickShots() + "/" + account.getSlotTickIntervalMs() + "ms)", "RACE");
        String paymentUrl = orchestrator.execute();

        ConsoleLogger.log(phone, "PAYMENT URL: " + paymentUrl, "DONE");

        // Post-payment: wait for the human to paste the redirect URL into the portal, then
        // fire the gateway callback GET across all bypass clients and report the result. Bounded
        // by the JWT — a payment link that nobody pays is not a booking, so when the session dies
        // with no redirect URL in hand the service throws RestartSignInException and the cycle
        // loop above races again for a fresh one.
        PaymentCallbackService callbackService =
            new PaymentCallbackService(account, allSlotClients, ipmsWebBaseUrl);
        callbackService.awaitAndFire(context.getReservationId(), signinResult.getSessionExpiresAtMs());
    }

    /**
     * Launches the one-time account setup (PDF upload + booking config) in a background virtual
     * thread, guarded by the persisted flags. Both IVAC endpoints need an OTP-verified JWT, so the
     * task first waits for OTP to be verified (returns immediately if it already is, e.g. a
     * JWT-reuse restart), then uploads and posts booking config, retrying transient failures until
     * the JWT-expiry deadline. The slot tick loop holds all reserve requests until this completes
     * (RaceContext setup gate).
     *
     * The task still runs when both flags are already set — the gate is opened immediately in that
     * case, and the thread only syncs IVAC's own appointment dates in the background. Without it a
     * restart-resume would race the whole window on the portal's guessed date range, never having
     * asked IVAC which dates it will actually accept.
     */
    private void launchSetupTask(String phone, RaceContext context, List<IvacHttpClient> slotClients,
                                 long jwtDeadlineMs) {
        boolean setupNeeded = !account.isPdfUploaded() || !account.isBookingConfigured();
        context.setSetupRequired(setupNeeded);
        Thread.ofVirtual().name("setup-" + phone).start(() -> {
            try (PortalSetupClient portalSetup = new PortalSetupClient(phone)) {
                AccountSetupService setup =
                    new AccountSetupService(account, slotClients.get(0), portalCaptcha, portalSetup,
                        appConfig, appConfig.getCaptchaShelfLifeMs());
                // Prefetch + decode the PDF upload payload now so the portal round-trip and the
                // base64 decode overlap the OTP-verify wait; the upload then carries a ready byte[]
                // the instant OTP verifies (too much competition to spend that time fetching).
                // No-op once the PDFs are already uploaded.
                setup.prefetchPdfPayload();
                context.awaitOtpVerifiedUntil(jwtDeadlineMs);
                if (context.isOtpVerified() && !context.isRaceOver()) {
                    if (setupNeeded) {
                        setup.prepareForBooking(jwtDeadlineMs, context::isRaceOver);
                    } else {
                        // Chain already done in an earlier cycle: only IVAC's dates are missing.
                        setup.startBookingConfigSync(jwtDeadlineMs, context::isRaceOver);
                    }
                }
                // Open the slot gate before waiting on the background dates sync — reserve fires on
                // the portal's dates and adopts IVAC's the moment they land.
                context.markSetupComplete();
                // Keep portalSetup open until the sync ends: it writes the captured dates back to
                // the portal, and try-with-resources would otherwise close the client under it.
                setup.awaitBookingConfigSync(jwtDeadlineMs);
            } catch (Exception e) {
                ConsoleLogger.log(phone, "Account setup task failed: " + e.getMessage(), "ERROR");
            } finally {
                // Always release the slot gate so reserve is never blocked forever, even if setup
                // failed — a failed reserve is recoverable, a hung slot loop is not.
                context.markSetupComplete();
            }
        });
    }

    /**
     * Fires sendOtp(SMS) + sendOtp(EMAIL) in parallel, then polls the portal for each channel
     * concurrently up to {@code otpTimeoutMs}. Populates RaceContext's SMS and Email pairs.
     * Returns immediately when both pollers finish (success or null).
     */
    private void dualSendOtpAndPoll(String phone, OtpService otpService, String email,
                                    long windowStartMs, RaceContext context, String phaseLabel)
            throws InterruptedException {
        long otpTimeoutMs = appConfig.getOtpTimeoutMs();

        CompletableFuture<SendOtpResult> smsFuture = CompletableFuture.supplyAsync(() -> {
            try {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] Sending SMS OTP via forgot-password", "AUTH");
                return otpService.sendOtp(email, "sms", windowStartMs);
            } catch (Exception e) {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] sendOtp(sms) failed: " + e.getMessage(), "WARN");
                return null;
            }
        });

        CompletableFuture<SendOtpResult> emailFuture = CompletableFuture.supplyAsync(() -> {
            try {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] Sending EMAIL OTP via forgot-password", "AUTH");
                return otpService.sendOtp(email, "email", windowStartMs);
            } catch (Exception e) {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] sendOtp(email) failed: " + e.getMessage(), "WARN");
                return null;
            }
        });

        SendOtpResult smsResult;
        SendOtpResult emailResult;
        try {
            smsResult = smsFuture.get();
            emailResult = emailFuture.get();
        } catch (ExecutionException e) {
            smsResult = null;
            emailResult = null;
        }

        // Apply requestIds + serverTimes immediately so portal polls have correct `since` cutoffs.
        if (smsResult != null) {
            context.setSmsRequestId(smsResult.getRequestId());
            context.setSmsServerTimeMs(smsResult.getServerTimeMs());
        }
        if (emailResult != null) {
            context.setEmailRequestId(emailResult.getRequestId());
            context.setEmailServerTimeMs(emailResult.getServerTimeMs());
        }

        final long smsSince = smsResult != null ? smsResult.getServerTimeMs() : 0L;
        final long emailSince = emailResult != null ? emailResult.getServerTimeMs() : 0L;
        final boolean haveSms = smsResult != null;
        final boolean haveEmail = emailResult != null;

        CompletableFuture<PortalOtpClient.OtpResult> smsPoll = haveSms
            ? CompletableFuture.supplyAsync(() -> {
                try {
                    return otpService.pollPortal("sms", smsSince, otpTimeoutMs);
                } catch (InterruptedException ie) {
                    Thread.currentThread().interrupt();
                    return null;
                }
            })
            : CompletableFuture.completedFuture(null);

        CompletableFuture<PortalOtpClient.OtpResult> emailPoll = haveEmail
            ? CompletableFuture.supplyAsync(() -> {
                try {
                    return otpService.pollPortal("email", emailSince, otpTimeoutMs);
                } catch (InterruptedException ie) {
                    Thread.currentThread().interrupt();
                    return null;
                }
            })
            : CompletableFuture.completedFuture(null);

        try {
            CompletableFuture.allOf(smsPoll, emailPoll).get();
        } catch (ExecutionException e) {
            // individual futures already swallow their own errors
        }

        PortalOtpClient.OtpResult smsOtp = smsPoll.getNow(null);
        PortalOtpClient.OtpResult emailOtp = emailPoll.getNow(null);

        if (smsOtp != null) {
            context.setSmsOtp(smsOtp.otpCode);
            ConsoleLogger.log(phone, "[" + phaseLabel + "] SMS OTP captured: " + smsOtp.otpCode, "OK");
        } else if (haveSms) {
            ConsoleLogger.log(phone, "[" + phaseLabel + "] SMS OTP poll timed out", "WARN");
        }

        if (emailOtp != null) {
            context.setEmailOtp(emailOtp.otpCode);
            ConsoleLogger.log(phone, "[" + phaseLabel + "] EMAIL OTP captured: " + emailOtp.otpCode, "OK");
        } else if (haveEmail) {
            ConsoleLogger.log(phone, "[" + phaseLabel + "] EMAIL OTP poll timed out", "WARN");
        }
    }

    /**
     * Fires sendOtp(EMAIL) asynchronously; does NOT poll the portal (the race orchestrator's
     * background email poller will pick up the OTP). Returns the future for the caller to apply
     * the requestId+serverTime once sign-in is in flight.
     */
    private CompletableFuture<SendOtpResult> fireEmailSendOtpAsync(String phone, OtpService otpService,
                                                                    String email, long windowStartMs, String phaseLabel) {
        return CompletableFuture.supplyAsync(() -> {
            try {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] Sending EMAIL OTP via forgot-password (parallel)", "AUTH");
                return otpService.sendOtp(email, "email", windowStartMs);
            } catch (Exception e) {
                ConsoleLogger.log(phone, "[" + phaseLabel + "] sendOtp(email) failed: " + e.getMessage(), "WARN");
                return null;
            }
        });
    }

    private void applyCachedFpPairs(String phone, RaceContext context) {
        boolean any = false;
        if (cachedSmsRequestId != null && cachedSmsOtp != null) {
            context.setSmsRequestId(cachedSmsRequestId);
            context.setSmsOtp(cachedSmsOtp);
            context.setSmsServerTimeMs(cachedSmsServerTimeMs);
            cachedSmsRequestId = null;
            cachedSmsOtp = null;
            cachedSmsServerTimeMs = 0L;
            any = true;
        }
        if (cachedEmailRequestId != null && cachedEmailOtp != null) {
            context.setEmailRequestId(cachedEmailRequestId);
            context.setEmailOtp(cachedEmailOtp);
            context.setEmailServerTimeMs(cachedEmailServerTimeMs);
            cachedEmailRequestId = null;
            cachedEmailOtp = null;
            cachedEmailServerTimeMs = 0L;
            any = true;
        }
        if (any) {
            ConsoleLogger.log(phone, "Reusing cached FP pairs from previous cycle", "AUTH");
        } else {
            ConsoleLogger.log(phone, "Already in window — no cached FP pairs available; race will rely on sign-in OTP", "AUTH");
        }
    }

    private void cacheFpPairs(RaceContext context) {
        if (context.getSmsRequestId() != null && context.getSmsOtp() != null) {
            cachedSmsRequestId = context.getSmsRequestId();
            cachedSmsOtp = context.getSmsOtp();
            cachedSmsServerTimeMs = context.getSmsServerTimeMs();
        }
        if (context.getEmailRequestId() != null && context.getEmailOtp() != null) {
            cachedEmailRequestId = context.getEmailRequestId();
            cachedEmailOtp = context.getEmailOtp();
            cachedEmailServerTimeMs = context.getEmailServerTimeMs();
        }
    }

    // Sign-in uses all bypass clients; primary excluded to avoid Cloudflare blocks.
    // Falls back to primary only when no bypass IPs are configured.
    private List<IvacHttpClient> buildSigninClients(IvacHttpClient primary, List<IvacHttpClient> bypassClients) {
        if (bypassClients.isEmpty()) {
            List<IvacHttpClient> result = new ArrayList<>(1);
            result.add(primary);
            return result;
        }
        return new ArrayList<>(bypassClients);
    }

    /**
     * CaptchaService that blocks on a fresh portal request for turnstile_encrypted tokens.
     */
    private CaptchaService makePortalCaptchaService() {
        return new CaptchaService() {
            @Override
            public CaptchaToken fetch() throws IOException {
                try {
                    return portalCaptcha.requestCaptcha("turnstile_encrypted", account.getPhone()).get();
                } catch (ExecutionException e) {
                    throw new IOException("Captcha solve failed: " +
                        (e.getCause() != null ? e.getCause().getMessage() : e.getMessage()), e.getCause());
                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                    throw new IOException("Interrupted waiting for captcha", e);
                }
            }

            @Override
            public CaptchaToken fetchIfExpired(CaptchaToken existing) throws IOException {
                if (existing == null || existing.isOlderThan(appConfig.getCaptchaShelfLifeMs())) {
                    return fetch();
                }

                return existing;
            }
        };
    }

    private boolean isWindowClosed(String windowEndTime) {
        if (windowEndTime == null || windowEndTime.isBlank()) {
            return false;
        }
        try {
            LocalTime end = LocalTime.parse(windowEndTime, DateTimeFormatter.ofPattern("H:mm[:ss]"));
            LocalTime now = TimeSync.now(ZoneId.of("Asia/Dhaka"));
            return !now.isBefore(end);
        } catch (Exception e) {
            return false;
        }
    }

    private void closeAll(AutoCloseable... closeable) {
        for (AutoCloseable c : closeable) {
            if (c != null) {
                try {
                    c.close();
                } catch (Exception ignored) {
                }
            }
        }
    }
}
