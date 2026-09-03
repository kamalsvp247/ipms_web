package com.ivac.booking.util;

public class SessionExpiryValidator {
    private final long sessionExpiresAtMs;
    private final long signInTimeMs;

    public SessionExpiryValidator(long sessionExpiresAtMs) {
        this.sessionExpiresAtMs = sessionExpiresAtMs;
        this.signInTimeMs = System.currentTimeMillis();
    }

    public boolean isSessionExpired() {
        return System.currentTimeMillis() >= sessionExpiresAtMs;
    }

    public long getSignInAgeSeconds() {
        return (System.currentTimeMillis() - signInTimeMs) / 1000;
    }

    public long getTimeUntilExpirySeconds() {
        long remainingMs = sessionExpiresAtMs - System.currentTimeMillis();
        return remainingMs > 0 ? remainingMs / 1000 : 0;
    }
}
