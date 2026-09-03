package com.ivac.booking.service.setup;

import com.google.gson.Gson;
import com.ivac.booking.Constants;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.config.ProtocolConstants;
import com.ivac.booking.networking.IvacHttpClient;
import org.junit.jupiter.api.Test;

import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;

/**
 * Regression guard, mirroring OtpServiceEndpointRefreshTest: AccountSetupService used to snapshot the
 * upload/booking-config paths and the x-sec-runtime-state header into final fields at construction, so
 * a rotation extracted after the window opened could not reach the PDF and booking-config setup gate.
 */
class AccountSetupEndpointRefreshTest {

    private static final Gson GSON = new Gson();

    private static AccountSetupService serviceFor(AppConfig config) {
        return new AccountSetupService(null, IvacHttpClient.direct("01700000000"), null, null, config, 0L);
    }

    @Test
    void setupPathsAndRuntimeStateFollowALiveConfigSwap() {
        AppConfig config = GSON.fromJson("""
            {"endpoints":{
              "uploadFile":"/file/upload_file_v23",
              "bookingConfig":"/appointment/appointment-booking-config",
              "getBookingConfig":"/appointment/get-booking-config",
              "uploadRuntimeState":"v1.original"
            }}""", AppConfig.class);

        AccountSetupService service = serviceFor(config);

        assertEquals("/file/upload_file_v23", service.uploadPath());

        config.applyProtocolConstants(new ProtocolConstants(Map.of(
                "uploadFile", "/file/upload_file_v24",
                "bookingConfig", "/appointment/booking-config-v2",
                "getBookingConfig", "/appointment/get-booking-config-v2",
                "uploadRuntimeState", "v2.rotated"), null, null, null));

        assertEquals("/file/upload_file_v24", service.uploadPath(), "upload path must not be cached");
        assertEquals("/appointment/booking-config-v2", service.bookingConfigPath());
        assertEquals("/appointment/get-booking-config-v2", service.getBookingConfigPath());
        assertEquals("v2.rotated", service.uploadRuntimeState(), "runtime-state header must not be cached");
    }

    @Test
    void fallsBackToCompiledDefaultsWithoutConfig() {
        AccountSetupService service = serviceFor(null);

        assertEquals("/file/upload_file_v23", service.uploadPath());
        assertEquals("/appointment/appointment-booking-config", service.bookingConfigPath());
        assertEquals("/appointment/get-booking-config", service.getBookingConfigPath());
        assertEquals(Constants.UPLOAD_RUNTIME_STATE, service.uploadRuntimeState());
    }
}
