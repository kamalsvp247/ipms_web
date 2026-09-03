package com.ivac.booking.model.response;

import com.google.gson.annotations.SerializedName;

public class BaseResponse {
    @SerializedName("statusCode")
    private int statusCode;

    @SerializedName("message")
    private String message;

    @SerializedName("error")
    private String error;

    @SerializedName("successFlag")
    private boolean successFlag;

    @SerializedName("serverTime")
    private String serverTime;

    public int getStatusCode() {
        return statusCode;
    }

    public String getMessage() {
        return message != null ? message : error;
    }

    public boolean isSuccessFlag() {
        return successFlag;
    }

    public String getServerTime() {
        return serverTime;
    }
}
