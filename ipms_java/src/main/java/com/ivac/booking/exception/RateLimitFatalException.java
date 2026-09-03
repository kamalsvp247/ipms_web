package com.ivac.booking.exception;

/**
 * Thrown when IVAC returns a 429 with a retry-after period exceeding 30 minutes.
 * Unchecked so it propagates naturally out of virtual-thread lambdas.
 */
public class RateLimitFatalException extends RuntimeException {

    private final long retryAfterSeconds;

    public RateLimitFatalException(String message, long retryAfterSeconds) {
        super(message);
        this.retryAfterSeconds = retryAfterSeconds;
    }

    public long getRetryAfterSeconds() {
        return retryAfterSeconds;
    }
}
