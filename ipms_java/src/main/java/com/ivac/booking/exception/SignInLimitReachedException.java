package com.ivac.booking.exception;

/**
 * Thrown when an account has reached its per-day sign-in cap (AccountConfig.maxRetries).
 * The worker stops for the account instead of establishing another session.
 */
public class SignInLimitReachedException extends Exception {

    public SignInLimitReachedException(String message) {
        super(message);
    }
}
