package com.ivac.booking.model.response;

import com.google.gson.annotations.SerializedName;

import java.util.List;

public class SigninResponse extends BaseResponse {
    private Data data;

    public Data getData() {
        return data;
    }

    public static class Data {
        @SerializedName("accessToken")
        private String accessToken;

        @SerializedName("tokenType")
        private String tokenType;

        @SerializedName("expiresAt")
        private int expiresAt;

        @SerializedName("userId")
        private String userId;

        @SerializedName("requestId")
        private String requestId;

        @SerializedName("roles")
        private List<String> roles;

        public String getAccessToken() {
            return accessToken;
        }

        public String getTokenType() {
            return tokenType;
        }

        public int getExpiresAt() {
            return expiresAt;
        }

        public String getUserId() {
            return userId;
        }

        public String getRequestId() {
            return requestId;
        }

        public List<String> getRoles() {
            return roles;
        }
    }
}
