package com.ivac.booking.worker;

import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.model.domain.SigninResult;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * A restart cycle must reuse the JWT this process just minted. Signing in again inside IVAC's
 * cooldown returns 429 "You can log in after N minute(s)" and blocks the worker while it is
 * holding a valid token.
 */
class AccountWorkerSessionReuseTest {

    private static final long NOW = 1_700_000_000_000L;

    private static AccountConfig account() {
        AccountConfig account = new AccountConfig();
        account.setPhone("01700000000");
        return account;
    }

    private static SigninResult signinResult(long expiresAtMs) {
        return new SigninResult("jwt-token", expiresAtMs, "req-123", NOW);
    }

    @Test
    void freshConfigHasNothingToReuse() {
        assertFalse(AccountWorker.hasReusableJwt(account(), NOW),
            "no stored token — the cycle must sign in");
    }

    @Test
    void sessionMintedThisRunSurvivesIntoTheNextCycle() {
        AccountConfig account = account();

        AccountWorker.adoptSession(account, signinResult(NOW + 899_000L));

        assertTrue(AccountWorker.hasReusableJwt(account, NOW),
            "restart cycle reuses the JWT instead of re-signing into the cooldown");
        assertEquals("jwt-token", account.getAccessToken());
        assertEquals(NOW + 899_000L, account.getJwtExpiresAtMs());
        assertEquals("req-123", account.getSigninRequestId(),
            "requestId carries over so the race keeps the signin OTP pair");
        assertEquals(NOW, account.getSigninServerTimeMs());
    }

    @Test
    void tokenAboutToExpireIsNotReused() {
        AccountConfig account = account();
        AccountWorker.adoptSession(account, signinResult(NOW + 20_000L));

        assertFalse(AccountWorker.hasReusableJwt(account, NOW),
            "under the 30s guard it would expire mid-race — sign in instead");
    }

    @Test
    void expiredTokenIsNotReused() {
        AccountConfig account = account();
        AccountWorker.adoptSession(account, signinResult(NOW - 1L));

        assertFalse(AccountWorker.hasReusableJwt(account, NOW));
    }

    @Test
    void freshSignInClearsTheOtpVerifiedFlagOfThePreviousSession() {
        AccountConfig account = account();
        account.setOtpVerified(true);

        AccountWorker.adoptSession(account, signinResult(NOW + 899_000L));

        assertFalse(account.isOtpVerified(),
            "a new JWT has not been OTP-verified yet — reusing it must not skip the OTP phase");
    }

    @Test
    void missingRequestIdLeavesThePreviousOneUntouched() {
        AccountConfig account = account();
        account.setSigninRequestId("earlier-req");

        AccountWorker.adoptSession(account, new SigninResult("jwt-token", NOW + 899_000L, null, 0L));

        assertEquals("earlier-req", account.getSigninRequestId());
    }
}
