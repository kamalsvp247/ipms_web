package com.ivac.booking.service.otp;

import com.ivac.booking.model.domain.OtpVerifyResult;
import com.ivac.booking.model.domain.SendOtpResult;
import com.ivac.booking.service.PortalOtpClient;

import java.io.IOException;

public interface OtpService {

    /**
     * Triggers a forgot-password OTP on the given channel.
     * channel must be "sms" (sends SMS) or "email" (sends email).
     */
    SendOtpResult sendOtp(String email, String channel) throws IOException, InterruptedException;

    SendOtpResult sendOtp(String email, String channel, long deadlineMs) throws IOException, InterruptedException;

    /**
     * Polls the portal /api/otp/{phone} endpoint for an OTP on the given channel arrived
     * at or after {@code sinceMs - tolerance}. Returns null on timeout.
     */
    PortalOtpClient.OtpResult pollPortal(String channel, long sinceMs, long timeoutMs) throws InterruptedException;

    /**
     * Verifies an OTP code against a requestId on the given channel.
     * channel maps to the IVAC otpChannel field: "sms" → "PHONE", "email" → "EMAIL".
     */
    OtpVerifyResult verifyOtp(String requestId, String otpCode, String channel) throws IOException;

    /**
     * Cancels any OTP verify HTTP calls currently in flight on this service's client.
     * Called when a slot is reserved — the reservation proves OTP is already verified, so
     * pending verify calls are redundant and are aborted immediately instead of blocking
     * their virtual threads until the server responds or the socket times out.
     * Returns the number of calls cancelled.
     */
    int cancelInFlightVerify();
}
