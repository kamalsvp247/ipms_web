package com.ivac.booking.model.domain;

public class SigninResult {

    private final String accessToken;
    private final long sessionExpiresAtMs;
    private final String signinRequestId;
    private final long serverTimeMs;

    public SigninResult(String accessToken, long sessionExpiresAtMs, String signinRequestId, long serverTimeMs) {
        this.accessToken = accessToken;
        this.sessionExpiresAtMs = sessionExpiresAtMs;
        this.signinRequestId = signinRequestId;
        this.serverTimeMs = serverTimeMs;
    }

    public String getAccessToken() {
        return accessToken;
    }

    public long getSessionExpiresAtMs() {
        return sessionExpiresAtMs;
    }

    public String getSigninRequestId() {
        return signinRequestId;
    }

    public long getServerTimeMs() {
        return serverTimeMs;
    }
}
