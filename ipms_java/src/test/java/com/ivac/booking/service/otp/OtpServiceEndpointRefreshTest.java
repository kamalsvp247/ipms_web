package com.ivac.booking.service.otp;

import com.google.gson.Gson;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.config.ProtocolConstants;
import com.ivac.booking.networking.IvacHttpClient;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;

/**
 * Regression guard: OtpServiceImpl used to snapshot its endpoint paths into final fields in the
 * constructor. Because the service is built at worker start, a rotation extracted after the window
 * opened could never reach it, and a second instance built mid-race would silently disagree with the
 * first about which path to call. The paths must be resolved per call, from live config.
 */
class OtpServiceEndpointRefreshTest {

    private static final Gson GSON = new Gson();

    private static OtpServiceImpl serviceFor(AppConfig config) {
        IvacHttpClient client = IvacHttpClient.direct("01700000000");

        return new OtpServiceImpl(null, client, List.of(client), config, null, 0L);
    }

    @Test
    void endpointPathsFollowALiveConfigSwap() {
        AppConfig config = GSON.fromJson(
                "{\"endpoints\":{\"verifyOtp\":\"/otp/verifySigninOtp\",\"sendOtp\":\"/forgot-password/sendOtp\"}}",
                AppConfig.class);

        // Built before the rotation, exactly as AccountWorker builds it at cycle start.
        OtpServiceImpl service = serviceFor(config);

        assertEquals("/otp/verifySigninOtp", service.verifyOtpPath());

        config.applyProtocolConstants(new ProtocolConstants(
                Map.of("verifyOtp", "/otp/verifyRotatedOtp", "sendOtp", "/forgot-password/sendOtpV2"),
                null, null, null));

        assertEquals("/otp/verifyRotatedOtp", service.verifyOtpPath(), "verifyOtp path must not be cached");
        assertEquals("/forgot-password/sendOtpV2", service.sendOtpPath(), "sendOtp path must not be cached");
    }

    @Test
    void fallsBackToCompiledDefaultsWithoutConfig() {
        OtpServiceImpl service = serviceFor(null);

        assertEquals("/otp/verifySigninOtp", service.verifyOtpPath());
        assertEquals("/forgot-password/sendOtp", service.sendOtpPath());
    }
}
