package com.ivac.booking.service.signin;

import com.google.gson.Gson;
import com.ivac.booking.Constants;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.model.domain.SigninResult;
import com.ivac.booking.model.request.SigninRequest;
import com.ivac.booking.model.response.SigninResponse;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.networking.EdgeThrottleFallbackManager;
import com.ivac.booking.service.captcha.PortalCaptchaClient;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.RetryUtil;
import com.ivac.booking.worker.RaceOrchestrator;

import java.io.IOException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.AtomicReference;

public class SigninServiceImpl implements SigninService {

    private static final long SIGNIN_PROBE_LEAD_MS = 100L;

    // Hard cap: if the server keeps rejecting captcha after this many tokens, stop and report
    private static final int MAX_CAPTCHA_BUDGET = 100;

    // Cloudflare gateway/origin errors (502, 504, 520-524) mean the request reached IVAC's
    // origin but no usable response returned — the origin may have already minted an OTP.
    // In single sign-in mode these retries are paced so a 520 storm cannot spam duplicate OTPs.
    private static final long ORIGIN_ERROR_COOLDOWN_MS = 5_000L;

    private enum ShotOutcome { RETRY, CAPTCHA_EXPIRED, RATE_LIMITED, FATAL, SUCCESS }

    private record ShotResult(ShotOutcome outcome, SigninResult signinResult, long retryAfterMs,
                              String message, int statusCode) {
        static ShotResult retry()                 { return new ShotResult(ShotOutcome.RETRY, null, -1L, null, -1); }
        static ShotResult retry(int statusCode)   { return new ShotResult(ShotOutcome.RETRY, null, -1L, null, statusCode); }
        static ShotResult captchaExpired()        { return new ShotResult(ShotOutcome.CAPTCHA_EXPIRED, null, -1L, null, -1); }
        static ShotResult rateLimited(long retryAfterMs) {
            return new ShotResult(ShotOutcome.RATE_LIMITED, null, retryAfterMs, null, 429);
        }
        // Cloudflare edge/WAF throttle on sign-in — "Too many request detected", no
        // server-stated cooldown. IP-scoped, not account-scoped, so callers fall back to another
        // egress IP instead of just backing off. message="edge_throttle" flags this case.
        static ShotResult edgeThrottled() {
            return new ShotResult(ShotOutcome.RATE_LIMITED, null, -1L, "edge_throttle", 429);
        }
        static ShotResult fatal()                 { return new ShotResult(ShotOutcome.FATAL, null, -1L, null, -1); }
        static ShotResult fatal(String message)   { return new ShotResult(ShotOutcome.FATAL, null, -1L, message, -1); }
        static ShotResult success(SigninResult r) { return new ShotResult(ShotOutcome.SUCCESS, r, -1L, null, -1); }
    }

    /**
     * A captcha token paired with a validity flag.
     * Identity (==) is used to detect stale references — no ID field needed.
     */
    private record CaptchaEntry(CaptchaToken token, AtomicBoolean valid) {
        static CaptchaEntry of(CaptchaToken token) {
            return new CaptchaEntry(token, new AtomicBoolean(true));
        }
    }

    private final Gson gson = new Gson();
    private final AppConfig appConfig;
    private final AccountConfig account;
    private final List<IvacHttpClient> httpClientList;
    private final PortalCaptchaClient portalCaptcha;

    // Shared across every phase (sign-in, OTP verify, slot, payment) for this account, so the
    // edge-throttle escalation (IPv6 hops, then proxy) is account-wide and each fallback client
    // is only stood up (and prewarmed) once per account rather than once per phase.
    private final EdgeThrottleFallbackManager edgeFallback;

    public SigninServiceImpl(AccountConfig account, AppConfig appConfig, List<IvacHttpClient> httpClientList,
                             PortalCaptchaClient portalCaptcha, EdgeThrottleFallbackManager edgeFallback) {
        this.account = account;
        this.appConfig = appConfig;
        this.httpClientList = httpClientList;
        this.portalCaptcha = portalCaptcha;
        this.edgeFallback = edgeFallback;
    }

    @Override
    public SigninResult signinAtWindowTime(long windowStartMs, CompletableFuture<CaptchaToken> captchaFuture)
            throws InterruptedException, AuthenticationFatalException {

        String phone = account.getPhone();

        long waitMs = (windowStartMs - SIGNIN_PROBE_LEAD_MS) - System.currentTimeMillis();
        if (waitMs > 0) {
            ConsoleLogger.log(phone, "Waiting " + (waitMs / 1000) + "s for window to open", "WAIT");
            Thread.sleep(waitMs);
        }

        CaptchaToken initialToken;
        try {
            initialToken = captchaFuture.get();
        } catch (ExecutionException e) {
            throw new RuntimeException(e);
        }

        // Track total captcha tokens consumed across the entire sign-in attempt
        AtomicInteger totalCaptchaConsumed = new AtomicInteger(1);

        if (initialToken.isOlderThan(appConfig.getCaptchaShelfLifeMs())) {
            ConsoleLogger.log(phone, "Captcha stale before sign-in — fetching fresh token", "AUTH");
            try {
                initialToken = portalCaptcha.requestCaptcha("turnstile", phone).get();
                totalCaptchaConsumed.incrementAndGet();
            } catch (ExecutionException ex) {
                ConsoleLogger.log(phone, "Fresh captcha fetch failed: " + ex.getMessage(), "WARN");
            }
        }

        if (account.isSingleSignIn()) {
            return signinSequential(initialToken, totalCaptchaConsumed);
        }

        int signinTickShots = account.getSigninTickShots();
        long signinTickIntervalMs = account.getSigninTickIntervalMs();
        // Mutable so an edge-throttle 429 can fold the edge-throttle fallback client into rotation
        // mid-race; the tick schedule is rebuilt from this each tick (cheap — small lists).
        AtomicReference<List<IvacHttpClient>> activeClientsRef = new AtomicReference<>(httpClientList);

        AtomicReference<CaptchaEntry> entryRef = new AtomicReference<>(CaptchaEntry.of(initialToken));
        CompletableFuture<SigninResult> winner = new CompletableFuture<>();
        // Shared sign-in cooldown deadline (epoch ms). Set when IVAC returns 429 with a
        // parseable "You can log in after X minute(s) and Y second(s)" message.
        // All clients honor this; while active, the loop also skips captcha refresh.
        AtomicLong signin429BlockedUntilMs = new AtomicLong(0L);
        int tickIndex = 0;

        while (!winner.isDone()) {
            long now = System.currentTimeMillis();
            long blockedUntil = signin429BlockedUntilMs.get();
            if (now < blockedUntil) {
                long remaining = blockedUntil - now;
                Thread.sleep(Math.min(remaining, signinTickIntervalMs));
                continue;
            }

            long batchStart = System.currentTimeMillis();

            // Refresh happens here, in the main thread, never inside virtual threads.
            // Virtual threads only flip valid=false via CAS; this thread acts on it.
            CaptchaEntry entry = entryRef.get();
            if (entry.valid().get() && entry.token().isOlderThan(appConfig.getCaptchaShelfLifeMs())) {
                entry.valid().compareAndSet(true, false);
            }
            if (!entry.valid().get()) {
                int consumed = totalCaptchaConsumed.incrementAndGet();
                if (consumed > MAX_CAPTCHA_BUDGET) {
                    winner.completeExceptionally(new AuthenticationFatalException(
                        "Sign-in captcha budget exhausted — " + MAX_CAPTCHA_BUDGET + " tokens rejected by server"));
                    break;
                }
                // Don't block indefinitely on captcha refresh — poll winner in parallel.
                // If a parallel signin shot succeeds while we're waiting, we don't need the
                // fresh captcha at all; downstream (OTP verify) needs no captcha, so we must
                // not delay it on a soon-to-be-discarded token.
                CompletableFuture<CaptchaToken> refreshFuture = portalCaptcha.requestCaptcha("turnstile", phone);
                CaptchaToken fresh = null;
                while (!winner.isDone()) {
                    try {
                        fresh = refreshFuture.get(100, java.util.concurrent.TimeUnit.MILLISECONDS);
                        break;
                    } catch (java.util.concurrent.TimeoutException ignored) {
                        // keep polling — re-check winner.isDone() on every iteration
                    } catch (ExecutionException ex) {
                        ConsoleLogger.log(phone, "Captcha refresh failed: " + ex.getMessage(), "WARN");
                        break;
                    }
                }
                if (winner.isDone()) {
                    refreshFuture.cancel(true);
                    break;
                }
                if (fresh != null) {
                    CaptchaEntry newEntry = CaptchaEntry.of(fresh);
                    entryRef.set(newEntry);
                    entry = newEntry;
                    ConsoleLogger.log(phone, "Captcha refreshed (token " + consumed + "/" + MAX_CAPTCHA_BUDGET + ")", "AUTH");
                }
            }

            final CaptchaEntry batchEntry = entry;
            List<IvacHttpClient> activeClients = activeClientsRef.get();
            List<List<IvacHttpClient>> tickSchedule = RaceOrchestrator.buildTickSchedule(activeClients, signinTickShots);
            int numTicks = tickSchedule.size();
            List<IvacHttpClient> tickClients = tickSchedule.get(tickIndex % numTicks);
            tickIndex++;

            ConsoleLogger.log(phone, "Sign-in tick " + tickIndex + "/" + numTicks + ": "
                + tickClients.size() + "/" + signinTickShots + " slots firing", "AUTH");

            for (IvacHttpClient client : tickClients) {
                if (winner.isDone()) {
                    break;
                }

                Thread.ofVirtual().start(() -> {
                    if (winner.isDone()) {
                        return;
                    }

                    // One shot per tick — any error (incl. 5xx) yields to the next tick interval.
                    ShotResult r = fireSingleSignin(client, batchEntry.token().getToken());

                    if (winner.isDone()) {
                        return;
                    }

                    switch (r.outcome()) {
                        case SUCCESS -> {
                            for (IvacHttpClient other : activeClientsRef.get()) {
                                other.cancelInFlightCalls();
                            }
                            winner.complete(r.signinResult());
                        }
                        case FATAL -> {
                            for (IvacHttpClient other : activeClientsRef.get()) {
                                other.cancelInFlightCalls();
                            }
                            String fatalMsg = r.message() != null ? r.message() : "Invalid credentials (HTTP 401)";
                            winner.completeExceptionally(new AuthenticationFatalException(fatalMsg));
                        }
                        case CAPTCHA_EXPIRED -> {
                            // Object identity (==) check: if entryRef was already replaced by the
                            // main thread, this is a late 400 for a stale entry — skip it entirely.
                            CaptchaEntry cur = entryRef.get();
                            if (cur == batchEntry) {
                                cur.valid().compareAndSet(true, false);
                            }
                        }
                        case RATE_LIMITED -> {
                            long serverCooldown = r.retryAfterMs();
                            if (serverCooldown >= 0) {
                                long deadline = System.currentTimeMillis() + serverCooldown;
                                long applied = signin429BlockedUntilMs.accumulateAndGet(deadline, Math::max);
                                long remaining = Math.max(0L, applied - System.currentTimeMillis());
                                ConsoleLogger.log(phone, "Sign-in 429 — server-side cooldown "
                                    + (remaining / 1000) + "s, pausing all clients + captcha", "WAIT");
                            } else {
                                // Edge throttle with no server-stated wait ("Too many request
                                // detected") — the origin IP itself is being throttled. No extra
                                // backoff (the tick interval paces the retry); fold the edge-throttle
                                // fallback client into rotation so subsequent ticks also try it.
                                if ("edge_throttle".equals(r.message())) {
                                    IvacHttpClient fallback = nextFallbackClient(phone);
                                    if (fallback != null) {
                                        activeClientsRef.updateAndGet(list -> withFallbackClient(list, fallback));
                                    }
                                }
                                ConsoleLogger.log(phone, "Client " + client.getRemoteHost()
                                    + " sign-in edge 429 — will retry next tick", "WAIT");
                            }
                        }
                        case RETRY -> {}
                    }
                });
            }

            long elapsed = System.currentTimeMillis() - batchStart;
            long sleepDeadline = batchStart + signinTickIntervalMs;
            if (elapsed < signinTickIntervalMs && !winner.isDone()) {
                try {
                    while (System.currentTimeMillis() < sleepDeadline && !winner.isDone()) {
                        long remaining = sleepDeadline - System.currentTimeMillis();
                        Thread.sleep(Math.min(remaining, 50L));
                    }
                } catch (InterruptedException e) {
                    for (IvacHttpClient c : activeClientsRef.get()) {
                        c.cancelInFlightCalls();
                    }
                    throw e;
                }
            }
        }

        try {
            SigninResult result = winner.get();
            ConsoleLogger.log(phone, "Sign-in successful — JWT obtained", "OK");
            return result;
        } catch (ExecutionException e) {
            if (e.getCause() instanceof AuthenticationFatalException afe) {
                throw afe;
            }
            throw new RuntimeException(e);
        }
    }

    /**
     * Sequential single sign-in mode (per-account flag singleSignIn).
     * Fires exactly one sign-in at a time, blocks for its response, and only retries on a
     * non-success outcome — never overlapping calls. The burst engine can issue many concurrent
     * sign-ins that each succeed and trigger a duplicate OTP when the server is slow (60-80s
     * responses observed); serialising the calls guarantees at most one success, hence one OTP.
     * OTP/slot/payment phases are unaffected — this returns the same SigninResult as the burst.
     */
    private SigninResult signinSequential(CaptchaToken initialToken, AtomicInteger totalCaptchaConsumed)
            throws InterruptedException, AuthenticationFatalException {
        String phone = account.getPhone();
        CaptchaToken token = initialToken;
        long blockedUntilMs = 0L;
        int clientIndex = 0;
        int attempt = 0;
        int consecutiveRateLimits = 0;
        // Grows to include the edge-throttle fallback client once an edge-throttle 429 is seen.
        List<IvacHttpClient> activeClients = httpClientList;

        while (true) {
            long now = System.currentTimeMillis();
            if (now < blockedUntilMs) {
                Thread.sleep(blockedUntilMs - now);
                continue;
            }

            // Refresh captcha if missing or stale. One token at a time; the fetch round-trip is
            // the only natural gap between attempts (failed retries fire immediately by design).
            if (token == null || token.isOlderThan(appConfig.getCaptchaShelfLifeMs())) {
                int consumed = totalCaptchaConsumed.incrementAndGet();
                if (consumed > MAX_CAPTCHA_BUDGET) {
                    throw new AuthenticationFatalException(
                        "Sign-in captcha budget exhausted — " + MAX_CAPTCHA_BUDGET + " tokens rejected by server");
                }
                try {
                    token = portalCaptcha.requestCaptcha("turnstile", phone).get();
                    ConsoleLogger.log(phone, "Captcha refreshed (token " + consumed + "/" + MAX_CAPTCHA_BUDGET + ")", "AUTH");
                } catch (ExecutionException ex) {
                    ConsoleLogger.log(phone, "Captcha refresh failed: " + ex.getMessage(), "WARN");
                    token = null;
                    continue;
                }
            }

            IvacHttpClient client = activeClients.get(clientIndex % activeClients.size());
            clientIndex++;
            attempt++;

            ConsoleLogger.log(phone, "Single sign-in attempt " + attempt + " via " + client.getRemoteHost(), "AUTH");

            // Synchronous — blocks for the response (read timeout 180s); only one call in flight.
            ShotResult r = fireSingleSignin(client, token.getToken());

            switch (r.outcome()) {
                case SUCCESS -> {
                    ConsoleLogger.log(phone, "Sign-in successful — JWT obtained", "OK");
                    return r.signinResult();
                }
                case FATAL -> throw new AuthenticationFatalException(
                    r.message() != null ? r.message() : "Invalid credentials (HTTP 401)");
                case CAPTCHA_EXPIRED -> {
                    consecutiveRateLimits = 0;
                    token = null;
                }
                case RATE_LIMITED -> {
                    long serverStated = r.retryAfterMs();
                    if (serverStated < 0 && "edge_throttle".equals(r.message())) {
                        IvacHttpClient fallback = nextFallbackClient(phone);
                        if (fallback != null) {
                            activeClients = withFallbackClient(activeClients, fallback);
                        }
                    }
                    long cooldown = RetryUtil.signinRateLimitCooldownMs(serverStated, consecutiveRateLimits);
                    consecutiveRateLimits = serverStated > 0 ? 0 : consecutiveRateLimits + 1;
                    blockedUntilMs = System.currentTimeMillis() + cooldown;
                    ConsoleLogger.log(phone, "Sign-in 429 — cooldown " + (cooldown / 1000) + "s"
                        + (serverStated > 0 ? " (server-stated)" : " (throttle backoff)"), "WAIT");
                }
                case RETRY -> {
                    consecutiveRateLimits = 0;
                    boolean serviceUnavailable = r.statusCode() == 403;
                    if (isOriginError(r.statusCode()) || serviceUnavailable) {
                        long cooldownMs = account.getRetryDelayMs();
                        blockedUntilMs = System.currentTimeMillis() + cooldownMs;
                        String reason = serviceUnavailable
                            ? "service unavailable — avoid edge throttle"
                            : "CF origin error — avoid duplicate OTP";
                        ConsoleLogger.log(phone, "Sign-in " + r.statusCode()
                            + " (" + reason + ") — cooldown " + (cooldownMs / 1000) + "s", "WAIT");
                    }
                    // Otherwise retry immediately — wait-for-result is the only throttle (per design).
                }
            }
        }
    }

    /** Delegates to the account-shared EdgeThrottleFallbackManager: another IPv6 source while the pool has untried addresses, then the proxy. */
    private IvacHttpClient nextFallbackClient(String phone) {
        return edgeFallback.nextFallbackClient(phone);
    }

    private static List<IvacHttpClient> withFallbackClient(List<IvacHttpClient> base, IvacHttpClient fallback) {
        if (base.contains(fallback)) {
            return base;
        }
        List<IvacHttpClient> updated = new ArrayList<>(base);
        updated.add(fallback);
        return List.copyOf(updated);
    }

    private static boolean isOriginError(int statusCode) {
        return statusCode == 502 || statusCode == 504 || (statusCode >= 520 && statusCode <= 524);
    }

    private ShotResult fireSingleSignin(IvacHttpClient client, String captchaTokenStr) {
        String phone = account.getPhone();
        try {
            SigninResult signinResult = doSignin(client, phone, captchaTokenStr);
            return ShotResult.success(signinResult);
        } catch (IvacHttpClient.HttpStatusException e) {
            return switch (e.getStatusCode()) {
                case 400 -> ShotResult.captchaExpired();
                case 401 -> ShotResult.fatal();
                case 403 -> {
                    ConsoleLogger.log(phone, "Sign-in 403 (service unavailable) on " + client.getRemoteHost()
                        + " — backing off like origin errors", "RETRY");
                    yield ShotResult.retry(e.getStatusCode());
                }
                case 429 -> {
                    if (RetryUtil.isSigninPermanentBlock(e.getBody())) {
                        String message = RetryUtil.signinMessage(e.getBody());
                        ConsoleLogger.log(phone, "Sign-in 429 — OTP limit reached: " + message
                            + " — stopping account", "FAIL");
                        yield ShotResult.fatal("Sign-in blocked: " + message);
                    }
                    long retryAfterMs = RetryUtil.parseSigninRetryAfterMs(e.getBody());
                    if (retryAfterMs < 0 && RetryUtil.isEdgeThrottleMessage(e.getBody())) {
                        yield ShotResult.edgeThrottled();
                    }
                    yield ShotResult.rateLimited(retryAfterMs);
                }
                case 503 -> {
                    ConsoleLogger.log(phone, "Sign-in 503 CF page — retrying immediately", "RETRY");
                    yield ShotResult.retry();
                }
                case 502, 504 -> ShotResult.retry(e.getStatusCode());
                default -> ShotResult.retry(e.getStatusCode());
            };
        } catch (IOException | AuthenticationFatalException e) {
            return ShotResult.retry();
        }
    }

    private SigninResult doSignin(IvacHttpClient client, String phone, String captchaToken)
            throws IOException, AuthenticationFatalException {
        String url = Constants.BASE_URL + appConfig.getSigninPath();
        SigninRequest request = new SigninRequest(account.getPhone(), account.getPassword(), captchaToken);

        String json = client.postUnauthenticated(url, request, Map.of("x-sec-navigation-state", appConfig.getSigninNavState()));
        SigninResponse response = gson.fromJson(json, SigninResponse.class);

        if (response.getData() == null || response.getData().getAccessToken() == null) {
            String msg = response.getMessage() != null ? response.getMessage() : "no token";

            if (msg.toLowerCase().contains("invalid") || msg.toLowerCase().contains("credential")) {
                throw new AuthenticationFatalException("Sign-in rejected: " + msg);
            }

            throw new IOException("Sign-in failed: " + msg);
        }

        int expiresInSeconds = response.getData().getExpiresAt();
        if (expiresInSeconds <= 0) {
            expiresInSeconds = 899;
        }

        long expiresAtMs = System.currentTimeMillis() + (expiresInSeconds * 1000L);

        long serverTimeMs;
        try {
            serverTimeMs = Instant.parse(response.getServerTime()).toEpochMilli();
        } catch (Exception ignored) {
            serverTimeMs = System.currentTimeMillis();
        }

        return new SigninResult(response.getData().getAccessToken(), expiresAtMs, response.getData().getRequestId(), serverTimeMs);
    }
}
