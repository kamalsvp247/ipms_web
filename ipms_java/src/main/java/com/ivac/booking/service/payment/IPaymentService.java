package com.ivac.booking.service.payment;

import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.exception.ConfigNotTodayException;
import com.ivac.booking.exception.RestartSignInException;
import com.ivac.booking.worker.RaceContext;

/**
 * Handles payment initiation after slot reservation.
 * POSTs to ipms_web on success and returns the gateway URL.
 */
public interface IPaymentService {

    /**
     * Initiates payment and returns the payment gateway URL.
     * Retries on transient errors. Checks reservation TTL.
     *
     * @param context shared race state (used for TTL check)
     * @param label   thread label for logging
     * @return payment gateway URL
     */
    String initiatePayment(RaceContext context, String label)
            throws RestartSignInException, ConfigNotTodayException, AuthenticationFatalException, InterruptedException;
}
