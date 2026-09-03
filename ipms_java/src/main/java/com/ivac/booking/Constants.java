package com.ivac.booking;

public class Constants {

    // Portal URL — hardcoded; no .env needed on VPS workers.
    public static final String PORTAL_URL = "https://ipms.senda.fit";

    // API base URL (api.ivacbd.com only)
    public static final String API_CF_HOST = "api.ivacbd.com";
    public static final String BASE_URL = "https://" + API_CF_HOST + "/iams/api/v1";

    // Static x-sec-navigation-state UUID baked into the IVAC frontend bundle; sent on /auth/v23-sign-in.
    // Rotates on IVAC redeploy — re-decode from the bundle if sign-in starts 400/403ing.
    public static final String SIGNIN_NAVIGATION_STATE = "80d51dc5-af20-46fa-a7bb-e6a8f3f80065";

    // Static x-sec-runtime-state token baked into the IVAC frontend bundle; sent on /file/upload_file.
    // Rotates on IVAC redeploy — re-decode from the bundle if PDF upload starts 400/403ing.
    public static final String UPLOAD_RUNTIME_STATE = "v1.5a4c8831.9a53.47ed.b579.042a2c0cee5a";

    // Timeouts
    public static final int INITIATE_REQUEST_TIMEOUT_MS = 180_000;
    public static final long RESERVATION_TTL_MS = 270_000L;

    public static final long[] OTP_VERIFY_403_BACKOFF_MS  = { 2_000L, 4_000L, 8_000L, 16_000L };

    // Retry thresholds
    public static final int MAX_CONSECUTIVE_401S = 9;
    public static final int PAYMENT_429_SHIFT_CAP = 2;
    public static final long PAYMENT_429_MAX_BACKOFF_MS = 20_000L;

    // Backoff delays
    public static final long PAYMENT_429_BASE_BACKOFF_MS = 20_000L;
    public static final int CAPTCHA_400_RETRY_DELAY_MS = 1_000;

    // Payment aggressive mode — parallel burst when slot/JWT near expiry

    public static final long SLEEP_NORMAL_CHUNK_MS = 1_000L;

    // Captcha shelf life is NOT a constant. It comes from the portal as captchaShelfLifeMs and is
    // read through AppConfig.getCaptchaShelfLifeMs(), which falls back to 20s when config omits it.
    // A duplicate constant lived here and silently pinned CaptchaToken.isExpired() to 20s no matter
    // what the portal said; both are gone. Pass the AppConfig value into isOlderThan(ms) instead.
}
