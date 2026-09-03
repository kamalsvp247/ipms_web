package com.ivac.booking.service.signin;

import com.ivac.booking.exception.AuthenticationFatalException;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.model.domain.SigninResult;

import java.io.IOException;
import java.util.concurrent.CompletableFuture;

public interface SigninService {

    SigninResult signinAtWindowTime(long windowStartMs, CompletableFuture<CaptchaToken> captchaFuture)
            throws IOException, InterruptedException, AuthenticationFatalException;
}
