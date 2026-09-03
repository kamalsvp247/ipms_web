package com.ivac.booking.config;

import com.google.gson.Gson;
import com.ivac.booking.Constants;
import org.junit.jupiter.api.Test;

import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the bundle-extracted endpoint delivery: AppConfig exposes each IVAC path/header from the
 * config-delivered "endpoints" map, and falls back per-key to the compiled-in default when the map
 * is absent, missing the key, or the value is blank — so a bad/empty sync can never break a call.
 */
class AppConfigEndpointsTest {

    private static final Gson GSON = new Gson();

    @Test
    void usesConfigEndpointsWhenPresent() {
        String json = """
            {"endpoints":{
              "signin":"/auth/v99-sign-in",
              "sendOtp":"/forgot-password/sendOtp",
              "verifyOtp":"/otp/verifySigninOtp",
              "uploadFile":"/file/upload_file_v99",
              "bookingConfig":"/appointment/appointment-booking-config",
              "getBookingConfig":"/appointment/get-booking-config",
              "reserveSlot":"/slots/{reserveSlotId}/reserve-slot",
              "payment":"/payment/{paymentConfigId}/dg-epay/initiate",
              "signinNavState":"11111111-2222-3333-4444-555555555555",
              "uploadRuntimeState":"v9.deadbeef"
            }}""";
        AppConfig cfg = GSON.fromJson(json, AppConfig.class);

        assertEquals("/auth/v99-sign-in", cfg.getSigninPath());
        assertEquals("/file/upload_file_v99", cfg.getUploadFilePath());
        assertEquals("11111111-2222-3333-4444-555555555555", cfg.getSigninNavState());
        assertEquals("v9.deadbeef", cfg.getUploadRuntimeState());
        assertEquals("/slots/{reserveSlotId}/reserve-slot", cfg.getReserveSlotPathTemplate());
        assertEquals("/payment/{paymentConfigId}/dg-epay/initiate", cfg.getPaymentPathTemplate());
    }

    @Test
    void fallsBackToDefaultsWhenEndpointsMissing() {
        AppConfig cfg = GSON.fromJson("{}", AppConfig.class);

        assertEquals("/auth/v23-sign-in", cfg.getSigninPath());
        assertEquals("/forgot-password/sendOtp", cfg.getSendOtpPath());
        assertEquals("/otp/verifySigninOtp", cfg.getVerifyOtpPath());
        assertEquals("/file/upload_file_v23", cfg.getUploadFilePath());
        assertEquals("/appointment/appointment-booking-config", cfg.getBookingConfigPath());
        assertEquals("/appointment/get-booking-config", cfg.getGetBookingConfigPath());
        assertEquals("/slots/{reserveSlotId}/reserve-slot", cfg.getReserveSlotPathTemplate());
        assertEquals("/payment/{paymentConfigId}/dg-epay/initiate", cfg.getPaymentPathTemplate());
        assertEquals(Constants.SIGNIN_NAVIGATION_STATE, cfg.getSigninNavState());
        assertEquals(Constants.UPLOAD_RUNTIME_STATE, cfg.getUploadRuntimeState());
    }

    @Test
    void liveSwapChangesWhatTheGettersReturn() {
        // IVAC hides the JS bundle behind a notice page until a minute or two after the window opens,
        // so the portal can only extract a rotation while the race is already running. The swap is how
        // those values reach a live bot without a restart.
        AppConfig cfg = GSON.fromJson("""
            {"endpoints":{"signin":"/auth/v23-sign-in"},
             "reserveSlotId":"reserve-old","paymentConfigId":"payment-old","reserveRequestMeta":"meta-old"}""",
                AppConfig.class);

        assertEquals("/auth/v23-sign-in", cfg.getSigninPath());

        String changes = cfg.applyProtocolConstants(new ProtocolConstants(
                Map.of("signin", "/auth/v24-sign-in", "verifyOtp", "/otp/verifyRotated"),
                "reserve-new", null, null));

        assertEquals("/auth/v24-sign-in", cfg.getSigninPath());
        assertEquals("/otp/verifyRotated", cfg.getVerifyOtpPath());
        assertEquals("reserve-new", cfg.getReserveSlotId());
        assertEquals("payment-old", cfg.getPaymentConfigId(), "omitted value must keep last-known-good");
        assertEquals("meta-old", cfg.getReserveRequestMeta());
        assertTrue(changes.contains("signin"), changes);
    }

    @Test
    void failedRefreshCannotDowngradeToCompiledDefaults() {
        AppConfig cfg = GSON.fromJson(
                "{\"endpoints\":{\"signin\":\"/auth/v24-sign-in\"},\"reserveSlotId\":\"reserve-live\"}",
                AppConfig.class);

        // An empty payload is what a partially-failed portal sync looks like; it must be inert.
        cfg.applyProtocolConstants(new ProtocolConstants(Map.of(), null, null, null));
        cfg.applyProtocolConstants(null);

        assertEquals("/auth/v24-sign-in", cfg.getSigninPath());
        assertEquals("reserve-live", cfg.getReserveSlotId());
    }

    @Test
    void exposesTheConfigVersionThePortalStamped() {
        AppConfig cfg = GSON.fromJson("{\"configVersion\":\"a1b2c3d4e5f60718\"}", AppConfig.class);

        assertEquals("a1b2c3d4e5f60718", cfg.getConfigVersion());
    }

    @Test
    void fallsBackPerKeyWhenPartialOrBlank() {
        // Only signin present; uploadFile is blank -> must fall back; verifyOtp absent -> must fall back.
        AppConfig cfg = GSON.fromJson("{\"endpoints\":{\"signin\":\"/auth/v42-sign-in\",\"uploadFile\":\"   \"}}", AppConfig.class);

        assertEquals("/auth/v42-sign-in", cfg.getSigninPath());
        assertEquals("/file/upload_file_v23", cfg.getUploadFilePath());
        assertEquals("/otp/verifySigninOtp", cfg.getVerifyOtpPath());
    }
}
