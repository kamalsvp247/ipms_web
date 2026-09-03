package com.ivac.booking.worker;

import org.junit.jupiter.api.Test;

import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class RaceContextTest {

    @Test
    void slotReservedFiresOtpVerifyCancellersExactlyOnce() {
        RaceContext context = new RaceContext();
        AtomicInteger cancels = new AtomicInteger();
        context.registerOtpVerifyCanceller(cancels::incrementAndGet);

        // First caller wins the CAS and triggers the cancellers.
        assertTrue(context.setSlotReserved());
        assertEquals(1, cancels.get());

        // Losing the CAS a second time must not re-fire the cancellers.
        assertFalse(context.setSlotReserved());
        assertEquals(1, cancels.get());

        // Reservation implies OTP is verified.
        assertTrue(context.isSlotReserved());
        assertTrue(context.isOtpVerified());
    }

    @Test
    void cancellersDoNotFireBeforeSlotReserved() {
        RaceContext context = new RaceContext();
        AtomicInteger cancels = new AtomicInteger();
        context.registerOtpVerifyCanceller(cancels::incrementAndGet);

        // A plain OTP verification (no reservation) must not abort in-flight verify calls.
        assertTrue(context.setOtpVerified("2026-07-07T07:29:15.000Z"));
        assertEquals(0, cancels.get());
    }

    @Test
    void allRegisteredCancellersFireOnReservation() {
        RaceContext context = new RaceContext();
        AtomicInteger first = new AtomicInteger();
        AtomicInteger second = new AtomicInteger();
        context.registerOtpVerifyCanceller(first::incrementAndGet);
        context.registerOtpVerifyCanceller(second::incrementAndGet);

        context.setSlotReserved();

        assertEquals(1, first.get());
        assertEquals(1, second.get());
    }

    @Test
    void aThrowingCancellerDoesNotBlockTheReservation() {
        RaceContext context = new RaceContext();
        AtomicInteger reached = new AtomicInteger();
        context.registerOtpVerifyCanceller(() -> {
            throw new RuntimeException("client already closed");
        });
        context.registerOtpVerifyCanceller(reached::incrementAndGet);

        // A canceller that throws must not stop the reservation or the remaining cancellers.
        assertTrue(context.setSlotReserved());
        assertEquals(1, reached.get());
        assertTrue(context.isSlotReserved());
    }

    @Test
    void setupGateHoldsSlotsUntilMarkedCompleteAndOpensWhenNotRequired() throws InterruptedException {
        // Not required: gate is open so slot threads never wait.
        RaceContext open = new RaceContext();
        open.setSetupRequired(false);
        long start = System.currentTimeMillis();
        open.awaitSetupComplete(5_000L);
        assertTrue(System.currentTimeMillis() - start < 1_000L);

        // Required: a slot waiter blocks until setup is marked complete.
        RaceContext gated = new RaceContext();
        gated.setSetupRequired(true);
        AtomicInteger released = new AtomicInteger();
        Thread waiter = Thread.ofVirtual().start(() -> {
            try {
                gated.awaitSetupComplete(5_000L);
                released.incrementAndGet();
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        });
        Thread.sleep(100L);
        assertEquals(0, released.get());
        gated.markSetupComplete();
        waiter.join(2_000L);
        assertEquals(1, released.get());
    }
}
