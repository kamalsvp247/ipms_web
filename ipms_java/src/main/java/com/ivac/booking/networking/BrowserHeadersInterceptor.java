package com.ivac.booking.networking;

import okhttp3.Interceptor;
import okhttp3.Request;
import okhttp3.Response;
import org.jetbrains.annotations.NotNull;

import java.io.IOException;

/**
 * Presents a realistic Chrome browser fingerprint on every IVAC request.
 * Cloudflare bot management blocks OkHttp's default User-Agent (okhttp/x.y.z)
 * with a 403 HTML challenge page. Sending browser identity headers lets the
 * request pass Cloudflare's header-level bot checks.
 * Does not send Referer or Origin — the IVAC app rejects a Referer with
 * 403 invalid_referer. Accept-Encoding is left unset so OkHttp adds gzip and
 * transparently decodes the response.
 */
public class BrowserHeadersInterceptor implements Interceptor {

    private static final String USER_AGENT =
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36";
    private static final String SEC_CH_UA =
            "\"Not/A)Brand\";v=\"8\", \"Chromium\";v=\"126\", \"Google Chrome\";v=\"126\"";

    @Override
    public Response intercept(@NotNull Chain chain) throws IOException {
        Request request = chain.request();
        Request.Builder builder = request.newBuilder()
                .header("User-Agent", USER_AGENT)
                .header("Accept", "application/json, text/plain, */*")
                .header("Accept-Language", "en-US,en;q=0.9")
                .header("sec-ch-ua", SEC_CH_UA)
                .header("sec-ch-ua-mobile", "?0")
                .header("sec-ch-ua-platform", "\"Windows\"")
                .header("sec-fetch-site", "same-site")
                .header("sec-fetch-mode", "cors")
                .header("sec-fetch-dest", "empty");
        return chain.proceed(builder.build());
    }
}
