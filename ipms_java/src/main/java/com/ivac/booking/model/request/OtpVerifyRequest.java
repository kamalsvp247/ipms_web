package com.ivac.booking.model.request;

import com.google.gson.annotations.SerializedName;

public class OtpVerifyRequest {
    @SerializedName("requestId")
    private String requestId;

    private String phone;
    private String email;
    private String code;

    @SerializedName("otpChannel")
    private String otpChannel;

    public OtpVerifyRequest(String requestId, String phone, String email, String code, String otpChannel) {
        this.requestId = requestId;
        this.phone = phone;
        this.email = email;
        this.code = code;
        this.otpChannel = otpChannel;
    }

    public String getRequestId() {
        return requestId;
    }

    public String getPhone() {
        return phone;
    }

    public String getEmail() {
        return email;
    }

    public String getCode() {
        return code;
    }

    public String getOtpChannel() {
        return otpChannel;
    }
}
