package com.ivac.booking.service;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.Constants;
import com.ivac.booking.networking.ApiLogInterceptor;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.EnvLoader;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.Closeable;
import java.io.IOException;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

/**
 * Polls the portal's /api/otp/{phone} endpoint for OTP codes captured from SMS or Gmail.
 * Replaces the legacy Firebase polling path.
 *
 * URL pattern: GET {PORTAL_URL}/api/otp/{phone}?channel=sms|email&since={epoch_ms}&consume=1
 * Auth: Bearer {slot.api.key}
 *
 * Success response (200):
 *   { "otp_code": "123456", "channel": "sms", "fetched_at": "...", "fetched_at_ms": 1779... }
 * Not-yet-arrived response (404):
 *   { "error": "not_found" }
 */
public class PortalOtpClient implements Closeable {

    private static final Logger log = LoggerFactory.getLogger(PortalOtpClient.class);
    private static final Gson GSON = new Gson();

    public static class OtpResult {
        public final String otpCode;
        public final String channel;
        public final long fetchedAtMs;

        public OtpResult(String otpCode, String channel, long fetchedAtMs) {
            this.otpCode = otpCode;
            this.channel = channel;
            this.fetchedAtMs = fetchedAtMs;
        }
    }

    private final String phone;
    private final String baseUrl;
    private final String bearerToken;
    private final OkHttpClient httpClient;

    public PortalOtpClient(String phone) {
        this.phone = phone;
        this.baseUrl = Constants.PORTAL_URL + "/api/otp/" + URLEncoder.encode(phone, StandardCharsets.UTF_8);

        String slotApiKey = System.getProperty("slot.api.key");
        if (slotApiKey == null || slotApiKey.isBlank()) {
            slotApiKey = EnvLoader.get("SLOT_API_KEY");
        }
        this.bearerToken = (slotApiKey != null && !slotApiKey.isBlank()) ? slotApiKey : null;

        this.httpClient = new OkHttpClient.Builder()
                .addInterceptor(new ApiLogInterceptor(phone, null))
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(15, TimeUnit.SECONDS)
                .build();
    }

    /**
     * Fetches OTP once without retry. Returns null if no matching OTP exists yet (404) or on transport error.
     *
     * @param channel  "sms" | "email" | null (any)
     * @param sinceMs  epoch ms cutoff; rows older than (sinceMs - tolerance) are excluded by the portal
     */
    public OtpResult fetchOnce(String channel, long sinceMs) {
        String url = buildUrl(channel, sinceMs, true);
        Request.Builder rb = new Request.Builder().url(url).get();
        if (bearerToken != null) {
            rb.addHeader("Authorization", "Bearer " + bearerToken);
        }

        long startMs = System.currentTimeMillis();

        try (Response response = httpClient.newCall(rb.build()).execute()) {
            long durationMs = System.currentTimeMillis() - startMs;

            if (response.code() == 404) {
                ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ", since=" + sinceMs
                        + ") → 404 no match (" + durationMs + "ms)", "POLLING");
                return null;
            }
            if (!response.isSuccessful()) {
                ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ") → HTTP " + response.code()
                        + " (" + durationMs + "ms)", "POLLING");
                return null;
            }

            ResponseBody body = response.body();
            if (body == null) {
                ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ") → 200 empty body ("
                        + durationMs + "ms)", "POLLING");
                return null;
            }

            JsonObject json = GSON.fromJson(body.string(), JsonObject.class);
            if (json == null || !json.has("otp_code") || json.get("otp_code").isJsonNull()) {
                ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ") → 200 no otp_code field ("
                        + durationMs + "ms)", "POLLING");
                return null;
            }

            String code = json.get("otp_code").getAsString();
            String ch = json.has("channel") && !json.get("channel").isJsonNull()
                    ? json.get("channel").getAsString()
                    : (channel != null ? channel : "sms");
            long fetchedAtMs = json.has("fetched_at_ms") && !json.get("fetched_at_ms").isJsonNull()
                    ? json.get("fetched_at_ms").getAsLong()
                    : 0L;

            if (code != null && !code.isEmpty()) {
                ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ") → 200 OTP=" + code
                        + " (" + durationMs + "ms)", "POLLING");
                return new OtpResult(code, ch, fetchedAtMs);
            }
            return null;
        } catch (IOException e) {
            long durationMs = System.currentTimeMillis() - startMs;
            ConsoleLogger.log(phone, "Poll /api/otp (" + channel + ") → IO error: " + e.getMessage()
                    + " (" + durationMs + "ms)", "POLLING");
            return null;
        }
    }

    /**
     * Polls until an OTP is found or the deadline passes. Sleeps {@code intervalMs} between attempts.
     * Returns null on timeout. Throws on interrupt.
     */
    public OtpResult pollUntil(String channel, long sinceMs, long deadlineMs, long intervalMs, String label)
            throws InterruptedException {
        int attempt = 0;
        while (!Thread.currentThread().isInterrupted()) {
            if (System.currentTimeMillis() >= deadlineMs) {
                ConsoleLogger.log(phone, "Portal OTP poll (" + label + ") timed out after " + attempt + " attempts", "WARN");
                return null;
            }

            attempt++;
            OtpResult result = fetchOnce(channel, sinceMs);
            if (result != null && result.otpCode != null && !result.otpCode.isEmpty()) {
                return result;
            }

            if (attempt % 5 == 0) {
                long remainingMs = deadlineMs - System.currentTimeMillis();
                ConsoleLogger.log(phone, "Portal OTP poll (" + label + ") attempt " + attempt
                        + ", " + Math.max(0, remainingMs / 1000) + "s remaining", "POLLING");
            }

            Thread.sleep(intervalMs);
        }
        throw new InterruptedException("Portal OTP poll interrupted");
    }

    private String buildUrl(String channel, long sinceMs, boolean consume) {
        StringBuilder sb = new StringBuilder(baseUrl).append('?');
        boolean first = true;
        if (channel != null && (channel.equals("sms") || channel.equals("email"))) {
            sb.append("channel=").append(channel);
            first = false;
        }
        if (sinceMs > 0) {
            if (!first) sb.append('&');
            sb.append("since=").append(sinceMs);
            first = false;
        }
        if (!consume) {
            if (!first) sb.append('&');
            sb.append("consume=0");
        }
        return sb.toString();
    }

    @Override
    public void close() {
        httpClient.dispatcher().cancelAll();
        httpClient.dispatcher().executorService().shutdown();
        httpClient.connectionPool().evictAll();
    }
}
