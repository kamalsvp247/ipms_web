package com.ivac.booking.service.payment;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.Constants;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.exception.ConfigNotTodayException;
import com.ivac.booking.exception.RestartSignInException;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.service.captcha.PortalCaptchaClient;
import com.ivac.booking.util.HttpUtil;
import com.ivac.booking.util.RetryUtil;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.networking.EdgeThrottleFallbackManager;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.worker.RaceContext;
import com.ivac.booking.worker.RaceOrchestrator;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;

public class PaymentServiceImpl implements IPaymentService {

    private enum ShotOutcome {
        SUCCESS, RATE_LIMITED,
        // IVAC edge/WAF throttle ("Too many request detected", no server-stated wait) —
        // distinct from RATE_LIMITED (a real account-scoped cooldown) so the caller escalates
        // backoff and folds the edge-throttle fallback client into the payment client rotation.
        EDGE_THROTTLE_429,
        FATAL_RATE_LIMIT, AUTH_FAILED, CONFIG_NOT_TODAY, APPOINTMENT_NOT_FOUND, RETRY
    }

    private record ShotResult(ShotOutcome outcome, String gatewayUrl, String rawBody) {
        static ShotResult retry() { return new ShotResult(ShotOutcome.RETRY, null, null); }
    }

    private static final long PAYMENT_TTL_GUARD_MS = 900_000L;

    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    private static final OkHttpClient IPMS_WEB_CLIENT = new OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build();

    // IVAC bakes a fixed, deployment-scoped payment config ID into the initiate URL in its frontend
    // bundle: POST /payment/{paymentConfigId}/dg-epay/initiate. Decoded from the bundle's _Q initiate
    // function — the URL is the static string "payment/<uuid>/dg-epay/initiate" (the ssl gateway path
    // is "payment/ssl/initiate", no ID). The <uuid> is NOT the account's appointmentId (that still
    // goes in the body as {appointmentId}); it is a deployment constant IVAC rotates on redeploy,
    // exactly like reserveSlotId. The live value is delivered via /api/config
    // (settings.payment_config_id, bundle-synced by the portal's Algorithm Monitor); the constant
    // below is only a fallback for an older portal whose config omits the field.
    private static final String PAYMENT_CONFIG_ID_FALLBACK = "dcd59a95-d55e-41ed-b57c-60416e01617e";

    private final String ipmsWebBaseUrl;
    private final Gson gson = new Gson();
    // allClients[0..n] = bypass clients (primary excluded, same as OTP tick strategy)
    private final List<IvacHttpClient> allClients;
    private final AccountConfig account;
    private final PortalCaptchaClient portalCaptcha;
    private final EdgeThrottleFallbackManager edgeFallback;

    // Resolved once at construction from the bundle-synced portal config (falling back to the
    // compiled-in constant): POST /payment/{paymentConfigId}/dg-epay/initiate.
    private final String[] paymentPaths;

    // GET /appointment/get-booking-config path (bundle-extracted, config-delivered; fallback default).
    private final String getBookingConfigPath;

    public PaymentServiceImpl(AccountConfig account, AppConfig appConfig, List<IvacHttpClient> allClients,
                              String ipmsWebBaseUrl, PortalCaptchaClient portalCaptcha,
                              EdgeThrottleFallbackManager edgeFallback) {
        this.account = account;
        this.allClients = allClients;
        this.ipmsWebBaseUrl = ipmsWebBaseUrl;
        this.portalCaptcha = portalCaptcha;
        this.edgeFallback = edgeFallback;

        String configId = appConfig != null ? appConfig.getPaymentConfigId() : null;
        if (configId == null || configId.isBlank()) {
            configId = PAYMENT_CONFIG_ID_FALLBACK;
        }
        String paymentTemplate = appConfig != null
            ? appConfig.getPaymentPathTemplate()
            : "/payment/{paymentConfigId}/dg-epay/initiate";
        this.paymentPaths = new String[] {
            paymentTemplate.replace("{paymentConfigId}", configId),
        };
        this.getBookingConfigPath = appConfig != null
            ? appConfig.getGetBookingConfigPath()
            : "/appointment/get-booking-config";
    }

    @Override
    public String initiatePayment(RaceContext context, String label)
            throws RestartSignInException, ConfigNotTodayException, AuthenticationFatalException, InterruptedException {

        String phone = account.getPhone();

        ensureAppointmentId(label);

        int paymentTickShots = account.getPaymentTickShots();
        long paymentTickIntervalMs = account.getPaymentTickIntervalMs();
        // Mutable so an edge-throttle 429 can fold the edge-throttle fallback client into rotation
        // mid-payment; the tick schedule is rebuilt from this each tick (cheap — small lists).
        AtomicReference<List<IvacHttpClient>> activeClientsRef = new AtomicReference<>(allClients);
        int tickIndex = 0;
        int consecutiveEdgeThrottle429s = 0;

        ConsoleLogger.log(phone, label + " initiating payment (tick, " + allClients.size()
            + " client(s), " + paymentTickShots + " shots/" + paymentTickIntervalMs + "ms)", "WAIT");

        while (true) {
            if (Thread.currentThread().isInterrupted()) {
                throw new InterruptedException("Payment interrupted");
            }
            if (context.isPaymentDone()) {
                throw new InterruptedException("Payment already completed by peer thread");
            }

            long tickStart = System.currentTimeMillis();
            List<IvacHttpClient> activeClients = activeClientsRef.get();
            List<List<IvacHttpClient>> tickSchedule = RaceOrchestrator.buildTickSchedule(activeClients, paymentTickShots);
            int numTicks = tickSchedule.size();
            List<IvacHttpClient> tick = tickSchedule.get(tickIndex % numTicks);
            tickIndex++;

            ShotResult result = fireOneTick(tick, label, tickIndex);

            switch (result.outcome()) {
                case SUCCESS -> {
                    postPaymentToIpmsWeb(result.rawBody(), context.getReservationId());
                    return result.gatewayUrl();
                }
                case CONFIG_NOT_TODAY -> throw new ConfigNotTodayException("Payment not allowed today");
                case APPOINTMENT_NOT_FOUND -> throw new RestartSignInException("Appointment not found — restarting");
                case AUTH_FAILED -> {
                    long elapsed = System.currentTimeMillis() - context.getSlotReservedAtMs();
                    if (elapsed > PAYMENT_TTL_GUARD_MS || context.getSessionExpiryValidator().isSessionExpired()) {
                        throw new RestartSignInException(
                                "Session expired during payment (waited " + (elapsed / 1000) + "s)");
                    }
                    ConsoleLogger.log(phone, label + " payment 401 (" + (elapsed / 1000) + "s elapsed) — retrying in " + paymentTickIntervalMs + "ms", "RETRY");
                    Thread.sleep(paymentTickIntervalMs);
                }
                case FATAL_RATE_LIMIT -> throw new AuthenticationFatalException(
                    label + " payment 429 >30 min — stopping account");
                case EDGE_THROTTLE_429 -> {
                    consecutiveEdgeThrottle429s++;
                    long backoff = RetryUtil.base429BackoffMs(consecutiveEdgeThrottle429s);
                    IvacHttpClient fallback = edgeFallback.nextFallbackClient(phone);
                    if (fallback != null) {
                        activeClientsRef.updateAndGet(list -> withFallbackClient(list, fallback));
                    }
                    ConsoleLogger.log(phone, label + " payment edge 429 — escalating backoff "
                        + (backoff / 1000) + "s, folding fallback client into rotation", "WAIT");
                    Thread.sleep(backoff);
                }
                case RATE_LIMITED -> {
                    consecutiveEdgeThrottle429s = 0;
                    ConsoleLogger.log(phone, label + " payment 429 — backing off " + (Constants.PAYMENT_429_BASE_BACKOFF_MS / 1000) + "s", "WAIT");
                    Thread.sleep(Constants.PAYMENT_429_BASE_BACKOFF_MS);
                }
                case RETRY -> {
                    long elapsed = System.currentTimeMillis() - tickStart;
                    long sleepMs = paymentTickIntervalMs - elapsed;
                    if (sleepMs > 0) {
                        Thread.sleep(sleepMs);
                    }
                }
            }
        }
    }

    private static List<IvacHttpClient> withFallbackClient(List<IvacHttpClient> base, IvacHttpClient fallback) {
        if (base.contains(fallback)) {
            return base;
        }
        List<IvacHttpClient> updated = new ArrayList<>(base);
        updated.add(fallback);
        return List.copyOf(updated);
    }

    /**
     * Fires one tick: tick.size() × paymentPaths.length shots in parallel virtual threads.
     * First SUCCESS wins immediately. If no success, returns the highest-priority outcome
     * after all shots complete: CONFIG_NOT_TODAY > AUTH_FAILED > RATE_LIMITED > RETRY.
     */
    private ShotResult fireOneTick(List<IvacHttpClient> tick, String label, int tickIndex)
            throws InterruptedException {
        int totalShots = tick.size();
        ShotResult[] results = new ShotResult[totalShots];
        Arrays.fill(results, ShotResult.retry());

        // IVAC now requires a raw Turnstile token in the x-token header on payment initiate.
        // Fetch one fresh token per tick; all shots in the tick share it (single-use: one wins).
        String xToken = fetchRawCaptcha(label);
        if (xToken == null) {
            return ShotResult.retry();
        }

        CompletableFuture<ShotResult> winner = new CompletableFuture<>();
        AtomicInteger completed = new AtomicInteger(0);

        ConsoleLogger.log(account.getPhone(), label + " payment tick " + tickIndex + " → firing "
            + totalShots + " shots across " + paymentPaths.length + " endpoints", "PAYMENT");

        for (int i = 0; i < totalShots; i++) {
            final int idx = i;
            final IvacHttpClient shotClient = tick.get(i);
            final String path = paymentPaths[i % paymentPaths.length];
            final String endpointTag = path.contains("dg-epay") ? "dg" : "ssl";
            final String shotLabel = label + "[B-" + (i + 1) + "-" + endpointTag + "]";

            Thread.ofVirtual().start(() -> {
                ShotResult r;
                try {
                    r = fireSingleShot(shotClient, shotLabel, path, xToken);
                } catch (Throwable t) {
                    r = ShotResult.retry();
                }
                results[idx] = r;

                if (r.outcome() == ShotOutcome.SUCCESS || r.outcome() == ShotOutcome.FATAL_RATE_LIMIT) {
                    winner.complete(r);
                    return;
                }

                if (completed.incrementAndGet() == totalShots && !winner.isDone()) {
                    winner.complete(priorityPick(results));
                }
            });
        }

        try {
            return winner.get();
        } catch (ExecutionException e) {
            return ShotResult.retry();
        } catch (InterruptedException e) {
            for (IvacHttpClient c : allClients) {
                c.cancelInFlightCalls();
            }
            throw e;
        }
    }

    private ShotResult priorityPick(ShotResult[] results) {
        ShotResult best = ShotResult.retry();
        for (ShotResult r : results) {
            if (r.outcome() == ShotOutcome.FATAL_RATE_LIMIT)    { return r; }
            if (r.outcome() == ShotOutcome.CONFIG_NOT_TODAY)    { return r; }
            if (r.outcome() == ShotOutcome.APPOINTMENT_NOT_FOUND && best.outcome() == ShotOutcome.RETRY) { best = r; }
            else if (r.outcome() == ShotOutcome.AUTH_FAILED     && best.outcome() == ShotOutcome.RETRY)  { best = r; }
            else if (r.outcome() == ShotOutcome.RATE_LIMITED    && best.outcome() == ShotOutcome.RETRY)  { best = r; }
            else if (r.outcome() == ShotOutcome.EDGE_THROTTLE_429 && best.outcome() == ShotOutcome.RETRY) { best = r; }
        }
        return best;
    }

    private ShotResult fireSingleShot(IvacHttpClient shotClient, String label, String path, String xToken) {
        String phone = account.getPhone();
        try {
            ConsoleLogger.log(phone, label + " → Initiating payment", "PAYMENT");
            Map<String, Object> body = new HashMap<>();
            body.put("appointmentId", account.getAppointmentId());
            IvacHttpClient.RawResponse raw = shotClient.postRawNoAuthRetry(path, body,
                    Constants.INITIATE_REQUEST_TIMEOUT_MS, Map.of("x-token", xToken));

            if (raw.statusCode() == 200 || raw.statusCode() == 201) {
                String gatewayUrl = extractGatewayUrl(raw.body());
                if (gatewayUrl != null && !gatewayUrl.isEmpty()) {
                    ConsoleLogger.log(phone, label + " payment succeeded → " + gatewayUrl, "OK");
                    return new ShotResult(ShotOutcome.SUCCESS, gatewayUrl, raw.body());
                }
                ConsoleLogger.log(phone, label + " payment HTTP " + raw.statusCode() + " but no gateway URL found in body: "
                        + (raw.body() == null ? "(null)" : raw.body().substring(0, Math.min(300, raw.body().length()))), "WARN");
                return ShotResult.retry();
            }

            if (raw.statusCode() == 400 && raw.body() != null) {
                try {
                    JsonObject json = gson.fromJson(raw.body(), JsonObject.class);
                    String msg = json.has("message") ? json.get("message").getAsString() : "";
                    if ("CONFIG_NOT_TODAY".equals(msg)) {
                        ConsoleLogger.log(phone, label + " payment: CONFIG_NOT_TODAY", "FAIL");
                        return new ShotResult(ShotOutcome.CONFIG_NOT_TODAY, null, null);
                    }
                } catch (Exception ignored) {
                }
            }

            if (raw.statusCode() == 404 && raw.body() != null) {
                try {
                    JsonObject json = gson.fromJson(raw.body(), JsonObject.class);
                    String msg = json.has("message") ? json.get("message").getAsString() : "";
                    if ("Appointment not found.".equals(msg)) {
                        ConsoleLogger.log(phone, label + " payment 404: Appointment not found — will restart", "FAIL");
                        return new ShotResult(ShotOutcome.APPOINTMENT_NOT_FOUND, null, null);
                    }
                } catch (Exception ignored) {
                }
            }

            if (raw.statusCode() == 401) {
                ConsoleLogger.log(phone, label + " payment 401", "RETRY");
                return new ShotResult(ShotOutcome.AUTH_FAILED, null, null);
            }

            if (raw.statusCode() == 429) {
                // Safety valve: an explicit "retry after N second(s)" beyond 30 min stops the
                // account. Uses the uncapped seconds parse (parseSigninRetryAfterMs caps at 15 min).
                long rawRetryAfterSec = HttpUtil.parseRetryAfterSeconds(raw.body());
                if (rawRetryAfterSec > 1800) {
                    ConsoleLogger.log(phone, label + " payment 429 — retry after " + rawRetryAfterSec + "s (>30 min) — stopping account", "FAIL");
                    return new ShotResult(ShotOutcome.FATAL_RATE_LIMIT, null, null);
                }

                // "You can pay after X minute(s) and Y second(s)" style — an explicit
                // account-scoped cooldown IVAC states verbatim; honor it as-is (capped at 15 min).
                long serverStatedMs = RetryUtil.parseSigninRetryAfterMs(raw.body());
                if (serverStatedMs > 0) {
                    ConsoleLogger.log(phone, label + " payment 429 — server-stated cooldown", "WAIT");
                    return new ShotResult(ShotOutcome.RATE_LIMITED, null, null);
                }

                // No server-stated wait — e.g. "Too many request detected", IVAC's edge/WAF
                // throttle rather than an account cooldown. Let the caller apply the escalating
                // backoff and fold the edge-throttle fallback client into the payment rotation.
                if (RetryUtil.isEdgeThrottleMessage(raw.body())) {
                    return new ShotResult(ShotOutcome.EDGE_THROTTLE_429, null, null);
                }

                ConsoleLogger.log(phone, label + " payment 429 — backing off " + (Constants.PAYMENT_429_BASE_BACKOFF_MS / 1000) + "s", "WAIT");
                return new ShotResult(ShotOutcome.RATE_LIMITED, null, null);
            }

            ConsoleLogger.log(phone, label + " payment HTTP " + raw.statusCode() + " — retrying", "RETRY");
            return ShotResult.retry();

        } catch (Exception e) {
            if (!Thread.currentThread().isInterrupted()) {
                ConsoleLogger.log(phone, label + " payment error: " + e.getMessage(), "RETRY");
            }
            return ShotResult.retry();
        }
    }

    /**
     * Fetches a fresh raw Turnstile token from the portal for the payment x-token header.
     * Returns null on failure so the caller can retry on the next tick.
     */
    private String fetchRawCaptcha(String label) {
        try {
            CaptchaToken token = portalCaptcha.requestCaptcha("raw", account.getPhone()).get();
            if (token == null || token.getToken() == null || token.getToken().isBlank()) {
                ConsoleLogger.log(account.getPhone(), label + " payment captcha empty — retrying", "RETRY");
                return null;
            }
            return token.getToken();
        } catch (Exception e) {
            if (e instanceof InterruptedException) {
                Thread.currentThread().interrupt();
            }
            ConsoleLogger.log(account.getPhone(), label + " payment captcha fetch failed: " + e.getMessage(), "RETRY");
            return null;
        }
    }

    private void ensureAppointmentId(String label) throws InterruptedException {
        String existing = account.getAppointmentId();
        if (existing != null && !existing.isBlank()) {
            return;
        }

        String phone = account.getPhone();
        ConsoleLogger.log(phone, label + " appointmentId missing — fetching from /appointment/get-booking-config", "PAYMENT");

        int attempt = 0;
        while (true) {
            if (Thread.currentThread().isInterrupted()) {
                throw new InterruptedException("Interrupted while fetching booking config");
            }
            attempt++;
            try {
                IvacHttpClient client = allClients.get(attempt % allClients.size());
                IvacHttpClient.RawResponse raw = client.getRaw(getBookingConfigPath);

                if (raw.statusCode() == 200 && raw.body() != null) {
                    String id = extractAppointmentId(raw.body());
                    if (id != null && !id.isBlank()) {
                        account.setAppointmentId(id);
                        ConsoleLogger.log(phone, label + " appointmentId fetched: " + id, "OK");
                        return;
                    }
                    ConsoleLogger.log(phone, label + " booking-config returned no appointmentId (attempt " + attempt + ") — retrying", "RETRY");
                } else {
                    ConsoleLogger.log(phone, label + " booking-config HTTP " + raw.statusCode() + " (attempt " + attempt + ") — retrying", "RETRY");
                }
            } catch (Exception e) {
                ConsoleLogger.log(phone, label + " booking-config error (attempt " + attempt + "): " + e.getMessage(), "RETRY");
            }
            Thread.sleep(account.getPaymentTickIntervalMs());
        }
    }

    private String extractAppointmentId(String responseBody) {
        try {
            JsonObject root = gson.fromJson(responseBody, JsonObject.class);
            if (root == null || !root.has("data") || !root.get("data").isJsonObject()) {
                return null;
            }
            JsonObject data = root.getAsJsonObject("data");
            if (!data.has("appointmentId") || data.get("appointmentId").isJsonNull()) {
                return null;
            }
            return data.get("appointmentId").getAsString();
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * Extract gateway URL from raw IVAC response body. Parses JSON directly so it works
     * for both /payment/ssl/initiate (redirectGatewayURL / GatewayPageURL) and
     * /payment/dg-epay/initiate (webview_url) regardless of model field state.
     */
    private String extractGatewayUrl(String responseBody) {
        if (responseBody == null || responseBody.isBlank()) {
            return null;
        }
        try {
            JsonObject root = gson.fromJson(responseBody, JsonObject.class);
            if (root == null) {
                return null;
            }
            JsonObject data = root.has("data") && root.get("data").isJsonObject()
                    ? root.getAsJsonObject("data")
                    : root;

            String[] keys = {"webview_url", "redirectGatewayURL", "GatewayPageURL", "gateway_page_url", "redirect_gateway_url"};
            for (String key : keys) {
                if (data.has(key) && !data.get(key).isJsonNull()) {
                    String url = data.get(key).getAsString();
                    if (url != null && !url.isEmpty()) {
                        return url;
                    }
                }
            }
        } catch (Exception ignored) {
        }
        return null;
    }

    private void postPaymentToIpmsWeb(String responseJson, String reservationId) {
        if (ipmsWebBaseUrl == null || ipmsWebBaseUrl.isBlank()) {
            return;
        }

        String url = ipmsWebBaseUrl.replaceAll("/$", "") + "/api/payment-links";
        JsonObject payload = gson.fromJson(responseJson, JsonObject.class);
        payload.addProperty("account_phone", account.getPhone());
        if (reservationId != null && !reservationId.isBlank()) {
            payload.addProperty("reservation_id", reservationId);
        }
        String bodyJson = gson.toJson(payload);

        Request request = new Request.Builder()
                .url(url)
                .post(RequestBody.create(bodyJson, JSON))
                .build();

        try (Response response = IPMS_WEB_CLIENT.newCall(request).execute()) {
            if (!response.isSuccessful()) {
                ConsoleLogger.log(account.getPhone(), "payment-links POST failed: HTTP " + response.code(), "WARN");
            }
        } catch (Exception e) {
            ConsoleLogger.log(account.getPhone(), "Failed to post payment link: " + e.getMessage(), "WARN");
        }
    }
}
