package com.ivac.booking.service.slot;

public enum ShotOutcome {
    RESERVED,
    ALREADY_PAID,
    CAPTCHA_BAD,
    RATE_LIMITED,
    // IVAC edge/WAF throttle ("Too many request detected", no server-stated wait) — distinct
    // from RATE_LIMITED (a real account-scoped cooldown) so the caller escalates backoff and
    // folds a proxy client into the slot rotation instead of the flat 20s block.
    EDGE_THROTTLE_429,
    RESTART,
    RETRY,
    DISABLED,
    SKIPPED
}
