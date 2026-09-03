package com.ivac.booking.service.otp;

import com.ivac.booking.model.domain.OtpVerifyResult;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class OtpServiceImplTest {

    @Test
    void status400AlreadyVerifiedIsIdempotentSuccess() {
        String body = "{\"http_status\":400,\"message\":\"OTP Already verified\"}";
        assertTrue(OtpServiceImpl.isIdempotentAlreadyVerified(400, body));
    }

    @Test
    void status400AlreadyVerifiedIsCaseInsensitive() {
        assertTrue(OtpServiceImpl.isIdempotentAlreadyVerified(400,
            "{\"message\":\"otp already VERIFIED\"}"));
    }

    @Test
    void unrelated400IsNotTreatedAsVerified() {
        String body = "{\"status\":400,\"error\":\"Captcha verification failed. Please try again\"}";
        assertFalse(OtpServiceImpl.isIdempotentAlreadyVerified(400, body));
    }

    @Test
    void status404NotFoundStaysIdempotentSuccess() {
        assertTrue(OtpServiceImpl.isIdempotentAlreadyVerified(404,
            "{\"message\":\"Request not found\"}"));
    }

    @Test
    void unrelated404IsNotTreatedAsVerified() {
        assertFalse(OtpServiceImpl.isIdempotentAlreadyVerified(404,
            "{\"message\":\"Something else went wrong\"}"));
    }

    @Test
    void success200BodyIsNeverIdempotentBranch() {
        String body = "{\"data\":{\"verified\":true},\"statusCode\":200,\"message\":\"Success\"}";
        assertFalse(OtpServiceImpl.isIdempotentAlreadyVerified(200, body));
    }

    @Test
    void nullBodyIsNotVerified() {
        assertFalse(OtpServiceImpl.isIdempotentAlreadyVerified(400, null));
        assertFalse(OtpServiceImpl.isIdempotentAlreadyVerified(404, null));
    }

    @Test
    void alreadyVerifiedResultCountsAsVerified() {
        assertTrue(OtpVerifyResult.alreadyVerified(null).isVerified());
    }
}
