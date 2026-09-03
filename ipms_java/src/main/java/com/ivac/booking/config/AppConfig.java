package com.ivac.booking.config;

import com.google.gson.annotations.SerializedName;
import com.ivac.booking.Constants;

import java.util.List;
import java.util.Map;

public class AppConfig {

    @SerializedName("baseUrl")
    private String baseUrl;

    @SerializedName("ipmsWebBaseUrl")
    private String ipmsWebBaseUrl;

    @SerializedName("maxRetries")
    private int maxRetries;

    @SerializedName("captchaFetchSecondsBeforeWindow")
    private int captchaFetchSecondsBeforeWindow;

    @SerializedName("signInRetryDelayMs")
    private int signInRetryDelayMs;

    @SerializedName("otpIntervalDelayMs")
    private int otpIntervalDelayMs;

    @SerializedName("otpTimeoutMs")
    private int otpTimeoutMs;

    @SerializedName("reserveSlotRetryDelayMs")
    private int reserveSlotRetryDelayMs;

    @SerializedName("initiatePaymentRetryDelayMs")
    private int initiatePaymentRetryDelayMs;

    @SerializedName("forgotPasswordLeadSeconds")
    private int forgotPasswordLeadSeconds;

    @SerializedName("slotFullSlowDelayMs")
    private long slotFullSlowDelayMs;

    @SerializedName("bypassIps")
    private List<String> bypassIps;

    @SerializedName("windowStartTime")
    private String windowStartTime;

    @SerializedName("windowEndTime")
    private String windowEndTime;

    @SerializedName("reserveSlotId")
    private String reserveSlotId;

    @SerializedName("paymentConfigId")
    private String paymentConfigId;

    @SerializedName("reserveRequestMeta")
    private String reserveRequestMeta;

    @SerializedName("signin429ProxyUrl")
    private String signin429ProxyUrl;

    @SerializedName("captchaShelfLifeMs")
    private long captchaShelfLifeMs;

    // Every IVAC high commission the portal offers, so booking config can try another centre when
    // IVAC rejects the account's configured one with 400 "Invalid High Commission.".
    @SerializedName("bookingCityOptions")
    private List<BookingCityOption> bookingCityOptions;

    // IVAC endpoint paths + rotating headers, bundle-extracted by the portal and delivered here so a
    // redeploy rotation is adopted with no source edit or JAR rebuild. Any missing/blank key falls back
    // to the compiled-in default in the matching getter, so a bad/empty sync never breaks a call.
    @SerializedName("endpoints")
    private Map<String, String> endpoints;

    // Opaque hash of the portal's bundle-derived constants. The portal stamps the same value on every
    // captcha poll response, so the bot can tell that a mid-window extraction changed something and
    // refresh before using the token that unblocked it.
    @SerializedName("configVersion")
    private String configVersion;

    // Live view of the four bundle-derived constants above, swappable while a race runs. See
    // ProtocolConstants for why only these values may change in flight. transient so Gson leaves it
    // alone; volatile so a swap publishes safely to every worker thread without locking readers.
    private transient volatile ProtocolConstants protocol;

    @SerializedName("accounts")
    private List<AccountConfig> accounts;

    public String getBaseUrl() {
        return baseUrl;
    }

    public List<AccountConfig> getAccounts() {
        return accounts;
    }

    public int getMaxRetries() {
        return maxRetries;
    }

    public int getSignInRetryDelayMs() {
        return signInRetryDelayMs > 0 ? signInRetryDelayMs : 500;
    }

    public int getOtpIntervalDelayMs() {
        return otpIntervalDelayMs;
    }

    public int getOtpTimeoutMs() {
        return otpTimeoutMs;
    }

    public int getInitiatePaymentRetryDelayMs() {
        return initiatePaymentRetryDelayMs;
    }

    public String getWindowStartTime() {
        return windowStartTime;
    }

    public String getWindowEndTime() {
        return windowEndTime;
    }

    public String getConfigVersion() {
        return configVersion;
    }

    /**
     * The live bundle-derived constants. Seeded on first read from the fields Gson populated, then
     * replaced by applyProtocolConstants() whenever the portal reports a new config version.
     *
     * Seeding is lazy rather than done in a constructor because Gson may allocate the instance
     * without running field initializers. A race here is benign: two threads would build the same
     * immutable snapshot from the same final-in-practice Gson fields.
     */
    private ProtocolConstants protocol() {
        ProtocolConstants current = protocol;

        if (current == null) {
            current = new ProtocolConstants(endpoints, reserveSlotId, paymentConfigId, reserveRequestMeta);
            protocol = current;
        }

        return current;
    }

    public ProtocolConstants getProtocolConstants() {
        return protocol();
    }

    /**
     * Swaps in freshly fetched constants, merged per key so a partial payload cannot downgrade a good
     * value. Safe to call while a race is in flight: readers see one immutable snapshot or the next,
     * never a mix. Synchronized because the merge is a read-modify-write; reads stay lock-free.
     *
     * Returns a summary of what changed, or an empty string when the swap was a no-op.
     */
    public synchronized String applyProtocolConstants(ProtocolConstants fresh) {
        if (fresh == null) {
            return "";
        }

        ProtocolConstants before = protocol();
        ProtocolConstants merged = before.mergedWith(fresh);
        protocol = merged;

        return before.describeChanges(merged);
    }

    public String getReserveSlotId() {
        return protocol().reserveSlotId();
    }

    public String getPaymentConfigId() {
        return protocol().paymentConfigId();
    }

    public String getReserveRequestMeta() {
        return protocol().reserveRequestMeta();
    }

    // Returns the bundle-extracted endpoint value for key, or the compiled-in fallback when the config
    // omits it (missing map, missing key, or blank) — so the bot always has a working path/header.
    private String endpoint(String key, String fallback) {
        String value = protocol().endpoints().get(key);

        if (value != null && !value.isBlank()) {
            return value;
        }

        return fallback;
    }

    public String getSigninPath() {
        return endpoint("signin", "/auth/v23-sign-in");
    }

    public String getSendOtpPath() {
        return endpoint("sendOtp", "/forgot-password/sendOtp");
    }

    public String getVerifyOtpPath() {
        return endpoint("verifyOtp", "/otp/verifySigninOtp");
    }

    public String getUploadFilePath() {
        return endpoint("uploadFile", "/file/upload_file_v23");
    }

    public String getBookingConfigPath() {
        return endpoint("bookingConfig", "/appointment/appointment-booking-config");
    }

    public String getGetBookingConfigPath() {
        return endpoint("getBookingConfig", "/appointment/get-booking-config");
    }

    // Template with a {reserveSlotId} placeholder; callers substitute the separately-synced UUID.
    public String getReserveSlotPathTemplate() {
        return endpoint("reserveSlot", "/slots/{reserveSlotId}/reserve-slot");
    }

    // Template with a {paymentConfigId} placeholder; callers substitute the separately-synced UUID.
    public String getPaymentPathTemplate() {
        return endpoint("payment", "/payment/{paymentConfigId}/dg-epay/initiate");
    }

    // Read-only: the bot corrects the applicant form to match the profile, never the profile itself,
    // so /profile/sendOtp and /profile/verifyAndUpdate are deliberately not called from here.
    public String getProfilePath() {
        return endpoint("profile", "/profile");
    }

    public String getSigninNavState() {
        return endpoint("signinNavState", Constants.SIGNIN_NAVIGATION_STATE);
    }

    public String getUploadRuntimeState() {
        return endpoint("uploadRuntimeState", Constants.UPLOAD_RUNTIME_STATE);
    }

    public String getSignin429ProxyUrl() {
        return signin429ProxyUrl;
    }

    public String getIpmsWebBaseUrl() {
        return ipmsWebBaseUrl;
    }

    public int getForgotPasswordLeadSeconds() {
        return forgotPasswordLeadSeconds;
    }

    public long getSlotFullSlowDelayMs() {
        return slotFullSlowDelayMs >= 0 ? slotFullSlowDelayMs : 2_000L;
    }

    public long getCaptchaShelfLifeMs() {
        return captchaShelfLifeMs > 0 ? captchaShelfLifeMs : 20_000L;
    }

    public List<String> getBypassIps() {
        return (bypassIps != null) ? bypassIps : List.of();
    }

    /**
     * Every IVAC high commission the portal knows, used by booking config to rotate off a centre
     * IVAC rejects with "Invalid High Commission.". Falls back to the compiled-in list when an older
     * portal omits the key, so the rotation always has somewhere to go.
     */
    public List<BookingCityOption> getBookingCityOptions() {
        if (bookingCityOptions == null || bookingCityOptions.isEmpty()) {
            return BookingCityOption.DEFAULTS;
        }

        List<BookingCityOption> usable = bookingCityOptions.stream()
                .filter(option -> option != null && option.isUsable())
                .toList();

        return usable.isEmpty() ? BookingCityOption.DEFAULTS : usable;
    }
}
