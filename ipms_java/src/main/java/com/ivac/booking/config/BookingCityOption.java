package com.ivac.booking.config;

import com.google.gson.annotations.SerializedName;

import java.util.ArrayList;
import java.util.List;

/**
 * One selectable IVAC high commission: the {mission, ivacCenter} pair the booking-config call sends,
 * plus the portal city key that names it.
 *
 * The whole list is delivered by /api/config (from App\Support\IvacBookingCities) rather than being
 * compiled in, so a new or renamed centre only needs a portal deploy. DEFAULTS mirrors the portal
 * list and is used only when the config omits the key, so an old portal never leaves the bot with no
 * candidate to try.
 */
public record BookingCityOption(
        @SerializedName("city") String city,
        @SerializedName("mission") String mission,
        @SerializedName("ivacCenter") String ivacCenter
) {

    public static final List<BookingCityOption> DEFAULTS = List.of(
            new BookingCityOption("Dhaka", "Dhaka", "IVAC, Dhaka (JFP)"),
            new BookingCityOption("Khulna", "Khulna", "IVAC, KHULNA"),
            new BookingCityOption("Chittagong", "Chittagong", "IVAC, CHITTAGONG"),
            new BookingCityOption("Rajshahi", "Rajshahi", "IVAC, RAJSHAHI"),
            new BookingCityOption("Sylhet", "Sylhet", "IVAC, SYLHET")
    );

    /**
     * True when both values the booking-config body needs are present. A half-filled entry is
     * dropped rather than posted: IVAC rejects it, and it would burn one rotation round.
     */
    public boolean isUsable() {
        return mission != null && !mission.isBlank() && ivacCenter != null && !ivacCenter.isBlank();
    }

    /**
     * True when this option posts the same body as the given mission/centre pair, so the account's
     * own configured pair is not tried twice in one rotation.
     */
    public boolean matches(String otherMission, String otherIvacCenter) {
        return mission != null && mission.equalsIgnoreCase(otherMission)
                && ivacCenter != null && ivacCenter.equalsIgnoreCase(otherIvacCenter);
    }

    /**
     * The candidate order for one account: its configured pair first (the operator's choice is the
     * most likely to be right), then every other centre the portal knows, de-duplicated. Unusable
     * entries are dropped. Returns an empty list when the account has no city and the portal sent
     * nothing usable — the caller then has nothing to post and gives up.
     */
    public static List<BookingCityOption> orderedFor(String mission, String ivacCenter,
                                                     List<BookingCityOption> available) {
        List<BookingCityOption> ordered = new ArrayList<>();
        List<BookingCityOption> pool = (available != null && !available.isEmpty()) ? available : DEFAULTS;

        if (mission != null && !mission.isBlank() && ivacCenter != null && !ivacCenter.isBlank()) {
            BookingCityOption configured = pool.stream()
                    .filter(option -> option != null && option.matches(mission, ivacCenter))
                    .findFirst()
                    // A pair the portal list no longer carries is still posted first: it is what the
                    // operator picked, and only IVAC can say whether it is invalid.
                    .orElseGet(() -> new BookingCityOption(null, mission, ivacCenter));
            ordered.add(configured);
        }

        for (BookingCityOption option : pool) {
            if (option == null || !option.isUsable()) {
                continue;
            }
            if (ordered.stream().noneMatch(existing -> existing.matches(option.mission(), option.ivacCenter()))) {
                ordered.add(option);
            }
        }

        return List.copyOf(ordered);
    }
}
