package com.ivac.booking.model.response;

import com.google.gson.annotations.SerializedName;

public class ForgotPasswordResponse extends BaseResponse {
    private Data data;

    public Data getData() {
        return data;
    }

    public static class Data {
        @SerializedName("requestId")
        private String requestId;

        @SerializedName("expiresAt")
        private String expiresAt;

        public String getRequestId() {
            return requestId;
        }

        public String getExpiresAt() {
            return expiresAt;
        }
    }
}
