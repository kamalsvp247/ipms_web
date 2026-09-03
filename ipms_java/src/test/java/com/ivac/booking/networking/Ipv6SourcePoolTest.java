package com.ivac.booking.networking;

import org.junit.jupiter.api.Test;

import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.UnknownHostException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class Ipv6SourcePoolTest {

    private static Inet6Address v6(String literal) throws UnknownHostException {
        return (Inet6Address) InetAddress.getByName(literal);
    }

    @Test
    void isGlobalUnicastDropsNonGlobalAddresses() throws Exception {
        assertTrue(Ipv6SourcePool.isGlobalUnicast(v6("2001:df7:b880:6b::a")));
        assertTrue(Ipv6SourcePool.isGlobalUnicast(v6("2606:4700:20::ac43:44a4")));

        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("fe80::1")), "link-local dropped");
        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("::1")), "loopback dropped");
        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("fc00::1")), "unique-local dropped");
        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("fd00::1")), "unique-local dropped");
        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("ff02::1")), "multicast dropped");
        assertFalse(Ipv6SourcePool.isGlobalUnicast(v6("::")), "wildcard dropped");
    }

    @Test
    void roundRobinCyclesInOrder() throws Exception {
        List<Inet6Address> addrs = List.of(
            v6("2001:df7:b880:6b::100"),
            v6("2001:df7:b880:6b::101"),
            v6("2001:df7:b880:6b::102"));

        Ipv6SourcePool pool = Ipv6SourcePool.forTesting(addrs);

        assertTrue(pool.isEnabled());
        assertEquals(3, pool.size());

        List<String> seen = new ArrayList<>();
        for (int i = 0; i < 6; i++) {
            seen.add(pool.next().getHostAddress());
        }

        // Two full cycles in stable order (test pool starts at offset 0).
        assertEquals(seen.get(0), seen.get(3));
        assertEquals(seen.get(1), seen.get(4));
        assertEquals(seen.get(2), seen.get(5));
        assertEquals(3, seen.subList(0, 3).stream().distinct().count());
    }

    @Test
    void nextIsThreadSafeAndEvenlyDistributed() throws Exception {
        List<Inet6Address> addrs = List.of(
            v6("2001:df7:b880:6b::10"),
            v6("2001:df7:b880:6b::11"),
            v6("2001:df7:b880:6b::12"),
            v6("2001:df7:b880:6b::13"));

        Ipv6SourcePool pool = Ipv6SourcePool.forTesting(addrs);

        int threads = 8;
        int perThread = 10_000;
        int total = threads * perThread;

        Map<String, AtomicInteger> counts = new ConcurrentHashMap<>();
        ExecutorService exec = Executors.newFixedThreadPool(threads);
        CountDownLatch start = new CountDownLatch(1);
        CountDownLatch done = new CountDownLatch(threads);

        for (int t = 0; t < threads; t++) {
            exec.submit(() -> {
                try {
                    start.await();
                    for (int i = 0; i < perThread; i++) {
                        String ip = pool.next().getHostAddress();
                        counts.computeIfAbsent(ip, k -> new AtomicInteger()).incrementAndGet();
                    }
                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                } finally {
                    done.countDown();
                }
            });
        }

        start.countDown();
        assertTrue(done.await(30, TimeUnit.SECONDS), "workers finished");
        exec.shutdownNow();

        int summed = counts.values().stream().mapToInt(AtomicInteger::get).sum();
        assertEquals(total, summed, "no lost or duplicated handouts");

        int expected = total / addrs.size();
        for (Map.Entry<String, AtomicInteger> e : counts.entrySet()) {
            // AtomicInteger round-robin guarantees a perfectly even split across a divisor count.
            assertEquals(expected, e.getValue().get(), "even distribution for " + e.getKey());
        }
    }

    @Test
    void emptyPoolIsDisabledAndNextThrows() {
        Ipv6SourcePool pool = Ipv6SourcePool.forTesting(List.of());

        assertFalse(pool.isEnabled());
        assertEquals(0, pool.size());
        assertThrows(IllegalStateException.class, pool::next);
    }

    @Test
    void detectGlobalIpv6NeverReturnsNonGlobalAddresses() {
        // Runs against the real host; asserts only the invariant (may be empty on v4-only hosts).
        for (Inet6Address addr : Ipv6SourcePool.detectGlobalIpv6()) {
            assertTrue(Ipv6SourcePool.isGlobalUnicast(addr),
                "detected address must be global unicast: " + addr.getHostAddress());
        }
    }
}
