package com.ivac.booking.exception;

/**
 * Thrown when the current sign-in session is no longer valid and
 * the entire auth cycle must restart from sendOtp → signin.
 */
public class RestartSignInException extends Exception {

    public RestartSignInException(String message) {
        super(message);
    }

    public RestartSignInException(String message, Throwable cause) {
        super(message, cause);
    }
}
