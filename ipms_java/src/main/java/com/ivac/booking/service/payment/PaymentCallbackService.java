package com.ivac.booking.service.payment;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.exception.RestartSignInException;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.worker.RaceOrchestrator;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Post-payment redirect-callback flow.
 *
 * After initiate-payment succeeds the human completes payment in a browser and pastes the
 * resulting redirect URL into the portal. This service keeps the worker alive, polls the
 * portal for that URL (matched by reservationId == tran_id), then fires it across all bypass
 * clients in the same tick pattern as initiate-payment and reports the outcome back.
 */
public class PaymentCallbackService {

    private static final long POLL_INTERVAL_MS = 3_000L;

    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    private static final OkHttpClient PORTAL_CLIENT = new OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build();

    private enum ShotOutcome { SUCCESS, TERMINAL_FAILURE, RETRY }

    private record ShotResult(ShotOutcome outcome, int statusCode, String body) {
        static ShotResult retry() {
            return new ShotResult(ShotOutcome.RETRY, 0, null);
        }
    }

    private final AccountConfig account;
    private final List<IvacHttpClient> allClients;
    private final String ipmsWebBaseUrl;
    private final Gson gson = new Gson();

    public PaymentCallbackService(AccountConfig account, List<IvacHttpClient> allClients, String ipmsWebBaseUrl) {
        this.account = account;
        this.allClients = allClients;
        this.ipmsWebBaseUrl = ipmsWebBaseUrl;
    }

    /**
     * Wait for the portal to deliver a redirect URL for this reservationId, fire it across all
     * bypass clients, and report the result. Blocks until success, a terminal failure, the JWT
     * expires, or the worker thread is interrupted (manual stop).
     *
     * A payment link on its own is not a completed booking — the checkout can go unpaid or expire.
     * So when no redirect URL arrives before the JWT dies, this throws RestartSignInException and
     * the worker starts a fresh cycle (new sign-in, new race) instead of parking here forever.
     *
     * @param jwtExpiresAtMs epoch ms at which the session behind this payment expires
     */
    public void awaitAndFire(String reservationId, long jwtExpiresAtMs)
        throws InterruptedException, RestartSignInException {
        String phone = account.getPhone();

        if (reservationId == null || reservationId.isBlank()) {
            ConsoleLogger.log(phone, "No reservationId — skipping post-payment callback wait", "WARN");
            return;
        }
        if (ipmsWebBaseUrl == null || ipmsWebBaseUrl.isBlank()) {
            ConsoleLogger.log(phone, "No portal URL — skipping post-payment callback wait", "WARN");
            return;
        }

        long waitSeconds = Math.max(0L, (jwtExpiresAtMs - System.currentTimeMillis()) / 1000L);
        ConsoleLogger.log(phone, "Payment initiated — waiting up to " + waitSeconds
            + "s for redirect URL from portal (reservation " + reservationId + ")", "WAIT");

        String callbackUrl = pollForCallbackUrl(reservationId, jwtExpiresAtMs);
        if (callbackUrl == null) {
            if (System.currentTimeMillis() >= jwtExpiresAtMs) {
                throw new RestartSignInException(
                    "No redirect URL before JWT expiry — restarting cycle to race for a new payment link");
            }

            return;
        }

        ConsoleLogger.log(phone, "Redirect URL received — firing callback GET across "
            + allClients.size() + " client(s)", "PAYMENT");

        fireCallback(reservationId, callbackUrl);
    }

    /**
     * Poll the portal for this reservation's redirect URL, giving up at the deadline.
     *
     * Returns null both when the deadline passes and when the thread is interrupted; the caller
     * tells them apart by the clock, since only the former warrants a restart.
     */
    private String pollForCallbackUrl(String reservationId, long deadlineMs) throws InterruptedException {
        String phone = account.getPhone();
        String url = ipmsWebBaseUrl.replaceAll("/$", "") + "/api/payment-callback?reservation_id="
            + URLEncoder.encode(reservationId, StandardCharsets.UTF_8);

        while (!Thread.currentThread().isInterrupted() && System.currentTimeMillis() < deadlineMs) {
            try {
                Request request = new Request.Builder().url(url).get().build();
                try (Response response = PORTAL_CLIENT.newCall(request).execute()) {
                    String body = response.body() != null ? response.body().string() : "";
                    if (response.isSuccessful() && !body.isBlank()) {
                        JsonObject json = gson.fromJson(body, JsonObject.class);
                        if (json != null && json.has("callback_url") && !json.get("callback_url").isJsonNull()) {
                            String cb = json.get("callback_url").getAsString();
                            if (cb != null && !cb.isBlank()) {
                                return cb;
                            }
                        }
                    }
                }
            } catch (Exception e) {
                ConsoleLogger.log(phone, "Callback poll error: " + e.getMessage(), "RETRY");
            }
            Thread.sleep(POLL_INTERVAL_MS);
        }
        return null;
    }

    private void fireCallback(String reservationId, String callbackUrl) throws InterruptedException {
        String phone = account.getPhone();
        int tickShots = account.getPaymentTickShots();
        long tickIntervalMs = account.getPaymentTickIntervalMs();
        List<List<IvacHttpClient>> tickSchedule = RaceOrchestrator.buildTickSchedule(allClients, tickShots);
        int numTicks = tickSchedule.size();
        int tickIndex = 0;

        while (!Thread.currentThread().isInterrupted()) {
            long tickStart = System.currentTimeMillis();
            List<IvacHttpClient> tick = tickSchedule.get(tickIndex % numTicks);
            tickIndex++;

            ShotResult result = fireOneTick(tick, callbackUrl, tickIndex);

            switch (result.outcome()) {
                case SUCCESS -> {
                    ConsoleLogger.log(phone, "Callback GET succeeded — payment confirmed (" + result.body() + ")", "OK");
                    reportResult(reservationId, "success", result.statusCode(), result.body());
                    return;
                }
                case TERMINAL_FAILURE -> {
                    ConsoleLogger.log(phone, "Callback GET failed (terminal) — HTTP " + result.statusCode(), "FAIL");
                    reportResult(reservationId, "failed", result.statusCode(), result.body());
                    return;
                }
                case RETRY -> {
                    long sleepMs = tickIntervalMs - (System.currentTimeMillis() - tickStart);
                    if (sleepMs > 0) {
                        Thread.sleep(sleepMs);
                    }
                }
            }
        }
    }

    private ShotResult fireOneTick(List<IvacHttpClient> tick, String callbackUrl, int tickIndex)
            throws InterruptedException {
        int totalShots = tick.size();
        ShotResult[] results = new ShotResult[totalShots];
        java.util.Arrays.fill(results, ShotResult.retry());

        CompletableFuture<ShotResult> winner = new CompletableFuture<>();
        AtomicInteger completed = new AtomicInteger(0);

        ConsoleLogger.log(account.getPhone(), "Callback tick " + tickIndex + " → firing "
            + totalShots + " shot(s)", "PAYMENT");

        for (int i = 0; i < totalShots; i++) {
            final int idx = i;
            final IvacHttpClient client = tick.get(i);

            Thread.ofVirtual().start(() -> {
                ShotResult r;
                try {
                    IvacHttpClient.RedirectResponse raw = client.getAbsoluteNoAuthNoRedirect(callbackUrl);
                    int code = raw.statusCode();
                    // A 3xx redirect (typically 302 -> /payment/fail) means the gateway accepted
                    // the callback — that is success regardless of the Location target.
                    if (code >= 300 && code < 400) {
                        String detail = "HTTP " + code + " Location: " + (raw.location() != null ? raw.location() : "(none)");
                        r = new ShotResult(ShotOutcome.SUCCESS, code, detail);
                    } else if (code >= 400 && code < 500) {
                        r = new ShotResult(ShotOutcome.TERMINAL_FAILURE, code, raw.body());
                    } else {
                        r = new ShotResult(ShotOutcome.RETRY, code, raw.body());
                    }
                } catch (Throwable t) {
                    r = ShotResult.retry();
                }
                results[idx] = r;

                if (r.outcome() == ShotOutcome.SUCCESS) {
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
            if (r.outcome() == ShotOutcome.SUCCESS) {
                return r;
            }
            if (r.outcome() == ShotOutcome.TERMINAL_FAILURE && best.outcome() == ShotOutcome.RETRY) {
                best = r;
            }
        }
        return best;
    }

    private void reportResult(String reservationId, String status, int statusCode, String responseBody) {
        String phone = account.getPhone();
        String url = ipmsWebBaseUrl.replaceAll("/$", "") + "/api/payment-callback/result";

        JsonObject payload = new JsonObject();
        payload.addProperty("reservation_id", reservationId);
        payload.addProperty("status", status);
        payload.addProperty("status_code", statusCode);
        if (responseBody != null) {
            payload.addProperty("response", responseBody.length() > 65000
                ? responseBody.substring(0, 65000) : responseBody);
        }

        Request request = new Request.Builder()
                .url(url)
                .post(RequestBody.create(gson.toJson(payload), JSON))
                .build();

        try (Response response = PORTAL_CLIENT.newCall(request).execute()) {
            if (!response.isSuccessful()) {
                ConsoleLogger.log(phone, "Callback result POST failed: HTTP " + response.code(), "WARN");
            }
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Failed to post callback result: " + e.getMessage(), "WARN");
        }
    }
}
