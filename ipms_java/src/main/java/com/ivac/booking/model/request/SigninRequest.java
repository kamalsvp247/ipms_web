package com.ivac.booking.model.request;

public class SigninRequest {

    private final String phone;
    private final String password;
    private final String c;

    public SigninRequest(String phone, String password, String captchaToken) {
        this.phone = phone;
        this.password = password;
        this.c = captchaToken;
    }

    public String getPhone() {
        return phone;
    }

    public String getPassword() {
        return password;
    }

    public String getC() {
        return c;
    }
}
