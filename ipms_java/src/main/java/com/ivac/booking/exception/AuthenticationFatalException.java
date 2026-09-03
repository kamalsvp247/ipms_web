package com.ivac.booking.exception;

/**
 * Signals an unrecoverable authentication failure, such as invalid credentials.
 */
public class AuthenticationFatalException extends Exception {

    public AuthenticationFatalException(String message) {
        super(message);
    }
}

