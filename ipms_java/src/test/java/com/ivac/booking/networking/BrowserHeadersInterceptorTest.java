package com.ivac.booking.networking;

import okhttp3.Call;
import okhttp3.Connection;
import okhttp3.Interceptor;
import okhttp3.MediaType;
import okhttp3.Protocol;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.ResponseBody;
import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

class BrowserHeadersInterceptorTest {

    private static Request runThrough(Request request) throws IOException {
        AtomicReference<Request> forwarded = new AtomicReference<>();
        new BrowserHeadersInterceptor().intercept(new CapturingChain(request, forwarded));
        return forwarded.get();
    }

    @Test
    void injectsChromeUserAgentInsteadOfOkHttpDefault() throws IOException {
        Request out = runThrough(new Request.Builder().url("https://api.ivacbd.com/x").get().build());

        String ua = out.header("User-Agent");
        assertNotNull(ua);
        assertTrue(ua.contains("Chrome/"), "User-Agent should look like Chrome, was: " + ua);
        assertTrue(ua.startsWith("Mozilla/5.0"), "User-Agent should start with Mozilla/5.0, was: " + ua);
    }

    @Test
    void addsBrowserFingerprintHeaders() throws IOException {
        Request out = runThrough(new Request.Builder().url("https://api.ivacbd.com/x").get().build());

        assertEquals("application/json, text/plain, */*", out.header("Accept"));
        assertEquals("en-US,en;q=0.9", out.header("Accept-Language"));
        assertEquals("?0", out.header("sec-ch-ua-mobile"));
        assertEquals("\"Windows\"", out.header("sec-ch-ua-platform"));
        assertNotNull(out.header("sec-ch-ua"));
        assertEquals("cors", out.header("sec-fetch-mode"));
    }

    @Test
    void neverSendsRefererOrOrigin() throws IOException {
        Request out = runThrough(new Request.Builder().url("https://api.ivacbd.com/x").get().build());

        assertEquals(null, out.header("Referer"));
        assertEquals(null, out.header("Origin"));
    }

    @Test
    void preservesCallerHeadersAndBody() throws IOException {
        Request in = new Request.Builder()
                .url("https://api.ivacbd.com/auth/sign-in-v2")
                .header("Content-Type", "application/json")
                .header("Authorization", "Bearer token123")
                .header("x-token", "raw-turnstile")
                .post(RequestBody.create("{\"phone\":\"01\"}", MediaType.get("application/json")))
                .build();

        Request out = runThrough(in);

        assertEquals("application/json", out.header("Content-Type"));
        assertEquals("Bearer token123", out.header("Authorization"));
        assertEquals("raw-turnstile", out.header("x-token"));
        assertEquals("POST", out.method());
        assertNotNull(out.body());
    }

    /**
     * Minimal Interceptor.Chain that captures the request handed to proceed()
     * and returns a stub 200 response. Only request()/proceed() are exercised.
     */
    private static final class CapturingChain implements Interceptor.Chain {
        private final Request request;
        private final AtomicReference<Request> forwarded;

        CapturingChain(Request request, AtomicReference<Request> forwarded) {
            this.request = request;
            this.forwarded = forwarded;
        }

        @Override
        public Request request() {
            return request;
        }

        @Override
        public Response proceed(Request request) {
            forwarded.set(request);
            return new Response.Builder()
                    .request(request)
                    .protocol(Protocol.HTTP_2)
                    .code(200)
                    .message("OK")
                    .body(ResponseBody.create("{}", MediaType.get("application/json")))
                    .build();
        }

        @Override
        public Connection connection() {
            return null;
        }

        @Override
        public Call call() {
            throw new UnsupportedOperationException();
        }

        @Override
        public int connectTimeoutMillis() {
            return 0;
        }

        @Override
        public Interceptor.Chain withConnectTimeout(int timeout, TimeUnit unit) {
            return this;
        }

        @Override
        public int readTimeoutMillis() {
            return 0;
        }

        @Override
        public Interceptor.Chain withReadTimeout(int timeout, TimeUnit unit) {
            return this;
        }

        @Override
        public int writeTimeoutMillis() {
            return 0;
        }

        @Override
        public Interceptor.Chain withWriteTimeout(int timeout, TimeUnit unit) {
            return this;
        }
    }
}
