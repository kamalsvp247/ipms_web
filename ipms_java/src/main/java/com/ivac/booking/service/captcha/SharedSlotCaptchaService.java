package com.ivac.booking.service.captcha;

import com.ivac.booking.model.domain.CaptchaToken;

import java.io.IOException;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicReference;

/**
 * Wraps a CaptchaService so all slot tasks share one token. When the token expires
 * or is rejected (null), exactly one re-fetch fires — all other callers wait on the
 * same CompletableFuture instead of each firing their own portal request.
 */
public class SharedSlotCaptchaService implements CaptchaService {

    private static final long AWAIT_TIMEOUT_MS = 70_000L;

    private final CaptchaService delegate;
    private final long shelfLifeMs;
    private final AtomicReference<CompletableFuture<CaptchaToken>> futureRef;

    public SharedSlotCaptchaService(CaptchaService delegate, long shelfLifeMs,
                                    CompletableFuture<CaptchaToken> initialFuture) {
        this.delegate = delegate;
        this.shelfLifeMs = shelfLifeMs;
        this.futureRef = new AtomicReference<>(initialFuture);
    }

    @Override
    public CaptchaToken fetch() throws IOException {
        return fetchIfExpired(null);
    }

    /**
     * Returns the shared token if still fresh. If expired or null, exactly one caller
     * fires a re-fetch via CAS; all others block on the same future.
     * The {@code existing} parameter is intentionally ignored — this service owns shared state.
     */
    @Override
    public CaptchaToken fetchIfExpired(CaptchaToken ignored) throws IOException {
        while (true) {
            CompletableFuture<CaptchaToken> current = futureRef.get();

            if (!current.isDone()) {
                return await(current);
            }

            CaptchaToken token;
            try {
                token = current.getNow(null);
            } catch (Exception e) {
                token = null;
            }

            if (token != null && !token.isOlderThan(shelfLifeMs)) {
                return token;
            }

            // Token expired or null — CAS: exactly one caller starts the re-fetch
            CompletableFuture<CaptchaToken> fresh = new CompletableFuture<>();
            if (futureRef.compareAndSet(current, fresh)) {
                Thread.ofVirtual().start(() -> {
                    try {
                        fresh.complete(delegate.fetch());
                    } catch (Exception e) {
                        fresh.completeExceptionally(e);
                    }
                });
                return await(fresh);
            }
            // Lost CAS — loop: winner's future is now in futureRef
        }
    }

    /**
     * Starts solving the next token during a caller's wait so the following fetchIfExpired()
     * returns a ready token instead of blocking on a fresh solve — the solve overlaps the slot
     * tick interval instead of stacking on top of it. Non-blocking.
     *
     * Skips when a solve is already in flight, or when the current token will still be within
     * shelf life after {@code usedInMs} (so short-interval callers keep reusing one token instead
     * of re-solving every tick). Fires a fresh solve otherwise.
     */
    public void prefetch(long usedInMs) {
        CompletableFuture<CaptchaToken> current = futureRef.get();
        if (!current.isDone()) {
            return; // a solve is already running — it will be ready for the next tick
        }

        CaptchaToken token;
        try {
            token = current.getNow(null);
        } catch (Exception e) {
            token = null;
        }

        long freshFor = shelfLifeMs - usedInMs;
        if (token != null && freshFor > 0 && !token.isOlderThan(freshFor)) {
            return; // current token will still be fresh when the next tick uses it
        }

        CompletableFuture<CaptchaToken> fresh = new CompletableFuture<>();
        if (futureRef.compareAndSet(current, fresh)) {
            Thread.ofVirtual().start(() -> {
                try {
                    fresh.complete(delegate.fetch());
                } catch (Exception e) {
                    fresh.completeExceptionally(e);
                }
            });
        }
    }

    /**
     * Forces a re-fetch if the shared token matches {@code rejected}.
     * Exactly one caller triggers the new fetch (CAS); others will pick it up on their next fetchIfExpired call.
     */
    @Override
    public void invalidate(CaptchaToken rejected) {
        if (rejected == null) return;
        CompletableFuture<CaptchaToken> current = futureRef.get();
        if (!current.isDone()) return;
        CaptchaToken existing;
        try {
            existing = current.getNow(null);
        } catch (Exception e) {
            existing = null;
        }
        if (existing != rejected) return;
        CompletableFuture<CaptchaToken> fresh = new CompletableFuture<>();
        if (futureRef.compareAndSet(current, fresh)) {
            Thread.ofVirtual().start(() -> {
                try {
                    fresh.complete(delegate.fetch());
                } catch (Exception e) {
                    fresh.completeExceptionally(e);
                }
            });
        }
    }

    private CaptchaToken await(CompletableFuture<CaptchaToken> future) throws IOException {
        try {
            return future.get(AWAIT_TIMEOUT_MS, TimeUnit.MILLISECONDS);
        } catch (ExecutionException e) {
            Throwable cause = e.getCause();
            throw new IOException(cause != null ? cause.getMessage() : e.getMessage(), cause);
        } catch (TimeoutException e) {
            throw new IOException("Shared captcha fetch timed out", e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new IOException("Interrupted waiting for shared captcha", e);
        }
    }
}
