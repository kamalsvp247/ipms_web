package com.ivac.booking.networking;

import com.ivac.booking.networking.EdgeThrottleFallbackManager.Decision;
import com.ivac.booking.networking.EdgeThrottleFallbackManager.Step;

import org.junit.jupiter.api.Test;

import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.UnknownHostException;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

import static com.ivac.booking.networking.EdgeThrottleFallbackManager.MAX_IPV6_ROTATIONS;
import static com.ivac.booking.networking.EdgeThrottleFallbackManager.ROTATION_COOLDOWN_MS;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Escalation order on an edge/WAF 429, exercised through decide() so no real HTTP clients
 * (and no network) are involved.
 */
class EdgeThrottleFallbackManagerTest {

    private static final String PROXY = "http://user:pass@bd-pr.oxylabs.io:30000";

    private static Inet6Address v6(String literal) throws UnknownHostException {
        return (Inet6Address) InetAddress.getByName(literal);
    }

    private static Ipv6SourcePool poolOf(String... literals) throws UnknownHostException {
        List<Inet6Address> addrs = new java.util.ArrayList<>();
        for (String literal : literals) {
            addrs.add(v6(literal));
        }
        return Ipv6SourcePool.forTesting(addrs);
    }

    /** Advances past the per-address grace period so the next call is allowed to hop. */
    private static long afterCooldown(long baseMs, int hop) {
        return baseMs + hop * (ROTATION_COOLDOWN_MS + 1);
    }

    @Test
    void ipv4MachineGoesStraightToProxy() {
        EdgeThrottleFallbackManager manager = new EdgeThrottleFallbackManager(
            PROXY, Set.of("103.174.51.42"), Ipv6SourcePool.forTesting(List.of()));

        assertEquals(Step.USE_PROXY, manager.decide(1_000L).step(),
            "no usable IPv6 — behaviour unchanged, proxy immediately");
    }

    @Test
    void hopsToUnusedIpv6BeforeProxy() throws Exception {
        Ipv6SourcePool pool = poolOf(
            "2001:df7:b880:6b::1",
            "2001:df7:b880:6b::2",
            "2001:df7:b880:6b::3",
            "2001:df7:b880:6b::4",
            "2001:df7:b880:6b::5",
            "2001:df7:b880:6b::6");

        // The account is already egressing from ::1, so it must never be handed back.
        EdgeThrottleFallbackManager manager = new EdgeThrottleFallbackManager(
            PROXY, Set.of("2001:df7:b880:6b:0:0:0:1"), pool);

        Set<String> handedOut = new HashSet<>();

        for (int hop = 0; hop < MAX_IPV6_ROTATIONS; hop++) {
            Decision decision = manager.decide(afterCooldown(1_000L, hop));

            assertEquals(Step.ROTATE_IPV6, decision.step(), "hop " + hop + " stays on IPv6");
            assertNotNull(decision.source());
            assertNotEquals("2001:df7:b880:6b:0:0:0:1", decision.source().getHostAddress(),
                "never hops back to the address the edge is already throttling");
            assertTrue(handedOut.add(decision.source().getHostAddress()),
                "each hop is a distinct source address");
        }

        assertEquals(Step.USE_PROXY,
            manager.decide(afterCooldown(1_000L, MAX_IPV6_ROTATIONS)).step(),
            "only after " + MAX_IPV6_ROTATIONS + " throttled addresses does it use the proxy");
    }

    @Test
    void keepsCurrentAddressDuringItsGracePeriod() throws Exception {
        Ipv6SourcePool pool = poolOf(
            "2001:df7:b880:6b::1",
            "2001:df7:b880:6b::2",
            "2001:df7:b880:6b::3");

        EdgeThrottleFallbackManager manager = new EdgeThrottleFallbackManager(PROXY, Set.of(), pool);

        assertEquals(Step.ROTATE_IPV6, manager.decide(1_000L).step());

        // A throttled tick reports many 429s at once; they must not burn the hop budget.
        for (long offset = 1; offset < ROTATION_COOLDOWN_MS; offset += 100) {
            assertEquals(Step.KEEP_CURRENT, manager.decide(1_000L + offset).step(),
                "within the grace period the freshly bound address is kept");
        }

        assertEquals(Step.ROTATE_IPV6, manager.decide(1_000L + ROTATION_COOLDOWN_MS).step(),
            "once the grace period lapses the next hop is allowed");
    }

    @Test
    void exhaustedPoolFallsBackToProxy() throws Exception {
        Ipv6SourcePool pool = poolOf("2001:df7:b880:6b::1", "2001:df7:b880:6b::2");

        EdgeThrottleFallbackManager manager = new EdgeThrottleFallbackManager(
            PROXY, Set.of("2001:df7:b880:6b:0:0:0:1"), pool);

        assertEquals(Step.ROTATE_IPV6, manager.decide(afterCooldown(1_000L, 0)).step());
        assertEquals(Step.USE_PROXY, manager.decide(afterCooldown(1_000L, 1)).step(),
            "one address left and it is already used — proxy, without waiting out the hop budget");
    }
}
