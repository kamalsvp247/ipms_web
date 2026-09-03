package com.ivac.booking.service.captcha;

import com.ivac.booking.model.domain.CaptchaToken;
import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.jupiter.api.Assertions.assertEquals;

class SharedSlotCaptchaServiceTest {

    private static CaptchaService countingDelegate(AtomicInteger fetches) {
        return new CaptchaService() {
            @Override
            public CaptchaToken fetch() {
                return new CaptchaToken("solved-" + fetches.incrementAndGet());
            }

            @Override
            public CaptchaToken fetchIfExpired(CaptchaToken existing) {
                return fetch();
            }
        };
    }

    @Test
    void prefetchSolvesNextTokenSoNextFetchDoesNotReSolve() throws IOException {
        AtomicInteger fetches = new AtomicInteger();
        // Initial token is fresh now, but shelf life 20s and it is used 21s later — stale by then.
        CompletableFuture<CaptchaToken> initial = CompletableFuture.completedFuture(new CaptchaToken("initial"));
        SharedSlotCaptchaService service = new SharedSlotCaptchaService(countingDelegate(fetches), 20_000L, initial);

        service.prefetch(21_000L);

        // fetchIfExpired returns the prefetched token (awaiting it if still in flight); it must
        // not trigger a second solve.
        CaptchaToken token = service.fetchIfExpired(null);
        assertEquals("solved-1", token.getToken());
        assertEquals(1, fetches.get());
    }

    @Test
    void prefetchSkipsWhenCurrentTokenStaysFresh() {
        AtomicInteger fetches = new AtomicInteger();
        CompletableFuture<CaptchaToken> initial = CompletableFuture.completedFuture(new CaptchaToken("fresh"));
        SharedSlotCaptchaService service = new SharedSlotCaptchaService(countingDelegate(fetches), 20_000L, initial);

        // Used again in 1s with a 20s shelf life — still fresh, so no solve should fire.
        service.prefetch(1_000L);
        assertEquals(0, fetches.get());
    }

    @Test
    void prefetchNoOpsWhileASolveIsAlreadyInFlight() {
        AtomicInteger fetches = new AtomicInteger();
        CompletableFuture<CaptchaToken> inFlight = new CompletableFuture<>(); // never completes
        SharedSlotCaptchaService service = new SharedSlotCaptchaService(countingDelegate(fetches), 20_000L, inFlight);

        service.prefetch(21_000L);
        assertEquals(0, fetches.get());
    }
}
