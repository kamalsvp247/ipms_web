package com.ivac.booking.config;

import org.junit.jupiter.api.Test;

import java.util.HashMap;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the snapshot the bot swaps in when the portal extracts a rotated bundle mid-window. The
 * rules that matter: a partial or empty refresh must never downgrade a good value, and a published
 * snapshot must be immutable so a racing thread cannot observe a half-updated set.
 */
class ProtocolConstantsTest {

    private static ProtocolConstants sample() {
        return new ProtocolConstants(
                Map.of("signin", "/auth/v23-sign-in", "verifyOtp", "/otp/verifySigninOtp"),
                "reserve-old",
                "payment-old",
                "meta-old");
    }

    @Test
    void dropsNullAndBlankEntriesOnConstruction() {
        Map<String, String> raw = new HashMap<>();
        raw.put("signin", "/auth/v23-sign-in");
        raw.put("verifyOtp", "   ");
        raw.put("uploadFile", null);

        ProtocolConstants constants = new ProtocolConstants(raw, null, null, null);

        assertEquals("/auth/v23-sign-in", constants.endpoints().get("signin"));
        assertFalse(constants.endpoints().containsKey("verifyOtp"), "blank values must not be published");
        assertFalse(constants.endpoints().containsKey("uploadFile"), "null values must not be published");
    }

    @Test
    void publishedSnapshotIsImmutable() {
        Map<String, String> raw = new HashMap<>();
        raw.put("signin", "/auth/v23-sign-in");

        ProtocolConstants constants = new ProtocolConstants(raw, null, null, null);

        // Gson hands back a mutable map, so the defensive copy is what actually makes a swap safe.
        raw.put("signin", "/auth/mutated-after-construction");

        assertEquals("/auth/v23-sign-in", constants.endpoints().get("signin"));
        assertThrows(UnsupportedOperationException.class, () -> constants.endpoints().put("signin", "/x"));
    }

    @Test
    void mergeTakesFreshValuesPerKey() {
        ProtocolConstants merged = sample().mergedWith(new ProtocolConstants(
                Map.of("signin", "/auth/v24-sign-in"),
                "reserve-new",
                null,
                null));

        assertEquals("/auth/v24-sign-in", merged.endpoints().get("signin"), "fresh key must win");
        assertEquals("/otp/verifySigninOtp", merged.endpoints().get("verifyOtp"), "untouched key must survive");
        assertEquals("reserve-new", merged.reserveSlotId());
        assertEquals("payment-old", merged.paymentConfigId(), "omitted scalar must keep last-known-good");
        assertEquals("meta-old", merged.reserveRequestMeta());
    }

    @Test
    void emptyOrBlankRefreshCannotDowngradeGoodValues() {
        ProtocolConstants merged = sample().mergedWith(new ProtocolConstants(Map.of(), "  ", "", null));

        assertEquals("/auth/v23-sign-in", merged.endpoints().get("signin"));
        assertEquals("/otp/verifySigninOtp", merged.endpoints().get("verifyOtp"));
        assertEquals("reserve-old", merged.reserveSlotId());
        assertEquals("payment-old", merged.paymentConfigId());
        assertEquals("meta-old", merged.reserveRequestMeta());
    }

    @Test
    void mergeWithNullIsANoOp() {
        assertEquals(sample(), sample().mergedWith(null));
    }

    @Test
    void describeChangesReportsOnlyWhatMoved() {
        ProtocolConstants updated = sample().mergedWith(new ProtocolConstants(
                Map.of("signin", "/auth/v24-sign-in"), null, null, null));

        String changes = sample().describeChanges(updated);

        assertTrue(changes.contains("signin: /auth/v23-sign-in -> /auth/v24-sign-in"), changes);
        assertFalse(changes.contains("verifyOtp"), changes);
        assertFalse(changes.contains("paymentConfigId"), changes);
    }

    @Test
    void describeChangesIsEmptyWhenNothingMoved() {
        assertEquals("", sample().describeChanges(sample()));
    }

    @Test
    void nullEndpointsMapBecomesEmptyRatherThanNull() {
        ProtocolConstants constants = new ProtocolConstants(null, null, null, null);

        assertTrue(constants.endpoints().isEmpty());
        assertNull(constants.reserveSlotId());
    }
}
