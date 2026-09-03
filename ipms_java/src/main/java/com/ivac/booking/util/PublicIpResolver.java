package com.ivac.booking.util;

import okhttp3.Dns;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.UnknownHostException;
import java.util.List;
import java.util.concurrent.TimeUnit;
import java.util.regex.Pattern;

/**
 * Resolves the worker machine's public IPv4 egress address once, so IPv4-only workers can ship
 * their real per-machine identity to the portal as bot_logs.remote_ip (shown in /log-analysis).
 *
 * IPv6 workers already ship their bound v6 source and never call this. The lookup forces an IPv4
 * exit (v4-only DNS) so it returns the IPv4 even on dual-stack hosts. Result is cached; a failed
 * lookup returns null and the caller falls back to the api.ivacbd.com label.
 */
public final class PublicIpResolver {

    private static final Logger log = LoggerFactory.getLogger(PublicIpResolver.class);

    private static final String LOOKUP_URL = "https://api.ipify.org";
    private static final Pattern IPV4 = Pattern.compile("^\\d{1,3}(\\.\\d{1,3}){3}$");

    private static volatile String cachedIpv4;
    private static volatile boolean resolved;

    private PublicIpResolver() {
    }

    public static synchronized String getPublicIpv4() {
        if (resolved) {
            return cachedIpv4;
        }

        cachedIpv4 = lookup();
        resolved = true;

        if (cachedIpv4 != null) {
            log.info("[PublicIP] Detected public IPv4 egress: {}", cachedIpv4);
        } else {
            log.warn("[PublicIP] Could not resolve public IPv4; log route falls back to host label.");
        }

        return cachedIpv4;
    }

    private static String lookup() {
        OkHttpClient client = new OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .dns(Ipv4OnlyDns.INSTANCE)
            .build();

        Request request = new Request.Builder().url(LOOKUP_URL).get().build();

        try (Response response = client.newCall(request).execute()) {
            if (!response.isSuccessful() || response.body() == null) {
                return null;
            }

            String body = response.body().string().trim();

            return IPV4.matcher(body).matches() ? body : null;
        } catch (Exception e) {
            log.warn("[PublicIP] Lookup failed: {}", e.getMessage());
            return null;
        } finally {
            client.dispatcher().executorService().shutdown();
            client.connectionPool().evictAll();
        }
    }

    private static final class Ipv4OnlyDns implements Dns {
        private static final Ipv4OnlyDns INSTANCE = new Ipv4OnlyDns();

        @Override
        public List<InetAddress> lookup(String hostname) throws UnknownHostException {
            List<InetAddress> v4 = Dns.SYSTEM.lookup(hostname).stream()
                .filter(addr -> addr instanceof Inet4Address)
                .toList();

            if (v4.isEmpty()) {
                throw new UnknownHostException("No A record for " + hostname);
            }

            return v4;
        }
    }
}
