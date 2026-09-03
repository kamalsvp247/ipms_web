package com.ivac.booking.config;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;

/**
 * Adopts a mid-window IVAC endpoint rotation without restarting the race.
 *
 * IVAC serves a time-gated notice page that hides the JS bundle until roughly a minute or two after
 * the nominal window start, so the portal can only extract the rotated endpoint paths and deployment
 * IDs once the race is already running. The bot therefore starts with whatever constants it loaded at
 * cycle start and has to pick up the new ones in flight.
 *
 * The signal rides the captcha poll, which is already open at 500ms during exactly that period and is
 * the same gate that releases the bot: the portal only enables captcha providers after a clean
 * extraction, so a token implies the constants are already fresh. Every poll response carries the
 * portal's config version; a change triggers one background refresh of /api/config, and a ready token
 * briefly waits for that refresh so sign-in can never use a stale path.
 *
 * Only ProtocolConstants are swapped. Accounts, window times and tick schedules still require a
 * restart, because worker threads and tick schedules are built from them at cycle start.
 */
public final class ProtocolConstantsRefresher {

    private static final Logger log = LoggerFactory.getLogger(ProtocolConstantsRefresher.class);

    private static final ProtocolConstantsRefresher INSTANCE = new ProtocolConstantsRefresher();

    private final AtomicReference<String> appliedVersion = new AtomicReference<>();

    private final AtomicReference<CompletableFuture<Void>> inFlight = new AtomicReference<>();

    // Bumped by every bind(). A restart can rebind while a refresh is still running, and that refresh
    // must not write its result into the new cycle's state - the version it fetched describes the old
    // config object, so applying it would leave the new cycle believing it is up to date when it is not.
    private final AtomicInteger generation = new AtomicInteger();

    private volatile AppConfig target;

    private ProtocolConstantsRefresher() {
    }

    public static ProtocolConstantsRefresher getInstance() {
        return INSTANCE;
    }

    /**
     * Binds the config instance every worker shares, and records the version it arrived with so the
     * first poll does not trigger a pointless refresh. Called once per booking cycle.
     */
    public void bind(AppConfig config) {
        generation.incrementAndGet();
        target = config;
        appliedVersion.set(config != null ? config.getConfigVersion() : null);
        inFlight.set(null);
    }

    /**
     * Records the config version the portal reported on a captcha poll. Starts a background refresh
     * when it differs from what is currently applied; does nothing on the common unchanged path, so
     * the steady state costs one string comparison per poll.
     */
    public void onVersionSeen(String version) {
        if (version == null || version.isBlank()) {
            return;
        }

        AppConfig config = target;

        if (config == null || version.equals(appliedVersion.get())) {
            return;
        }

        CompletableFuture<Void> claim = new CompletableFuture<>();

        // Only one refresh at a time; a concurrent version bump is picked up by the next poll.
        if (!inFlight.compareAndSet(null, claim)) {
            return;
        }

        int startedAt = generation.get();

        log.info("[Config] Portal reports config version {} (holding {}) — refreshing IVAC constants",
                version, appliedVersion.get());

        Thread.ofVirtual().name("protocol-constants-refresh").start(() -> {
            try {
                refresh(config, startedAt);
            } catch (Exception e) {
                log.warn("[Config] Constants refresh threw: {}", e.getMessage());
            } finally {
                // Clear only our own claim: a rebind may already have installed a newer one.
                inFlight.compareAndSet(claim, null);
                claim.complete(null);
            }
        });
    }

    /**
     * Blocks up to timeoutMs for an in-flight refresh, so a token handed out the moment the gate opens
     * is not used with constants the portal has already replaced. Bounded and failure-tolerant:
     * racing with slightly stale constants beats stalling the race.
     */
    public void awaitPending(long timeoutMs) {
        CompletableFuture<Void> pending = inFlight.get();

        if (pending == null) {
            return;
        }

        try {
            pending.get(timeoutMs, TimeUnit.MILLISECONDS);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        } catch (TimeoutException e) {
            log.warn("[Config] Constants refresh did not finish within {}ms — proceeding with current values", timeoutMs);
        } catch (ExecutionException e) {
            log.warn("[Config] Constants refresh failed: {} — proceeding with current values", e.getMessage());
        }
    }

    private void refresh(AppConfig config, int startedAt) {
        AppConfig fresh = ConfigLoader.load();

        // Never clobber a working set on a failed fetch. The portal being unreachable says nothing
        // about whether the constants we hold still work.
        if (fresh == null) {
            log.warn("[Config] Constants refresh failed (portal unreachable) — keeping current values");

            return;
        }

        // A restart rebound us onto a different config while this was in flight; the result describes
        // the previous cycle, so drop it rather than mark the new cycle as up to date.
        if (generation.get() != startedAt) {
            log.info("[Config] Constants refresh discarded — the booking cycle restarted while it ran");

            return;
        }

        String changes = config.applyProtocolConstants(fresh.getProtocolConstants());
        appliedVersion.set(fresh.getConfigVersion());

        if (changes.isEmpty()) {
            log.info("[Config] Constants refreshed at version {} — no value changed", fresh.getConfigVersion());
        } else {
            log.info("[Config] IVAC constants updated live at version {} — {}", fresh.getConfigVersion(), changes);
        }
    }
}
