package com.ivac.booking.util;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class RetryUtilTest {

    @Test
    void detectsDailyOtpLimitAsPermanentBlock() {
        String body = "{\"http_status\":429,\"message\":\"Too many OTP requests for this "
            + "phone number. Please try again tomorrow.\"}";
        assertTrue(RetryUtil.isSigninPermanentBlock(body));
    }

    @Test
    void tomorrowMatchIsCaseInsensitive() {
        String body = "{\"message\":\"Please try again TOMORROW\"}";
        assertTrue(RetryUtil.isSigninPermanentBlock(body));
    }

    @Test
    void shortCooldownIsNotAPermanentBlock() {
        String body = "{\"http_status\":429,\"message\":\"You can log in after 4 minute(s) "
            + "and 30 second(s).\"}";
        assertFalse(RetryUtil.isSigninPermanentBlock(body));
    }

    @Test
    void emptyOrUnparseableBodyIsNotAPermanentBlock() {
        assertFalse(RetryUtil.isSigninPermanentBlock(null));
        assertFalse(RetryUtil.isSigninPermanentBlock(""));
        assertFalse(RetryUtil.isSigninPermanentBlock("not json"));
        assertFalse(RetryUtil.isSigninPermanentBlock("{}"));
    }

    @Test
    void shortCooldownStillParsesRetryAfter() {
        String body = "{\"message\":\"You can log in after 4 minute(s) and 30 second(s).\"}";
        assertEquals((4 * 60L + 30L) * 1000L, RetryUtil.parseSigninRetryAfterMs(body));
    }

    @Test
    void permanentBlockMessageIsExtractedForLogging() {
        String body = "{\"message\":\"Too many OTP requests for this phone number. "
            + "Please try again tomorrow.\"}";
        assertEquals(
            "Too many OTP requests for this phone number. Please try again tomorrow.",
            RetryUtil.signinMessage(body));
        assertEquals("rate limited", RetryUtil.signinMessage("{}"));
    }

    @Test
    void edgeWafThrottleHasNoParseableWait() {
        String body = "{\"data\":null,\"statusCode\":429,\"message\":\"Too many request detected\","
            + "\"successFlag\":false,\"channel\":\"spellbound\"}";
        assertEquals(-1L, RetryUtil.parseSigninRetryAfterMs(body));
        assertFalse(RetryUtil.isSigninPermanentBlock(body));
        assertTrue(RetryUtil.isEdgeThrottleMessage(body));
    }

    @Test
    void serverStatedCooldownIsNotAnEdgeThrottle() {
        String body = "{\"message\":\"You can log in after 4 minute(s) and 30 second(s).\"}";
        assertFalse(RetryUtil.isEdgeThrottleMessage(body));
    }

    @Test
    void appOtpWindowLimitIsAPermanentBlock() {
        String body = "{\"http_status\":429,\"message\":\"Too many OTP requests. "
            + "Please try again after the current time window.\"}";
        assertEquals(-1L, RetryUtil.parseSigninRetryAfterMs(body));
        assertTrue(RetryUtil.isSigninPermanentBlock(body));
    }

    @Test
    void windowBlockMatchIsCaseInsensitive() {
        String body = "{\"message\":\"try again after the CURRENT TIME WINDOW\"}";
        assertTrue(RetryUtil.isSigninPermanentBlock(body));
    }

    @Test
    void waitlessRateLimitNeverRetriesImmediately() {
        assertTrue(RetryUtil.signinRateLimitCooldownMs(-1L, 0) > 0L);
        assertTrue(RetryUtil.signinRateLimitCooldownMs(0L, 0) > 0L);
    }

    @Test
    void waitlessRateLimitBackoffEscalatesFlat() {
        // Flat table, no jitter — the throttle backoff is exactly 4s, 8s, 12s.
        assertEquals(4_000L, RetryUtil.signinRateLimitCooldownMs(-1L, 0), "tier 0 = 4s");
        assertEquals(8_000L, RetryUtil.signinRateLimitCooldownMs(-1L, 1), "tier 1 = 8s");
        assertEquals(12_000L, RetryUtil.signinRateLimitCooldownMs(-1L, 2), "tier 2 = 12s");
        // Escalation caps at the 12s tier regardless of how many consecutive throttles occur.
        assertEquals(12_000L, RetryUtil.signinRateLimitCooldownMs(-1L, 99), "cap tier = 12s");
    }

    @Test
    void serverStatedWaitIsHonoredVerbatim() {
        assertEquals(39_000L, RetryUtil.signinRateLimitCooldownMs(39_000L, 5));
    }
}
