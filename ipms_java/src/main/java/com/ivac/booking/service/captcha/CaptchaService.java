package com.ivac.booking.service.captcha;

import com.ivac.booking.model.domain.CaptchaToken;

import java.io.IOException;

public interface CaptchaService {
    CaptchaToken fetch() throws IOException;
    CaptchaToken fetchIfExpired(CaptchaToken existing) throws IOException;

    /** Signals that {@code rejected} was refused by the server. No-op by default. */
    default void invalidate(CaptchaToken rejected) {}
}
