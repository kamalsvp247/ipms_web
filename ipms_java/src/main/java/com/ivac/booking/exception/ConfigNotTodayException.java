package com.ivac.booking.exception;

/**
 * Thrown when the payment API returns CONFIG_NOT_TODAY.
 * This is a fatal condition — booking is not available today.
 * The account worker must stop immediately without retrying.
 */
public class ConfigNotTodayException extends Exception {

    public ConfigNotTodayException(String message) {
        super(message);
    }
}
