package com.ivac.booking.config;

import com.google.gson.Gson;
import org.junit.jupiter.api.Test;

import java.util.Arrays;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertSame;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the high-commission rotation order used by booking config. IVAC answers
 * 400 "Invalid High Commission." when the configured centre does not match the applicant's web file,
 * so the bot walks every centre instead of reposting the rejected one until the JWT expires.
 */
class BookingCityOptionTest {

    private static final List<BookingCityOption> PORTAL_LIST = List.of(
        new BookingCityOption("Dhaka", "Dhaka", "IVAC, Dhaka (JFP)"),
        new BookingCityOption("Khulna", "Khulna", "IVAC, KHULNA"),
        new BookingCityOption("Sylhet", "Sylhet", "IVAC, SYLHET"));

    @Test
    void triesTheConfiguredCentreFirstThenEveryOtherOnce() {
        List<BookingCityOption> ordered =
            BookingCityOption.orderedFor("Sylhet", "IVAC, SYLHET", PORTAL_LIST);

        assertEquals(List.of("Sylhet", "Dhaka", "Khulna"),
            ordered.stream().map(BookingCityOption::mission).toList(),
            "the operator's choice is the best guess, so it goes first — and is not repeated");
    }

    @Test
    void keepsAConfiguredPairThePortalListNoLongerCarries() {
        List<BookingCityOption> ordered =
            BookingCityOption.orderedFor("Rangpur", "IVAC, RANGPUR", PORTAL_LIST);

        assertEquals(4, ordered.size());
        assertEquals("Rangpur", ordered.get(0).mission());
        assertEquals("Dhaka", ordered.get(1).mission());
    }

    @Test
    void fallsBackToEveryCentreWhenTheAccountHasNoCity() {
        List<BookingCityOption> ordered = BookingCityOption.orderedFor(null, null, PORTAL_LIST);

        assertEquals(PORTAL_LIST, ordered);
    }

    @Test
    void dropsHalfFilledEntriesRatherThanPostingThem() {
        List<BookingCityOption> pool = Arrays.asList(
            new BookingCityOption("Dhaka", "Dhaka", "IVAC, Dhaka (JFP)"),
            new BookingCityOption("Broken", "Khulna", "  "),
            null);

        List<BookingCityOption> ordered = BookingCityOption.orderedFor(null, null, pool);

        assertEquals(1, ordered.size());
        assertEquals("Dhaka", ordered.get(0).mission());
        assertFalse(new BookingCityOption("Broken", "Khulna", "  ").isUsable());
    }

    /**
     * An older portal that does not send bookingCityOptions must still leave the rotation somewhere
     * to go, otherwise the fix silently degrades back to reposting the rejected centre.
     */
    @Test
    void usesTheCompiledInListWhenTheConfigOmitsTheKey() {
        AppConfig config = new Gson().fromJson("{}", AppConfig.class);

        assertSame(BookingCityOption.DEFAULTS, config.getBookingCityOptions());
        assertEquals(BookingCityOption.DEFAULTS,
            BookingCityOption.orderedFor(null, null, List.of()));
    }

    @Test
    void readsThePortalListOffTheConfigPayload() {
        AppConfig config = new Gson().fromJson(
            "{\"bookingCityOptions\":[{\"city\":\"Sylhet\",\"mission\":\"Sylhet\","
                + "\"ivacCenter\":\"IVAC, SYLHET\"}]}", AppConfig.class);

        assertEquals(1, config.getBookingCityOptions().size());
        assertTrue(config.getBookingCityOptions().get(0).matches("sylhet", "ivac, sylhet"),
            "mission/centre comparison is case-insensitive so a portal casing change is not a new centre");
        assertEquals("Sylhet", config.getBookingCityOptions().get(0).city());
    }
}
