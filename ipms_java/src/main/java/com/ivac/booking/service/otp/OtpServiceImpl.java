package com.ivac.booking.service.otp;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.Constants;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.model.domain.OtpVerifyResult;
import com.ivac.booking.model.domain.SendOtpResult;
import com.ivac.booking.model.request.OtpVerifyRequest;
import com.ivac.booking.model.response.ForgotPasswordResponse;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.service.PortalOtpClient;
import com.ivac.booking.exception.RateLimitFatalException;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.HttpUtil;
import com.ivac.booking.util.RetryUtil;

import java.io.IOException;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ThreadLocalRandom;

public class OtpServiceImpl implements OtpService {

    private static final long SEND_OTP_RETRY_DELAY_MS = 2_000L;
    private static final long SEND_OTP_429_BACKOFF_MS = 15_000L;
    // Compiled-in fallback paths used only when the config omits them (bundle-extracted values win).
    private static final String OTP_VERIFY_PATH = "/otp/verifySigninOtp";
    private static final String SEND_OTP_PATH = "/forgot-password/sendOtp";

    private final Gson gson = new Gson();
    private final AccountConfig account;
    private final IvacHttpClient client;
    private final List<IvacHttpClient> sendOtpClients;
    private final PortalOtpClient portalOtp;

    // Held rather than snapshotted: the portal can rotate the endpoint paths mid-window, so every call
    // re-reads them through the AppConfig getters (which fall back to the constants above per key).
    private final AppConfig appConfig;

    private final long otpIntervalDelayMs;

    public OtpServiceImpl(AccountConfig account, IvacHttpClient client, AppConfig appConfig,
                          PortalOtpClient portalOtp, long otpIntervalDelayMs) {
        this(account, client, List.of(client), appConfig, portalOtp, otpIntervalDelayMs);
    }

    public OtpServiceImpl(AccountConfig account, IvacHttpClient client, List<IvacHttpClient> sendOtpClients,
                          AppConfig appConfig, PortalOtpClient portalOtp, long otpIntervalDelayMs) {
        this.account = account;
        this.client = client;
        this.sendOtpClients = sendOtpClients.isEmpty() ? List.of(client) : sendOtpClients;
        this.portalOtp = portalOtp;
        this.otpIntervalDelayMs = otpIntervalDelayMs;
        this.appConfig = appConfig;
    }

    // Package-private so the no-caching regression test can assert these follow a live config swap.
    String verifyOtpPath() {
        return appConfig != null ? appConfig.getVerifyOtpPath() : OTP_VERIFY_PATH;
    }

    String sendOtpPath() {
        return appConfig != null ? appConfig.getSendOtpPath() : SEND_OTP_PATH;
    }

    @Override
    public SendOtpResult sendOtp(String email, String channel) throws IOException, InterruptedException {
        return sendOtp(email, channel, Long.MAX_VALUE);
    }

    @Override
    public SendOtpResult sendOtp(String email, String channel, long deadlineMs) throws IOException, InterruptedException {
        String phone = account.getPhone();
        String ivacChannel = toIvacChannel(channel);
        int consecutive429s = 0;
        IvacHttpClient sendClient = pickRandomSendOtpClient(phone);

        while (!Thread.currentThread().isInterrupted()) {
            if (System.currentTimeMillis() >= deadlineMs) {
                throw new IOException("window opened — aborting sendOtp retries");
            }

            try {
                ConsoleLogger.log(phone, "→ Sending OTP request to forgot-password endpoint (channel=" + channel + ")", "AUTH");

                IvacHttpClient.RawResponse raw = sendClient.postRawNoAuthRetry(
                        sendOtpPath(),
                        Map.of("email", email, "phone", phone, "otpChannel", ivacChannel));

                if (raw.statusCode() == 429) {
                    throw new IOException("429");
                }

                if (raw.statusCode() >= 200 && raw.statusCode() < 300) {
                    ForgotPasswordResponse response = gson.fromJson(raw.body(), ForgotPasswordResponse.class);
                    if (response != null
                            && response.getData() != null
                            && response.getData().getRequestId() != null) {
                        String requestId = response.getData().getRequestId();
                        long serverTimeMs = parseServerTimeMs(raw.body());
                        ConsoleLogger.log(phone, "sendOtp(" + channel + ") success — requestId: " + requestId
                                + ", serverTime: " + serverTimeMs, "AUTH");
                        return new SendOtpResult(requestId, channel, serverTimeMs);
                    }

                    ConsoleLogger.log(phone, "sendOtp(" + channel + "): empty response — retrying", "RETRY");
                    Thread.sleep(SEND_OTP_RETRY_DELAY_MS);
                    continue;
                }

                if (raw.statusCode() >= 400) {
                    throw new IOException("HTTP " + raw.statusCode() + ": " + raw.body());
                }

                ConsoleLogger.log(phone, "sendOtp(" + channel + "): unexpected status " + raw.statusCode() + " — retrying", "RETRY");
                Thread.sleep(SEND_OTP_RETRY_DELAY_MS);

            } catch (IOException e) {
                String msg = e.getMessage() != null ? e.getMessage() : "";

                if (msg.contains("window opened")) {
                    throw e;
                }

                sendClient = pickRandomSendOtpClient(phone);

                if (msg.contains("429")) {
                    long backoff = RetryUtil.jitteredDelayMs(SEND_OTP_429_BACKOFF_MS * (++consecutive429s));
                    ConsoleLogger.log(phone, "sendOtp(" + channel + ") 429 — backing off " + (backoff / 1000) + "s", "WAIT");
                    Thread.sleep(backoff);
                } else if (msg.contains("HTTP 4")) {
                    ConsoleLogger.log(phone, "sendOtp(" + channel + ") client error: " + msg + " — retrying in 3s", "RETRY");
                    Thread.sleep(3_000L);
                } else {
                    ConsoleLogger.log(phone, "sendOtp(" + channel + ") error: " + msg + " — retrying in 2s", "RETRY");
                    Thread.sleep(SEND_OTP_RETRY_DELAY_MS);
                }

                consecutive429s = msg.contains("429") ? consecutive429s : 0;
            }
        }

        throw new InterruptedException("sendOtp interrupted");
    }

    private IvacHttpClient pickRandomSendOtpClient(String phone) {
        if (sendOtpClients.size() == 1) {
            return sendOtpClients.get(0);
        }
        int idx = ThreadLocalRandom.current().nextInt(sendOtpClients.size());
        ConsoleLogger.log(phone, "sendOtp: switching to bypass client [" + idx + "] for retry", "RETRY");
        return sendOtpClients.get(idx);
    }

    @Override
    public PortalOtpClient.OtpResult pollPortal(String channel, long sinceMs, long timeoutMs) throws InterruptedException {
        String phone = account.getPhone();
        long deadline = System.currentTimeMillis() + timeoutMs;
        ConsoleLogger.log(phone, "Polling portal OTP (channel=" + channel + ", since="
                + sinceMs + ", timeout=" + (timeoutMs / 1000) + "s)", "POLLING");
        return portalOtp.pollUntil(channel, sinceMs, deadline, otpIntervalDelayMs, channel);
    }

    @Override
    public OtpVerifyResult verifyOtp(String requestId, String otpCode, String channel) throws IOException {
        return verifyOtp(requestId, otpCode, channel, 0);
    }

    private OtpVerifyResult verifyOtp(String requestId, String otpCode, String channel, int attempt) throws IOException {
        String phone = account.getPhone();
        String ivacChannel = toIvacChannel(channel);

        OtpVerifyRequest request = new OtpVerifyRequest(
                requestId, phone, account.getEmail(), otpCode, ivacChannel);

        IvacHttpClient.RawResponse raw = client.postRawNoAuthRetry(verifyOtpPath(), request, 70_000);

        if (raw.statusCode() == 403) {
            long base = Constants.OTP_VERIFY_403_BACKOFF_MS[Math.min(attempt, Constants.OTP_VERIFY_403_BACKOFF_MS.length - 1)];
            long delay = RetryUtil.jitteredDelayMs(base);
            ConsoleLogger.log(phone, "OTP verify 403 — retrying in " + delay + "ms (attempt " + (attempt + 1) + ")", "RETRY");
            try { Thread.sleep(delay); } catch (InterruptedException ie) { Thread.currentThread().interrupt(); }
            return verifyOtp(requestId, otpCode, channel, attempt + 1);
        }

        if (raw.statusCode() == 429) {
            // Safety valve: an explicit "retry after N second(s)" beyond 30 min stops the
            // account. Uses the uncapped seconds parse (parseSigninRetryAfterMs caps at 15 min,
            // which would hide a genuinely huge cooldown).
            long rawRetryAfterSec = HttpUtil.parseRetryAfterSeconds(raw.body());
            if (rawRetryAfterSec > 1800) {
                throw new RateLimitFatalException(
                    "OTP verify 429 — retry after " + rawRetryAfterSec + "s (>30 min) — stopping account",
                    rawRetryAfterSec);
            }

            // "You can verify after X minute(s) and Y second(s)" style — an explicit
            // account-scoped cooldown IVAC states verbatim; honor it as-is (capped at 15 min).
            long serverStatedMs = RetryUtil.parseSigninRetryAfterMs(raw.body());
            if (serverStatedMs > 0) {
                ConsoleLogger.log(phone, "OTP verify 429 — retry after " + (serverStatedMs / 1000) + "s (server-stated) — backing off", "WARN");
                return OtpVerifyResult.rateLimited(serverStatedMs);
            }

            // No server-stated wait — e.g. "Too many request detected", IVAC's edge/WAF
            // throttle on this worker's egress IP rather than an account cooldown. The caller
            // (RaceOrchestrator) applies an escalating backoff and folds a proxy client into
            // the OTP verify rotation, same pattern as sign-in's edge-throttle fallback.
            if (RetryUtil.isEdgeThrottleMessage(raw.body())) {
                ConsoleLogger.log(phone, "OTP verify edge 429 (\"too many request detected\") — escalating", "WARN");
                return OtpVerifyResult.edgeThrottled();
            }

            ConsoleLogger.log(phone, "OTP verify 429 — retry after " + rawRetryAfterSec + "s — backing off", "WARN");
            return OtpVerifyResult.rateLimited(rawRetryAfterSec * 1000L);
        }

        if (isIdempotentAlreadyVerified(raw.statusCode(), raw.body())) {
            ConsoleLogger.log(phone, "OTP " + raw.statusCode() + " — already verified (idempotent)", "OK");
            return OtpVerifyResult.alreadyVerified(parseServerTime(raw.body()));
        }

        if (raw.statusCode() == 404) {
            ConsoleLogger.log(phone, "OTP verify failed: " + raw.body(), "WARN");
            return OtpVerifyResult.failed("unexpected 404");
        }

        if (raw.statusCode() != 200) {
            throw new IOException("OTP verify HTTP " + raw.statusCode());
        }

        JsonObject json = gson.fromJson(raw.body(), JsonObject.class);
        JsonObject data = json.has("data") && json.get("data").isJsonObject()
                ? json.getAsJsonObject("data")
                : null;

        boolean dataVerified = data != null
                && data.has("verified")
                && !data.get("verified").isJsonNull()
                && data.get("verified").getAsBoolean();

        if (dataVerified) {
            ConsoleLogger.log(phone, "OTP verified successfully (channel=" + channel + ")", "OK");
            return OtpVerifyResult.verified(parseServerTime(raw.body()));
        }

        String verificationStatus = data != null && data.has("verificationStatus") && !data.get("verificationStatus").isJsonNull()
                ? data.get("verificationStatus").getAsString()
                : "";
        String topMessage = json.has("message") && !json.get("message").isJsonNull()
                ? json.get("message").getAsString()
                : "";
        String reason = !verificationStatus.isEmpty() ? verificationStatus : topMessage;
        String reasonLower = reason.toLowerCase();

        if (reasonLower.contains("expired")) {
            ConsoleLogger.log(phone, "OTP verify returned EXPIRED — re-sign-in required", "WARN");
            return OtpVerifyResult.expired();
        }

        if (reasonLower.contains("does not match") || reasonLower.contains("mismatch")) {
            return OtpVerifyResult.mismatch();
        }

        ConsoleLogger.log(phone, "OTP verify failed: " + reason, "WARN");
        return OtpVerifyResult.failed(reason);
    }

    /**
     * True when an OTP verify error response actually means the code is already accepted, so
     * the caller must treat it as a successful verification rather than an error. IVAC signals
     * this idempotently in two shapes: a 404 whose body says the request/OTP was "not found"
     * (already consumed), or a 400 whose body says "OTP Already verified". The pairings are kept
     * specific so an unrelated 400 (e.g. captcha rejected) is never mistaken for success.
     */
    static boolean isIdempotentAlreadyVerified(int statusCode, String body) {
        if (body == null) {
            return false;
        }
        String lower = body.toLowerCase();
        return (statusCode == 404 && lower.contains("not found"))
            || (statusCode == 400 && lower.contains("already verified"));
    }

    private String parseServerTime(String body) {
        if (body == null) {
            return null;
        }
        try {
            JsonObject json = gson.fromJson(body, JsonObject.class);
            return json.has("serverTime") ? json.get("serverTime").getAsString() : null;
        } catch (Exception e) {
            return null;
        }
    }

    private long parseServerTimeMs(String body) {
        String iso = parseServerTime(body);
        if (iso == null || iso.isBlank()) {
            return System.currentTimeMillis();
        }
        try {
            return Instant.parse(iso).toEpochMilli();
        } catch (Exception e) {
            return System.currentTimeMillis();
        }
    }

    @Override
    public int cancelInFlightVerify() {
        return client.cancelCallsForPath(verifyOtpPath());
    }

    private static String toIvacChannel(String channel) {
        if ("email".equalsIgnoreCase(channel)) {
            return "EMAIL";
        }
        return "PHONE";
    }
}
