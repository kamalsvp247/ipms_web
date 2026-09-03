package com.ivac.booking.model.domain;

public class OtpVerifyResult {

    public enum Status {
        VERIFIED,
        ALREADY_VERIFIED,
        CODE_MISMATCH,
        EXPIRED,
        FAILED,
        RATE_LIMITED
    }

    private final Status status;
    private final String serverTime;
    private final long backoffMs;
    // True when the 429 was IVAC's edge/WAF throttle ("Too many request detected", no
    // server-stated wait) rather than a real account-scoped cooldown — callers escalate the
    // backoff and fold a proxy client into rotation instead of just waiting backoffMs.
    private final boolean edgeThrottle;

    private OtpVerifyResult(Status status, String serverTime, long backoffMs, boolean edgeThrottle) {
        this.status = status;
        this.serverTime = serverTime;
        this.backoffMs = backoffMs;
        this.edgeThrottle = edgeThrottle;
    }

    public static OtpVerifyResult verified(String serverTime) {
        return new OtpVerifyResult(Status.VERIFIED, serverTime, 0, false);
    }

    public static OtpVerifyResult alreadyVerified(String serverTime) {
        return new OtpVerifyResult(Status.ALREADY_VERIFIED, serverTime, 0, false);
    }

    public static OtpVerifyResult mismatch() {
        return new OtpVerifyResult(Status.CODE_MISMATCH, null, 0, false);
    }

    public static OtpVerifyResult expired() {
        return new OtpVerifyResult(Status.EXPIRED, null, 0, false);
    }

    public static OtpVerifyResult failed(String reason) {
        return new OtpVerifyResult(Status.FAILED, null, 0, false);
    }

    public static OtpVerifyResult rateLimited(long backoffMs) {
        return new OtpVerifyResult(Status.RATE_LIMITED, null, backoffMs, false);
    }

    public static OtpVerifyResult edgeThrottled() {
        return new OtpVerifyResult(Status.RATE_LIMITED, null, 0, true);
    }

    public boolean isVerified() {
        return status == Status.VERIFIED || status == Status.ALREADY_VERIFIED;
    }

    public Status getStatus() {
        return status;
    }

    public String getServerTime() {
        return serverTime;
    }

    public long getBackoffMs() {
        return backoffMs;
    }

    public boolean isEdgeThrottle() {
        return edgeThrottle;
    }
}
