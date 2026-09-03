package com.ivac.booking.util;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.IOException;
import java.util.Random;
import java.util.function.Predicate;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class RetryUtil {

    private static final Random random = new Random();
    private static final long OTP_LIMIT_WAIT_MS = 5 * 60 * 1000L; // 5 minutes

    private static final long SIGNIN_429_MAX_COOLDOWN_MS = 15 * 60_000L;
    private static final Pattern SIGNIN_429_MINUTES = Pattern.compile("(\\d+)\\s*minute", Pattern.CASE_INSENSITIVE);
    private static final Pattern SIGNIN_429_SECONDS = Pattern.compile("(\\d+)\\s*second", Pattern.CASE_INSENSITIVE);
    private static final Pattern SIGNIN_429_TOMORROW = Pattern.compile("tomorrow", Pattern.CASE_INSENSITIVE);
    private static final Pattern SIGNIN_429_WINDOW_BLOCK = Pattern.compile("current time window", Pattern.CASE_INSENSITIVE);
    private static final Pattern EDGE_THROTTLE_MESSAGE = Pattern.compile("too many request", Pattern.CASE_INSENSITIVE);

    /**
     * Extract the "message" string from an IVAC JSON error body.
     * Returns null when the body is missing, unparseable, or has no non-empty message.
     */
    private static String extractMessage(String body) {
        if (body == null || body.isEmpty()) {
            return null;
        }
        try {
            JsonObject json = JsonParser.parseString(body).getAsJsonObject();
            if (!json.has("message") || json.get("message").isJsonNull()) {
                return null;
            }
            String message = json.get("message").getAsString();
            return (message == null || message.isEmpty()) ? null : message;
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * Returns true if a sign-in 429 body indicates a daily/window-scoped block rather than a
     * short cooldown — retrying within the current booking window cannot succeed, so the
     * account must stop. Two known variants:
     * - "Too many OTP requests for this phone number. Please try again tomorrow."
     * - "Too many OTP requests. Please try again after the current time window."
     */
    public static boolean isSigninPermanentBlock(String body) {
        String message = extractMessage(body);
        return message != null
            && (SIGNIN_429_TOMORROW.matcher(message).find()
                || SIGNIN_429_WINDOW_BLOCK.matcher(message).find());
    }

    /**
     * Returns true for the Cloudflare edge/WAF throttle — "Too many request detected",
     * successFlag:false, no minute/second hint — as opposed to an IVAC-app-level cooldown that
     * states an explicit wait. This is IP-scoped, not account-scoped, and appears on both
     * sign-in and reserve-slot 429s, so callers on either endpoint fall back to a proxy client
     * rather than just backing off.
     */
    public static boolean isEdgeThrottleMessage(String body) {
        String message = extractMessage(body);
        return message != null && EDGE_THROTTLE_MESSAGE.matcher(message).find();
    }

    /**
     * Return the IVAC "message" string from an error body for logging, or a generic
     * fallback when no message is present.
     */
    public static String signinMessage(String body) {
        String message = extractMessage(body);
        return message != null ? message : "rate limited";
    }

    /**
     * Parse the IVAC sign-in 429 body and return the server-stated cooldown in ms.
     * Returns -1 if the body cannot be parsed or carries no minute/second hint.
     * Capped at 15 minutes so a malformed-but-huge value can't deadlock a worker.
     */
    public static long parseSigninRetryAfterMs(String body) {
        String message = extractMessage(body);
        if (message == null) {
            return -1L;
        }

        long minutes = 0L;
        long seconds = 0L;
        boolean found = false;

        Matcher m = SIGNIN_429_MINUTES.matcher(message);
        if (m.find()) {
            try {
                minutes = Long.parseLong(m.group(1));
                found = true;
            } catch (NumberFormatException ignored) {}
        }
        Matcher s = SIGNIN_429_SECONDS.matcher(message);
        if (s.find()) {
            try {
                seconds = Long.parseLong(s.group(1));
                found = true;
            } catch (NumberFormatException ignored) {}
        }
        if (!found) {
            return -1L;
        }

        long totalMs = (minutes * 60L + seconds) * 1000L;
        return Math.min(totalMs, SIGNIN_429_MAX_COOLDOWN_MS);
    }

    // Apply jitter (±percentage)
    public static long applyJitter(long baseMs, double jitterFraction) {
        double jitterRange = baseMs * jitterFraction;
        double jitterValue = (random.nextDouble() - 0.5) * 2 * jitterRange;
        return baseMs + (long) jitterValue;
    }

    // Return delay with jitter (±25% default)
    public static long jitteredDelayMs(long baseMs) {
        return applyJitter(baseMs, 0.25);
    }

    // HTTP 429 backoff table—for slot reservation
    // 4s, 8s, 12s backoffs
    public static long base429BackoffMs(int consecutive429s) {
        long[] backoffTable = {4_000L, 8_000L, 12_000L};
        return backoffTable[Math.min(consecutive429s, backoffTable.length - 1)];
    }

    // Get 429 backoff with jitter already applied
    public static long get429BackoffMs(int consecutive429s) {
        return jitteredDelayMs(base429BackoffMs(consecutive429s));
    }

    /**
     * Cooldown a single sign-in loop must wait after a 429 before firing again.
     * When the server states an explicit wait (parseSigninRetryAfterMs > 0) that value is
     * honored verbatim. Otherwise the 429 carries no stated wait — e.g. a Cloudflare edge/WAF
     * throttle ("Too many request detected") — and re-firing immediately would sustain the
     * block. (Window-scoped blocks like "try again after the current time window" never reach
     * here — isSigninPermanentBlock stops the account first.) A flat escalating backoff
     * (exactly 4s, 8s, 12s — no jitter)
     * keyed on the count of consecutive wait-less 429s is applied instead. Never returns 0,
     * so the loop can never busy-retry a 429.
     */
    public static long signinRateLimitCooldownMs(long serverStatedMs, int consecutiveWaitlessRateLimits) {
        if (serverStatedMs > 0) {
            return serverStatedMs;
        }
        return base429BackoffMs(Math.max(0, consecutiveWaitlessRateLimits));
    }

    // Determine delay based on signin HTTP status
    public static long getSigninRetryDelayMs(int httpStatus, long windowStartMs) {
        if (httpStatus == 503) {
            // 503: Server maintenance - standard 5s delay
            // Use 1-2s delay in final 30s of booking window
            long now = System.currentTimeMillis();
            long timeUntilWindow = windowStartMs - now;

            if (windowStartMs > 0 && timeUntilWindow > 0 && timeUntilWindow <= 30_000) {
                // Jittered 1-2s wait in final 30s
                return applyJitter(1_500, 0.33); // 1s-2s ±33%
            }
            return 5_000; // Standard 5s delay
        }

        if (httpStatus == 429) {
            // 429: Rate limited - 7s wait
            return applyJitter(7_000, 0.25); // 7s ±25%
        }

        if (httpStatus == 400) {
            // 400: Captcha invalid - retry immediately
            return 0;
        }

        // Other errors: 3s wait
        return 3_000;
    }

    // Wait 5 minutes on OTP limit
    public static boolean isOtpLimitError(Exception e) {
        String msg = e.getMessage();
        return msg != null && msg.toLowerCase().contains("limit");
    }

    public static void waitForOtpLimit() throws InterruptedException {
        Thread.sleep(OTP_LIMIT_WAIT_MS);
    }

    // Retry OTP verify on socket timeout
    public static <T> T retryOnSocketTimeout(Operation<T> operation, int maxAttempts, long delayMs) throws IOException {
        IOException lastError = null;

        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                return operation.execute();
            } catch (java.net.SocketTimeoutException e) {
                lastError = e;
                if (attempt < maxAttempts) {
                    try {
                        Thread.sleep(delayMs);
                    } catch (InterruptedException ie) {
                        Thread.currentThread().interrupt();
                        throw new IOException("Interrupted during OTP verify", ie);
                    }
                }
            }
        }

        throw lastError != null ? lastError : new IOException("OTP verify timeout");
    }

    // Functional interface—can throw IOException
    @FunctionalInterface
    public interface Operation<T> {
        T execute() throws IOException;
    }

    // Generic retry with validator—checks result
    public static <T> T retry(Operation<T> operation, Predicate<T> validator, int maxAttempts, long delayMs, String description) throws IOException {
        IOException lastError = null;

        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                T result = operation.execute();
                if (validator.test(result)) {
                    return result;
                }
                // Validator failed - retry
                if (attempt < maxAttempts) {
                    try {
                        TimeUtil.sleepMs(delayMs);
                    } catch (InterruptedException ie) {
                        Thread.currentThread().interrupt();
                        throw new IOException("Interrupted during " + description, ie);
                    }
                }
            } catch (IOException e) {
                lastError = e;
                if (attempt < maxAttempts) {
                    try {
                        TimeUtil.sleepMs(delayMs);
                    } catch (InterruptedException ie) {
                        Thread.currentThread().interrupt();
                        throw new IOException("Interrupted during " + description, ie);
                    }
                }
            }
        }

        throw lastError != null ? lastError : new IOException(description + " failed after " + maxAttempts + " attempts");
    }
}
