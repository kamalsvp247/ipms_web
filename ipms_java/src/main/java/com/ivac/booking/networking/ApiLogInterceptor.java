package com.ivac.booking.networking;

import okhttp3.*;
import okio.Buffer;

import java.io.IOException;

public class ApiLogInterceptor implements Interceptor {

    private final String phone;
    private final String remoteHost;

    public ApiLogInterceptor(String phone, String remoteHost) {
        this.phone = phone;
        this.remoteHost = remoteHost;
    }

    @Override
    public Response intercept(Chain chain) throws IOException {
        Request request = chain.request();

        String method = request.method();
        String url = request.url().toString();
        String requestHeaders = formatHeaders(request.headers());
        String requestBody = readRequestBody(request);

        long startMs = System.currentTimeMillis();

        String label = Thread.currentThread().getName();

        Response response;
        try {
            response = chain.proceed(request);
        } catch (IOException e) {
            ApiLogger.logError(phone, label, method, url, requestHeaders, requestBody,
                    e.getClass().getSimpleName() + ": " + e.getMessage(), remoteHost);
            throw e;
        }

        long durationMs = System.currentTimeMillis() - startMs;

        // 502/503/504 are gateway noise — skip body read and logging entirely.
        // 403 is intentionally NOT filtered: from IVAC it is an application-level signal
        // (rate limit / referer / captcha) and is critical for debugging OTP verify.
        int statusCode = response.code();
        if (statusCode == 502 || statusCode == 503 || statusCode == 504) {
            return response;
        }

        String responseBody = "";
        ResponseBody body = response.body();
        if (body != null) {
            MediaType contentType = body.contentType();
            byte[] bytes = body.bytes();
            responseBody = new String(bytes);

            // Rebuild the response body so it can still be consumed by the caller
            response = response.newBuilder()
                    .body(ResponseBody.create(bytes, contentType))
                    .build();
        }

        String responseHeaders = formatHeaders(response.headers());

        // Extract protocol version: HTTP_1_1, HTTP_2, etc. from OkHttp3 Response
        String protocolVersion = response.protocol() != null ? response.protocol().toString() : null;

        ApiLogger.log(phone, label, method, url, requestHeaders, requestBody,
                response.code(), responseHeaders, responseBody, durationMs, protocolVersion, remoteHost);

        return response;
    }

    private String formatHeaders(Headers headers) {
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < headers.size(); i++) {
            sb.append(headers.name(i)).append(": ").append(headers.value(i));
            if (i < headers.size() - 1) {
                sb.append("\n");
            }
        }
        return sb.toString();
    }

    private String readRequestBody(Request request) {
        RequestBody body = request.body();
        if (body == null) {
            return ""; // Empty string for requests with no body (GET, DELETE, etc.)
        }
        // Never log the raw bytes of a multipart upload (PDF file bytes) — it bloats the DB and
        // makes the log-analysis page slow. Log a compact summary instead.
        MediaType contentType = body.contentType();
        if (contentType != null && "multipart".equalsIgnoreCase(contentType.type())) {
            long len = -1L;
            try {
                len = body.contentLength();
            } catch (IOException ignored) {
                // contentLength is best-effort for the summary only.
            }
            return "(multipart/" + contentType.subtype() + " upload, "
                    + (len >= 0 ? len + " bytes" : "size unknown") + ")";
        }
        try {
            Buffer buffer = new Buffer();
            body.writeTo(buffer);
            return buffer.readUtf8();
        } catch (IOException e) {
            return "(failed to read request body)";
        }
    }
}
