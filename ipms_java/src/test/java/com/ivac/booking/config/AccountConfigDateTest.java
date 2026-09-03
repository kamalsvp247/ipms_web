package com.ivac.booking.config;

import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.stream.IntStream;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers appointment-date selection for reserve-slot: IVAC's own get-booking-config dates, when
 * captured, replace the portal-configured range; otherwise the portal range is used. Selection is a
 * randomized round-robin - every date is used exactly once per cycle, but in a different order each
 * cycle so parallel shots do not all pile onto the same date first.
 */
class AccountConfigDateTest {

    private static List<String> take(AccountConfig account, int count) {
        List<String> taken = new ArrayList<>(count);
        IntStream.range(0, count).forEach(i -> taken.add(account.nextAppointmentDate()));
        return taken;
    }

    @Test
    void usesEveryPortalDateExactlyOncePerCycle() {
        List<String> dates = List.of("2026-07-01", "2026-07-02", "2026-07-03", "2026-07-04");
        AccountConfig account = new AccountConfig();
        account.setAppointmentDates(dates);

        assertEquals(Set.copyOf(dates), new HashSet<>(take(account, 4)),
            "first cycle must cover every date");
        assertEquals(Set.copyOf(dates), new HashSet<>(take(account, 4)),
            "second cycle must cover every date again after reshuffling");
    }

    /**
     * With 20 dates the odds of a dozen cycles all landing on the same permutation are effectively
     * zero, so a single differing cycle is enough to prove the order is not fixed.
     */
    @Test
    void reshufflesTheOrderBetweenCycles() {
        List<String> dates = IntStream.rangeClosed(1, 20)
            .mapToObj(day -> String.format("2026-07-%02d", day))
            .toList();
        AccountConfig account = new AccountConfig();
        account.setAppointmentDates(dates);

        Set<List<String>> orders = new HashSet<>();
        IntStream.range(0, 12).forEach(i -> orders.add(take(account, dates.size())));

        assertTrue(orders.size() > 1, "date order must vary across cycles, not repeat a fixed sequence");
    }

    @Test
    void prefersIvacDatesOverPortalDatesWhenPresent() {
        AccountConfig account = new AccountConfig();
        account.setAppointmentDates(List.of("2026-07-01", "2026-07-02"));
        account.setIvacAppointmentDates(List.of("2026-08-10", "2026-08-11"));

        assertEquals(Set.of("2026-08-10", "2026-08-11"), new HashSet<>(take(account, 2)));
    }

    /**
     * IVAC's dates arrive mid-race from get-booking-config. Swapping the list must start a fresh
     * cycle rather than carrying a stale cursor into a list of a different length.
     */
    @Test
    void startsAFreshCycleWhenIvacDatesArriveMidCycle() {
        AccountConfig account = new AccountConfig();
        account.setAppointmentDates(List.of("2026-07-01", "2026-07-02", "2026-07-03"));
        account.nextAppointmentDate();

        account.setIvacAppointmentDates(List.of("2026-08-10", "2026-08-11"));

        assertEquals(Set.of("2026-08-10", "2026-08-11"), new HashSet<>(take(account, 2)));
    }

    @Test
    void fallsBackToPortalDatesWhenIvacDatesEmpty() {
        AccountConfig account = new AccountConfig();
        account.setAppointmentDates(List.of("2026-07-01"));
        account.setIvacAppointmentDates(List.of());

        assertEquals("2026-07-01", account.nextAppointmentDate());
    }

    @Test
    void returnsEmptyStringWhenNoDatesAtAll() {
        assertEquals("", new AccountConfig().nextAppointmentDate());
    }
}
