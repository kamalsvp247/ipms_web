package com.ivac.booking.model.domain;

/**
 * Result of a forgot-password sendOtp call.
 * channel is "sms" or "email" — used by the race to pick the right portal poll + verify path.
 * serverTimeMs is the IVAC server time (epoch ms) at OTP issuance — used as the `since` cutoff
 * when polling the portal so we never pick up stale rows from previous cycles.
 */
public class SendOtpResult {

    private final String requestId;
    private final String channel;
    private final long serverTimeMs;

    public SendOtpResult(String requestId, String channel, long serverTimeMs) {
        this.requestId = requestId;
        this.channel = channel;
        this.serverTimeMs = serverTimeMs;
    }

    public String getRequestId() {
        return requestId;
    }

    public String getChannel() {
        return channel;
    }

    public long getServerTimeMs() {
        return serverTimeMs;
    }
}
