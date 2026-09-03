package com.ivac.booking.exception;

import java.io.IOException;

public class CaptchaInvalidException extends IOException {

    public CaptchaInvalidException(String message) {
        super(message);
    }
}
