package com.ivac.booking.networking;

import com.ivac.booking.util.ConsoleLogger;

import java.net.Inet6Address;
import java.util.Collection;
import java.util.HashSet;
import java.util.Set;

/**
 * Decides which client an account falls back to when IVAC's edge/WAF answers 429
 * "Too many request detected" — an IP-scoped throttle, not an app-level per-account cooldown.
 * One instance per account, shared by every phase (sign-in, OTP verify, slot reservation,
 * payment), so escalation state and the built clients are account-wide rather than per-phase.
 *
 * Escalation order:
 * - Machine has globally-routable IPv6: hop to another source address from Ipv6SourcePool
 *   (random, never one this account has already egressed from). Each new address gets
 *   ROTATION_COOLDOWN_MS to prove itself before the next hop, so one throttled tick — which
 *   reports many 429s at once — cannot burn the whole budget on untried addresses. Only after
 *   MAX_IPV6_ROTATIONS addresses have all been throttled, or the pool runs out of unused
 *   addresses, does it escalate to the proxy.
 * - IPv4-only machine: the proxy immediately, unchanged from the previous behaviour.
 *
 * Proxy URL comes from the portal Setting signin_429_proxy_url (delivered via /api/config),
 * not a hardcoded constant, so it can be rotated or disabled without a bot redeploy. Blank
 * disables the proxy step and leaves callers with backoff only.
 */
public class EdgeThrottleFallbackManager {

    // Distinct IPv6 source addresses tried before giving up on IPv6 and using the proxy.
    static final int MAX_IPV6_ROTATIONS = 4;

    // Grace period a freshly rotated address gets before another rotation is allowed.
    static final long ROTATION_COOLDOWN_MS = 2_000;

    /** What nextFallbackClient should do for one edge-throttle report. */
    enum Step { ROTATE_IPV6, KEEP_CURRENT, USE_PROXY }

    record Decision(Step step, Inet6Address source) { }

    private final String proxyUrl;
    private final Ipv6SourcePool ipv6Pool;

    // Every source address this account has egressed from (its original client plus each
    // rotation), so a hop never hands back an address the edge is already throttling.
    private final Set<String> usedSources = new HashSet<>();

    private IvacHttpClient ipv6Fallback;
    private IvacHttpClient proxyClient;
    private int ipv6Rotations;
    private long lastRotationAtMs;

    // Set once if the proxy URL is unset/malformed, so callers don't retry building it (and
    // re-log the warning) on every subsequent edge-throttle 429 in the same race.
    private boolean proxyUnavailable = false;

    public EdgeThrottleFallbackManager(String proxyUrl, Collection<String> initialSources) {
        this(proxyUrl, initialSources, Ipv6SourcePool.getInstance());
    }

    EdgeThrottleFallbackManager(String proxyUrl, Collection<String> initialSources, Ipv6SourcePool ipv6Pool) {
        this.proxyUrl = proxyUrl;
        this.ipv6Pool = ipv6Pool;

        if (initialSources != null) {
            usedSources.addAll(initialSources);
        }
    }

    /**
     * The client to fold into the caller's rotation for this edge-throttle 429: a freshly
     * bound IPv6 source while the pool still has untried addresses, the previously rotated
     * one while it is still within its grace period, otherwise the shared proxy. Returns null
     * only when it lands on the proxy and no usable proxy URL is configured — callers must
     * treat that as "no fallback available" and keep their existing backoff.
     */
    public synchronized IvacHttpClient nextFallbackClient(String phone) {
        Decision decision = decide(System.currentTimeMillis());

        switch (decision.step()) {
            case KEEP_CURRENT -> {
                if (ipv6Fallback != null) {
                    return ipv6Fallback;
                }
            }
            case ROTATE_IPV6 -> {
                try {
                    ipv6Fallback = IvacHttpClient.boundToIpv6(decision.source(), phone);
                    ConsoleLogger.log(phone, "Edge 429 — hopping to IPv6 source "
                        + decision.source().getHostAddress() + " (" + ipv6Rotations + "/"
                        + MAX_IPV6_ROTATIONS + " hops used)", "WARN");
                    return ipv6Fallback;
                } catch (Exception e) {
                    ConsoleLogger.log(phone, "Edge 429 — failed to bind IPv6 source "
                        + decision.source().getHostAddress() + ": " + e.getMessage(), "ERROR");
                }
            }
            case USE_PROXY -> { }
        }

        return getOrCreateProxy(phone);
    }

    /**
     * Escalation decision for one edge-throttle report, booking the rotation (address marked
     * used, counter and timestamp advanced) when it returns ROTATE_IPV6. Package-private so
     * the escalation order can be tested without building real HTTP clients.
     */
    synchronized Decision decide(long nowMs) {
        if (!ipv6Pool.isEnabled() || ipv6Rotations >= MAX_IPV6_ROTATIONS) {
            return new Decision(Step.USE_PROXY, null);
        }

        if (ipv6Rotations > 0 && nowMs - lastRotationAtMs < ROTATION_COOLDOWN_MS) {
            return new Decision(Step.KEEP_CURRENT, null);
        }

        Inet6Address source = ipv6Pool.randomExcluding(usedSources);
        if (source == null) {
            return new Decision(Step.USE_PROXY, null);
        }

        usedSources.add(source.getHostAddress());
        ipv6Rotations++;
        lastRotationAtMs = nowMs;

        return new Decision(Step.ROTATE_IPV6, source);
    }

    /**
     * Returns the shared proxy client, building it on first call. Returns null (logging once)
     * if no proxy URL is configured or the URL fails to parse/build.
     */
    private IvacHttpClient getOrCreateProxy(String phone) {
        if (proxyClient != null) {
            return proxyClient;
        }
        if (proxyUnavailable) {
            return null;
        }

        if (proxyUrl == null || proxyUrl.isBlank()) {
            proxyUnavailable = true;
            ConsoleLogger.log(phone, "Edge 429 — no signin_429_proxy_url configured in "
                + "portal settings, falling back to backoff only", "WARN");
            return null;
        }

        try {
            proxyClient = IvacHttpClient.proxy(proxyUrl, phone);
            ConsoleLogger.log(phone, "Edge 429 (\"too many request detected\") — IPv6 hops "
                + "exhausted, activating shared proxy fallback client "
                + proxyClient.getRemoteHost(), "WARN");
        } catch (Exception e) {
            proxyUnavailable = true;
            ConsoleLogger.log(phone, "Edge 429 — failed to build proxy client: " + e.getMessage(), "ERROR");
            return null;
        }
        return proxyClient;
    }
}
