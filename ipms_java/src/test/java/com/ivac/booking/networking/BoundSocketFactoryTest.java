package com.ivac.booking.networking;

import org.junit.jupiter.api.Test;

import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.Socket;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assumptions.assumeTrue;

class BoundSocketFactoryTest {

    @Test
    void createSocketBindsToLoopbackV6() throws Exception {
        InetAddress loopback = InetAddress.getByName("::1");

        BoundSocketFactory factory = new BoundSocketFactory(loopback);

        try (Socket socket = factory.createSocket()) {
            assertTrue(socket.isBound(), "socket must be bound");
            assertEquals(loopback, socket.getLocalAddress(), "bound to the requested local address");
        }
    }

    @Test
    void createSocketBindsToDetectedGlobalV6WhenPresent() throws Exception {
        // Runs on hosts that actually have a global IPv6 (e.g. VPS-BDIPV6); skips on v4-only hosts.
        List<Inet6Address> global = Ipv6SourcePool.detectGlobalIpv6();
        assumeTrue(!global.isEmpty(), "no global IPv6 on this host");

        Inet6Address source = global.get(0);
        BoundSocketFactory factory = new BoundSocketFactory(source);

        try (Socket socket = factory.createSocket()) {
            assertTrue(socket.isBound());
            assertEquals(source, socket.getLocalAddress(),
                "outbound socket pinned to the chosen global IPv6 source");
        }
    }
}
