package com.ivac.booking.config;

import com.google.gson.annotations.SerializedName;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.concurrent.ThreadLocalRandom;
import java.util.concurrent.locks.ReentrantLock;

public class AccountConfig {

    @SerializedName("id")
    private int id;

    @SerializedName("phone")
    private String phone;

    @SerializedName("email")
    private String email;

    @SerializedName("tag")
    private String tag;

    @SerializedName("appointmentId")
    private String appointmentId;

    @SerializedName("appointmentDates")
    private java.util.List<String> appointmentDates;

    // Randomized round-robin over appointmentDates; not part of the config payload.
    private final transient ShuffledRotation appointmentDateRotation = new ShuffledRotation();

    // Appointment dates IVAC itself returns from GET /appointment/get-booking-config for this
    // account (populated once mission/center are selected). When present and non-empty these
    // REPLACE the portal-configured appointmentDates for reserve-slot — they are the authoritative
    // set the server will accept. Captured at runtime by the setup task; not part of the payload.
    private transient volatile java.util.List<String> ivacAppointmentDates;
    private final transient ShuffledRotation ivacAppointmentDateRotation = new ShuffledRotation();

    @SerializedName("password")
    private String password;

    @SerializedName("signinTickShots")
    private int signinTickShots = 10;

    @SerializedName("signinTickIntervalMs")
    private long signinTickIntervalMs = 1_000L;

    @SerializedName("singleSignIn")
    private boolean singleSignIn = false;

    @SerializedName("retryDelayMs")
    private long retryDelayMs = 5_000L;

    @SerializedName("otpTickShots")
    private int otpTickShots = 10;

    @SerializedName("otpTickIntervalMs")
    private long otpTickIntervalMs = 1_000L;

    @SerializedName("slotTickShots")
    private int slotTickShots = 10;

    @SerializedName("slotTickIntervalMs")
    private long slotTickIntervalMs = 1_000L;

    @SerializedName("paymentTickShots")
    private int paymentTickShots = 10;

    @SerializedName("paymentTickIntervalMs")
    private long paymentTickIntervalMs = 1_000L;

    @SerializedName("accessToken")
    private String accessToken;

    @SerializedName("jwtExpiresAtMs")
    private long jwtExpiresAtMs;

    @SerializedName("isOtpVerified")
    private boolean otpVerified;

    @SerializedName("signinRequestId")
    private String signinRequestId;

    @SerializedName("signinServerTimeMs")
    private long signinServerTimeMs;

    @SerializedName("maxRetries")
    private int maxRetries;

    // One-time setup: whether all applicant PDFs have been uploaded to IVAC and the
    // booking config posted. Persisted on the portal so a bot restart does not repeat them.
    @SerializedName("pdfUploaded")
    private boolean pdfUploaded;

    @SerializedName("bookingConfigured")
    private boolean bookingConfigured;

    // Booking config payload IVAC expects, resolved by the portal from the account's city.
    @SerializedName("bookingMission")
    private String bookingMission;

    @SerializedName("bookingIvacCenter")
    private String bookingIvacCenter;

    public AccountConfig() {
    }

    public AccountConfig(String phone, String email, String tag, String password) {
        this.phone = phone;
        this.email = email;
        this.tag = tag;
        this.password = password;
    }

    public String getPhone() {
        return phone;
    }

    public void setPhone(String phone) {
        this.phone = phone;
    }

    public String getEmail() {
        return email;
    }

    public void setEmail(String email) {
        this.email = email;
    }

    public String getTag() {
        return tag;
    }

    public void setTag(String tag) {
        this.tag = tag;
    }

    public String getAppointmentId() {
        return appointmentId;
    }

    public void setAppointmentId(String appointmentId) {
        this.appointmentId = appointmentId;
    }

    public java.util.List<String> getAppointmentDates() {
        return appointmentDates;
    }

    public void setAppointmentDates(java.util.List<String> appointmentDates) {
        this.appointmentDates = appointmentDates;
    }

    public java.util.List<String> getIvacAppointmentDates() {
        return ivacAppointmentDates;
    }

    public void setIvacAppointmentDates(java.util.List<String> ivacAppointmentDates) {
        this.ivacAppointmentDates = ivacAppointmentDates;
    }

    /**
     * Returns the next appointment date as a single string, rotating one-by-one through the list
     * on each call in a randomized order. Prefers IVAC's own available dates (from
     * get-booking-config) when the setup task has captured them, since those are the dates the
     * server will actually accept; falls back to the portal-configured range otherwise. Empty
     * string when no dates are available.
     */
    public String nextAppointmentDate() {
        List<String> ivac = ivacAppointmentDates;
        if (ivac != null && !ivac.isEmpty()) {
            return ivacAppointmentDateRotation.next(ivac);
        }
        if (appointmentDates == null || appointmentDates.isEmpty()) {
            return "";
        }
        return appointmentDateRotation.next(appointmentDates);
    }

    /**
     * Hands out one entry at a time in a random order, reshuffling once every entry has been used.
     * Coverage stays round-robin - each date is used exactly once per cycle, so none is skipped or
     * repeated while others are still untried - but the order differs every cycle, so parallel
     * shots and restarts do not all pile onto the same date first.
     * Replacing the source list (IVAC's dates arriving mid-race) starts a fresh cycle.
     */
    private static final class ShuffledRotation {

        private final ReentrantLock lock = new ReentrantLock();
        private List<String> source;
        private List<String> order = List.of();
        private int cursor;

        String next(List<String> dates) {
            lock.lock();
            try {
                if (dates != source || cursor >= order.size()) {
                    source = dates;
                    order = new ArrayList<>(dates);
                    Collections.shuffle(order, ThreadLocalRandom.current());
                    cursor = 0;
                }
                return order.get(cursor++);
            } finally {
                lock.unlock();
            }
        }
    }

    public String getPassword() {
        return password;
    }

    public void setPassword(String password) {
        this.password = password;
    }

    public int getSigninTickShots() {
        return signinTickShots > 0 ? signinTickShots : 10;
    }

    public void setSigninTickShots(int signinTickShots) {
        this.signinTickShots = signinTickShots;
    }

    public long getSigninTickIntervalMs() {
        return signinTickIntervalMs > 0 ? signinTickIntervalMs : 1_000L;
    }

    public void setSigninTickIntervalMs(long signinTickIntervalMs) {
        this.signinTickIntervalMs = signinTickIntervalMs;
    }

    public boolean isSingleSignIn() {
        return singleSignIn;
    }

    public void setSingleSignIn(boolean singleSignIn) {
        this.singleSignIn = singleSignIn;
    }

    public long getRetryDelayMs() {
        return retryDelayMs > 0 ? retryDelayMs : 5_000L;
    }

    public void setRetryDelayMs(long retryDelayMs) {
        this.retryDelayMs = retryDelayMs;
    }

    public int getOtpTickShots() {
        return otpTickShots > 0 ? otpTickShots : 10;
    }

    public void setOtpTickShots(int otpTickShots) {
        this.otpTickShots = otpTickShots;
    }

    public long getOtpTickIntervalMs() {
        return otpTickIntervalMs > 0 ? otpTickIntervalMs : 1_000L;
    }

    public void setOtpTickIntervalMs(long otpTickIntervalMs) {
        this.otpTickIntervalMs = otpTickIntervalMs;
    }

    public int getSlotTickShots() {
        return slotTickShots > 0 ? slotTickShots : 10;
    }

    public void setSlotTickShots(int slotTickShots) {
        this.slotTickShots = slotTickShots;
    }

    public long getSlotTickIntervalMs() {
        return slotTickIntervalMs > 0 ? slotTickIntervalMs : 1_000L;
    }

    public void setSlotTickIntervalMs(long slotTickIntervalMs) {
        this.slotTickIntervalMs = slotTickIntervalMs;
    }

    public int getPaymentTickShots() {
        return paymentTickShots > 0 ? paymentTickShots : 10;
    }

    public void setPaymentTickShots(int paymentTickShots) {
        this.paymentTickShots = paymentTickShots;
    }

    public long getPaymentTickIntervalMs() {
        return paymentTickIntervalMs > 0 ? paymentTickIntervalMs : 1_000L;
    }

    public void setPaymentTickIntervalMs(long paymentTickIntervalMs) {
        this.paymentTickIntervalMs = paymentTickIntervalMs;
    }

    public String getAccessToken() {
        return accessToken;
    }

    public void setAccessToken(String accessToken) {
        this.accessToken = accessToken;
    }

    public long getJwtExpiresAtMs() {
        return jwtExpiresAtMs;
    }

    public void setJwtExpiresAtMs(long jwtExpiresAtMs) {
        this.jwtExpiresAtMs = jwtExpiresAtMs;
    }

    public boolean isOtpVerified() {
        return otpVerified;
    }

    /**
     * Set when this process verifies an OTP, so a restart cycle skips the OTP phase for the
     * JWT it is reusing. Cleared whenever a fresh sign-in mints a new token.
     */
    public void setOtpVerified(boolean otpVerified) {
        this.otpVerified = otpVerified;
    }

    public String getSigninRequestId() {
        return signinRequestId;
    }

    public void setSigninRequestId(String signinRequestId) {
        this.signinRequestId = signinRequestId;
    }

    public long getSigninServerTimeMs() {
        return signinServerTimeMs;
    }

    public void setSigninServerTimeMs(long signinServerTimeMs) {
        this.signinServerTimeMs = signinServerTimeMs;
    }

    /**
     * Per-account daily sign-in cap. 0 (or unset) means no limit.
     */
    public int getMaxRetries() {
        return maxRetries;
    }

    public int getId() {
        return id;
    }

    public boolean isPdfUploaded() {
        return pdfUploaded;
    }

    public void setPdfUploaded(boolean pdfUploaded) {
        this.pdfUploaded = pdfUploaded;
    }

    public boolean isBookingConfigured() {
        return bookingConfigured;
    }

    public void setBookingConfigured(boolean bookingConfigured) {
        this.bookingConfigured = bookingConfigured;
    }

    public String getBookingMission() {
        return bookingMission;
    }

    public String getBookingIvacCenter() {
        return bookingIvacCenter;
    }

    @Override
    public String toString() {
        return "AccountConfig{" +
            "phone='" + phone + '\'' +
            ", email='" + email + '\'' +
            ", tag='" + tag + '\'' +
            ", password='" + password + '\'' +
            ", signinTickShots=" + signinTickShots +
            ", signinTickIntervalMs=" + signinTickIntervalMs +
            ", otpTickShots=" + otpTickShots +
            ", otpTickIntervalMs=" + otpTickIntervalMs +
            ", slotTickShots=" + slotTickShots +
            ", slotTickIntervalMs=" + slotTickIntervalMs +
            ", paymentTickShots=" + paymentTickShots +
            ", paymentTickIntervalMs=" + paymentTickIntervalMs +
            ", jwtExpiresAtMs=" + jwtExpiresAtMs +
            '}';
    }
}
