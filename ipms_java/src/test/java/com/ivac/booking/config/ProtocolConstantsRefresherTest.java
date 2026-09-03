package com.ivac.booking.config;

import com.google.gson.Gson;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertDoesNotThrow;
import static org.junit.jupiter.api.Assertions.assertEquals;

/**
 * Covers the refresher's guard rails. The refresh itself needs a live portal, so these focus on the
 * decisions made around it: not refetching when nothing changed, tolerating an unbound refresher, and
 * discarding a refresh whose booking cycle restarted underneath it.
 */
class ProtocolConstantsRefresherTest {

    private static final Gson GSON = new Gson();

    private ProtocolConstantsRefresher refresher;

    @BeforeEach
    void setUp() {
        refresher = ProtocolConstantsRefresher.getInstance();
        refresher.bind(null);
    }

    @Test
    void ignoresVersionsBeforeAConfigIsBound() {
        refresher.bind(null);

        // The captcha poll can fire before the booking cycle binds its config; that must be inert.
        assertDoesNotThrow(() -> refresher.onVersionSeen("some-version"));
    }

    @Test
    void ignoresBlankAndUnchangedVersions() {
        AppConfig config = GSON.fromJson("{\"configVersion\":\"v1\",\"reserveSlotId\":\"reserve-live\"}", AppConfig.class);
        refresher.bind(config);

        // Every poll carries the version, so the unchanged path is the hot one and must not refetch.
        refresher.onVersionSeen("v1");
        refresher.onVersionSeen(null);
        refresher.onVersionSeen("   ");

        assertEquals("reserve-live", config.getReserveSlotId());
    }

    @Test
    void awaitPendingReturnsImmediatelyWhenNothingIsInFlight() {
        refresher.bind(GSON.fromJson("{\"configVersion\":\"v1\"}", AppConfig.class));

        long startedAt = System.currentTimeMillis();
        refresher.awaitPending(5_000);

        // No refresh running means no wait — this sits on the sign-in critical path.
        assertEquals(true, System.currentTimeMillis() - startedAt < 1_000);
    }

    @Test
    void rebindingResetsTheVersionItTracks() {
        refresher.bind(GSON.fromJson("{\"configVersion\":\"v1\"}", AppConfig.class));

        AppConfig restarted = GSON.fromJson("{\"configVersion\":\"v2\",\"reserveSlotId\":\"reserve-v2\"}", AppConfig.class);
        refresher.bind(restarted);

        // After a restart the bot holds v2, so a poll reporting v2 must not trigger a refetch.
        refresher.onVersionSeen("v2");

        assertEquals("reserve-v2", restarted.getReserveSlotId());
    }
}
