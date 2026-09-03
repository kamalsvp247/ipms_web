package com.ivac.booking.networking;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.net.SocketException;
import java.security.SecureRandom;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.Enumeration;
import java.util.List;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Thread-safe pool of the machine's globally-routable IPv6 source addresses.
 *
 * Detected once, lazily, from the local network interfaces. When one or more usable
 * global IPv6 addresses exist, the pool is enabled and next() hands them out round-robin
 * so each account's outbound IVAC traffic can be bound to a distinct source address
 * (see BoundSocketFactory). When no usable IPv6 address exists, the pool is disabled and
 * callers keep the current IPv4 behaviour.
 *
 * A kill-switch (system property ipms.ipv6.enabled or env IPMS_IPV6_ENABLED, default on)
 * forces the disabled state without a rebuild if IPv6 egress ever misbehaves.
 */
public final class Ipv6SourcePool {

    private static final Logger log = LoggerFactory.getLogger(Ipv6SourcePool.class);

    private static final SecureRandom RANDOM = new SecureRandom();

    private final List<Inet6Address> addresses;
    private final AtomicInteger cursor;

    private Ipv6SourcePool(List<Inet6Address> addresses, int startOffset) {
        this.addresses = addresses;
        this.cursor = new AtomicInteger(startOffset);
    }

    private static final class Holder {
        private static final Ipv6SourcePool INSTANCE = build();
    }

    public static Ipv6SourcePool getInstance() {
        return Holder.INSTANCE;
    }

    private static Ipv6SourcePool build() {
        if (!killSwitchAllows()) {
            log.info("[IPv6] Disabled via kill-switch (ipms.ipv6.enabled/IPMS_IPV6_ENABLED). Using IPv4 egress.");
            return new Ipv6SourcePool(List.of(), 0);
        }

        List<Inet6Address> detected = detectGlobalIpv6();

        if (detected.isEmpty()) {
            log.info("[IPv6] No globally-routable IPv6 source addresses found. Using IPv4 egress.");
            return new Ipv6SourcePool(List.of(), 0);
        }

        int startOffset = RANDOM.nextInt(detected.size());

        log.info("[IPv6] Egress enabled: {} source address(es) [{} ... {}], round-robin start offset {}",
            detected.size(),
            detected.get(0).getHostAddress(),
            detected.get(detected.size() - 1).getHostAddress(),
            startOffset);

        return new Ipv6SourcePool(Collections.unmodifiableList(detected), startOffset);
    }

    private static boolean killSwitchAllows() {
        String prop = System.getProperty("ipms.ipv6.enabled");
        if (prop != null) {
            return isTruthy(prop);
        }

        String env = System.getenv("IPMS_IPV6_ENABLED");
        if (env != null) {
            return isTruthy(env);
        }

        return true;
    }

    private static boolean isTruthy(String value) {
        String v = value.trim().toLowerCase();
        return !(v.equals("0") || v.equals("false") || v.equals("no") || v.equals("off"));
    }

    /**
     * Enumerate all up, non-loopback interfaces and collect their global-unicast IPv6
     * addresses. Excludes link-local (fe80::), unique-local/site-local (fc00::/7),
     * loopback, multicast, and wildcard addresses.
     */
    static List<Inet6Address> detectGlobalIpv6() {
        List<Inet6Address> result = new ArrayList<>();

        try {
            Enumeration<NetworkInterface> interfaces = NetworkInterface.getNetworkInterfaces();

            while (interfaces.hasMoreElements()) {
                NetworkInterface nic = interfaces.nextElement();

                try {
                    if (!nic.isUp() || nic.isLoopback()) {
                        continue;
                    }
                } catch (SocketException e) {
                    continue;
                }

                Enumeration<InetAddress> addrs = nic.getInetAddresses();

                while (addrs.hasMoreElements()) {
                    InetAddress addr = addrs.nextElement();

                    if (addr instanceof Inet6Address v6 && isGlobalUnicast(v6)) {
                        result.add(v6);
                    }
                }
            }
        } catch (SocketException e) {
            log.warn("[IPv6] Failed to enumerate network interfaces: {}", e.getMessage());
        }

        return result;
    }

    static boolean isGlobalUnicast(Inet6Address addr) {
        return !addr.isLinkLocalAddress()
            && !addr.isLoopbackAddress()
            && !addr.isMulticastAddress()
            && !addr.isSiteLocalAddress()
            && !addr.isAnyLocalAddress()
            && !isUniqueLocal(addr);
    }

    /**
     * Unique-local address (fc00::/7). Inet6Address.isSiteLocalAddress() only matches the
     * deprecated fec0::/10 range, so ULA must be excluded explicitly.
     */
    private static boolean isUniqueLocal(Inet6Address addr) {
        return (addr.getAddress()[0] & 0xfe) == 0xfc;
    }

    public boolean isEnabled() {
        return !addresses.isEmpty();
    }

    public int size() {
        return addresses.size();
    }

    /**
     * Next source address in round-robin order. Thread-safe. Throws when the pool is
     * empty, so callers must guard with isEnabled().
     */
    public Inet6Address next() {
        int n = addresses.size();

        if (n == 0) {
            throw new IllegalStateException("Ipv6SourcePool is empty; guard calls with isEnabled()");
        }

        int index = Math.floorMod(cursor.getAndIncrement(), n);

        return addresses.get(index);
    }

    /**
     * A random source address that is not in excludedHostAddresses (compared on
     * getHostAddress()), or null when the pool is empty or every address is excluded.
     * Used by EdgeThrottleFallbackManager to hop to an unthrottled source on an edge 429.
     */
    public Inet6Address randomExcluding(Collection<String> excludedHostAddresses) {
        List<Inet6Address> candidates = new ArrayList<>(addresses.size());

        for (Inet6Address addr : addresses) {
            if (excludedHostAddresses == null || !excludedHostAddresses.contains(addr.getHostAddress())) {
                candidates.add(addr);
            }
        }

        if (candidates.isEmpty()) {
            return null;
        }

        return candidates.get(RANDOM.nextInt(candidates.size()));
    }

    /**
     * Test-only constructor for verifying round-robin and enablement without touching the
     * host's real interfaces.
     */
    static Ipv6SourcePool forTesting(List<Inet6Address> addresses) {
        return new Ipv6SourcePool(List.copyOf(addresses), 0);
    }
}
