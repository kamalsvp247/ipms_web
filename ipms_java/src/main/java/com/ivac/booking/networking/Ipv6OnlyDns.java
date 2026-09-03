package com.ivac.booking.networking;

import com.ivac.booking.Constants;
import okhttp3.Dns;

import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.UnknownHostException;
import java.util.List;

/**
 * DNS resolver that returns only IPv6 (AAAA) results for the IVAC API host.
 *
 * When a connection is bound to an IPv6 source address (BoundSocketFactory), the destination
 * must also be IPv6 — a v6 socket cannot connect to a v4 peer. System DNS returns both A and
 * AAAA records for api.ivacbd.com, so without this filter OkHttp may try a v4 destination and
 * fail. All other hosts fall through to the system resolver unchanged.
 */
public final class Ipv6OnlyDns implements Dns {

    @Override
    public List<InetAddress> lookup(String hostname) throws UnknownHostException {
        if (!Constants.API_CF_HOST.equals(hostname)) {
            return Dns.SYSTEM.lookup(hostname);
        }

        List<InetAddress> v6Only = Dns.SYSTEM.lookup(hostname).stream()
            .filter(addr -> addr instanceof Inet6Address)
            .toList();

        if (v6Only.isEmpty()) {
            throw new UnknownHostException("No AAAA record for " + hostname + " (IPv6 egress requires a v6 destination)");
        }

        return v6Only;
    }
}
