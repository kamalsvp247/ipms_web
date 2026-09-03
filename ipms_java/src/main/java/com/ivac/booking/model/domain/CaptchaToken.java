package com.ivac.booking.model.domain;

public class CaptchaToken {

    private final String token;
    private final long createdAtMs;

    public CaptchaToken(String token) {
        this.token = token;
        this.createdAtMs = System.currentTimeMillis();
    }

    public CaptchaToken(String token, long createdAtMs) {
        this.token = token;
        this.createdAtMs = createdAtMs;
    }

    /**
     * Staleness always takes the shelf life as an argument, so it can only ever be the portal's
     * captchaShelfLifeMs. A no-arg isExpired() used to exist against a hardcoded 20s constant;
     * it was unreferenced but would have silently ignored the portal setting the moment anyone
     * called it, so both it and the constant were removed rather than left as a trap.
     */
    public boolean isOlderThan(long ms) {
        return System.currentTimeMillis() > createdAtMs + ms;
    }

    public String getToken() {
        return token;
    }
}
