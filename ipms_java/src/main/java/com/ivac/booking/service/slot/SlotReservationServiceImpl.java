package com.ivac.booking.service.slot;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.model.response.ReserveSlotResponse;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.exception.RateLimitFatalException;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.HttpUtil;
import com.ivac.booking.util.RetryUtil;
import com.ivac.booking.worker.RaceContext;

import java.io.IOException;
import java.net.SocketTimeoutException;
import java.time.OffsetDateTime;
import java.util.Map;

public class SlotReservationServiceImpl implements SlotReservationService {

    private static final String SLOTS_DISABLED_MARKER = "all slots and payments are disabled";
    private static final long   SLOT_429_BACKOFF_MS   = 20_000L;

    // IVAC's frontend bundle attaches a fixed metadata header to the reserve-slot POST and to that
    // call ONLY (it appears exactly once in the bundle). The value is a static constant baked into
    // the bundle — not derived from any runtime input — so it is safe to send verbatim. Sending it
    // makes the bot's reserve request header-identical to the browser; omitting it is a divergence a
    // stricter IVAC deploy can reject. IVAC rotates this on redeploy (like reserveSlotId), so the
    // live value is delivered via /api/config (settings.reserve_request_meta, bundle-synced by the
    // portal's Algorithm Monitor). The constant below is only a fallback for an older portal whose
    // config omits the field.
    private static final String X_V_REQUEST_META_HEADER   = "x-v-request-meta";
    private static final String X_V_REQUEST_META_FALLBACK = "windos.s";

    private final Gson         gson    = new Gson();
    private final AccountConfig account;
    private final AppConfig    appConfig;
    private final IvacHttpClient client;

    public SlotReservationServiceImpl(AccountConfig account, AppConfig appConfig, IvacHttpClient client) {
        this.account  = account;
        this.appConfig = appConfig;
        this.client   = client;
    }

    @Override
    public ShotOutcome fireOneShot(RaceContext context, String captchaValue, String label) {
        if (context.isSlotReserved() || context.isRaceOver()) {
            return ShotOutcome.SKIPPED;
        }

        String phone = account.getPhone();

        // Reserve endpoint embeds a fixed, deployment-scoped slot ID in the path:
        // POST /slots/{reserveSlotId}/reserve-slot. IVAC bakes this ID into its frontend
        // bundle and rotates it on redeploy, so it is a portal Setting (reserve_slot_id)
        // delivered via /api/config, not the account's appointmentId.
        String reserveSlotId = appConfig.getReserveSlotId();
        if (reserveSlotId == null || reserveSlotId.isBlank()) {
            if (context.tryLogSlot("missing_reserve_slot_id")) {
                ConsoleLogger.log(phone, label + " slot reserve skipped — reserveSlotId not set in portal settings", "WARN");
            }
            return ShotOutcome.RETRY;
        }

        // One or more appointment dates are configured per-account (a date range expanded
        // to all dates in between). IVAC expects a SINGLE date string under "appointmentDate",
        // so rotate one-by-one through the list in a randomized order, sending a different date
        // on each attempt.
        String appointmentDate = account.nextAppointmentDate();

        try {
            IvacHttpClient.RawResponse raw = client.postRawNoAuthRetry(
                appConfig.getReserveSlotPathTemplate().replace("{reserveSlotId}", reserveSlotId),
                Map.of("c", captchaValue, "appointmentDate", appointmentDate),
                -1,
                Map.of(X_V_REQUEST_META_HEADER, resolveRequestMeta()));

            if (raw.statusCode() == 200) {
                context.setOtpVerified(null);
                context.resetSlotEdgeThrottleCount();
                ReserveSlotResponse resp = gson.fromJson(raw.body(), ReserveSlotResponse.class);

                if (resp.getReservationId() != null && !resp.getReservationId().isEmpty()) {
                    if (context.setSlotReserved()) {
                        context.setReservationId(resp.getReservationId());
                        ConsoleLogger.log(phone, label + " slot RESERVED — " + resp.getReservationId(), "OK");
                    }
                    return ShotOutcome.RESERVED;
                }

                String status = resp.getStatus() != null ? resp.getStatus() : "";
                if (!status.isEmpty() && context.tryLogSlot("status_" + status)) {
                    ConsoleLogger.log(phone, "Slot status=" + status + " — " + resp.getMessage(), "RETRY");
                }
                return ShotOutcome.RETRY;
            }

            if (raw.statusCode() == 400) {
                String body = raw.body() != null ? raw.body().toLowerCase() : "";
                if (body.contains("already made a payment") || body.contains("already paid")) {
                    ConsoleLogger.log(phone, label + " slot 400 — payment already made, skipping to payment", "OK");
                    context.setSlotReserved();
                    return ShotOutcome.ALREADY_PAID;
                }
                if (context.tryLogSlot("400_captcha")) {
                    ConsoleLogger.log(phone, "Slot 400 — captcha rejected", "RETRY");
                }
                return ShotOutcome.CAPTCHA_BAD;
            }

            if (raw.statusCode() == 429) {
                // Safety valve: an explicit "retry after N second(s)" beyond 30 min stops the
                // account. Uses the uncapped seconds parse (parseSigninRetryAfterMs caps at 15 min).
                long rawRetryAfterSec = HttpUtil.parseRetryAfterSeconds(raw.body());
                if (rawRetryAfterSec > 1800) {
                    ConsoleLogger.log(phone, label + " slot 429 — retry after " + rawRetryAfterSec + "s (>30 min) — stopping account", "FAIL");
                    throw new RateLimitFatalException(
                        label + " slot 429 — retry after " + rawRetryAfterSec + "s (>30 min) — stopping account",
                        rawRetryAfterSec);
                }

                // "You can try after X minute(s) and Y second(s)" style — an explicit
                // account-scoped cooldown IVAC states verbatim; honor it as-is (capped at 15 min).
                long serverStatedMs = RetryUtil.parseSigninRetryAfterMs(raw.body());
                if (serverStatedMs > 0) {
                    if (context.tryLogSlot("429")) {
                        ConsoleLogger.log(phone, "Slot 429 — server-stated cooldown, blocking all tasks "
                            + (serverStatedMs / 1000) + "s", "WAIT");
                    }
                    context.record429Backoff(serverStatedMs);
                    context.resetSlotEdgeThrottleCount();
                    return ShotOutcome.RATE_LIMITED;
                }

                // No server-stated wait — e.g. "Too many request detected", IVAC's edge/WAF
                // throttle rather than an account cooldown. Let the caller (RaceOrchestrator)
                // apply the escalating backoff and fold a proxy client into the slot rotation.
                if (RetryUtil.isEdgeThrottleMessage(raw.body())) {
                    return ShotOutcome.EDGE_THROTTLE_429;
                }

                if (context.tryLogSlot("429")) {
                    ConsoleLogger.log(phone, "Slot 429 — blocking all tasks " + (SLOT_429_BACKOFF_MS / 1000) + "s", "WAIT");
                }
                context.record429Backoff(SLOT_429_BACKOFF_MS);
                return ShotOutcome.RATE_LIMITED;
            }

            if (raw.statusCode() == 401) {
                if (context.isOtpVerified()) {
                    String serverTime = parseServerTime(raw.body());
                    String otpTime    = context.getOtpVerifiedServerTime();
                    if (serverTime != null && otpTime != null && isServerTimeAfter(serverTime, otpTime)) {
                        return ShotOutcome.RESTART;
                    }
                }
                if (context.tryLogSlot("401")) {
                    ConsoleLogger.log(phone, "Slot 401 — retrying", "RETRY");
                }
                return ShotOutcome.RETRY;
            }

            if (raw.statusCode() == 503) {
                if (isSlotsDisabledBody(raw.body())) {
                    ConsoleLogger.log(phone, label + " slot 503 — all slots and payments disabled, stopping", "FAIL");
                    return ShotOutcome.DISABLED;
                }
                if (isCloudflarePage(raw.body())) {
                    if (context.tryLogSlot("503_cf")) {
                        ConsoleLogger.log(phone, label + " slot 503 CF page — retrying immediately", "RETRY");
                    }
                    return ShotOutcome.RETRY;
                }
                if (context.tryLogSlot("503")) {
                    ConsoleLogger.log(phone, label + " slot 503 — retrying", "RETRY");
                }
                return ShotOutcome.RETRY;
            }

            if (context.tryLogSlot("http_" + raw.statusCode())) {
                ConsoleLogger.log(phone, label + " slot HTTP " + raw.statusCode() + " — retrying", "RETRY");
            }
            return ShotOutcome.RETRY;

        } catch (SocketTimeoutException e) {
            if (context.tryLogSlot("timeout")) {
                ConsoleLogger.log(phone, "Slot timeout — retrying", "RETRY");
            }
            return ShotOutcome.RETRY;
        } catch (IOException e) {
            if (!context.isRaceOver() && context.tryLogSlot("io_error")) {
                ConsoleLogger.log(phone, "Slot IO error: " + e.getMessage(), "RETRY");
            }
            return ShotOutcome.RETRY;
        }
    }

    /**
     * The x-v-request-meta header value, preferring the bundle-synced value from portal config and
     * falling back to the compiled-in constant when an older portal omits it.
     */
    private String resolveRequestMeta() {
        String fromConfig = appConfig.getReserveRequestMeta();
        return (fromConfig != null && !fromConfig.isBlank()) ? fromConfig : X_V_REQUEST_META_FALLBACK;
    }

    private boolean isSlotsDisabledBody(String body) {
        return body != null && body.toLowerCase().contains(SLOTS_DISABLED_MARKER);
    }

    private boolean isCloudflarePage(String body) {
        return body != null && body.contains("Service Temporarily Unavailable") && body.contains("</html>");
    }

    private String parseServerTime(String body) {
        if (body == null) {
            return null;
        }
        try {
            JsonObject json = gson.fromJson(body, JsonObject.class);
            return json.has("serverTime") ? json.get("serverTime").getAsString() : null;
        } catch (Exception e) {
            return null;
        }
    }

    private boolean isServerTimeAfter(String a, String b) {
        try {
            return OffsetDateTime.parse(a).isAfter(OffsetDateTime.parse(b));
        } catch (Exception e) {
            return false;
        }
    }
}
