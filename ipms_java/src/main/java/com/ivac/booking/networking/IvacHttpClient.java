package com.ivac.booking.networking;

import com.ivac.booking.Constants;
import com.ivac.booking.util.HttpUtil;
import com.ivac.booking.util.PublicIpResolver;
import okhttp3.*;
import org.jetbrains.annotations.NotNull;

import java.io.Closeable;
import java.io.IOException;
import java.net.Inet6Address;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.Proxy;
import java.net.URI;
import java.net.UnknownHostException;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;

public class IvacHttpClient implements Closeable {

    private static final MediaType JSON_TYPE = MediaType.get("application/json; charset=utf-8");
    // Per-account IPv6 source binding makes every account a distinct route, so accounts cannot
    // share pooled connections. At ~1-2 keepalive connections each, a 100-connection cap starts
    // evicting idle connections past ~50 accounts and forces a fresh TLS handshake every tick.
    // Sized for 500 accounts on one worker: each needs its own route, and an account that hits
    // an edge-throttle 429 folds a second (fallback-bound) client into rotation on top of that.
    private static final ConnectionPool SHARED_POOL = new ConnectionPool(2000, 15, TimeUnit.MINUTES);

    private final OkHttpClient okHttpClient;
    private final String phone;
    private final String remoteHost;
    private volatile String accessToken;

    private IvacHttpClient(OkHttpClient okHttpClient, String phone, String remoteHost) {
        this.okHttpClient = okHttpClient;
        this.phone = phone;
        this.remoteHost = remoteHost;
    }

    /**
     * Direct connection to api.ivacbd.com via normal DNS (through Cloudflare).
     * When the machine has globally-routable IPv6 addresses, this binds the connection to a
     * per-account source address from Ipv6SourcePool (round-robin) and forces an IPv6
     * destination, so the account egresses from a distinct IPv6. Falls back to the plain
     * IPv4 path unchanged when no usable IPv6 exists.
     */
    public static IvacHttpClient direct(String phone) {
        Ipv6SourcePool ipv6Pool = Ipv6SourcePool.getInstance();

        if (ipv6Pool.isEnabled()) {
            return boundToIpv6(ipv6Pool.next(), phone);
        }

        String publicIpv4 = PublicIpResolver.getPublicIpv4();
        String remoteHost = publicIpv4 != null ? publicIpv4 : Constants.API_CF_HOST;

        IvacHttpClient client = new IvacHttpClient(baseBuilder(phone, remoteHost).build(), phone, remoteHost);
        client.prewarm();
        return client;
    }

    /**
     * Direct client bound to a specific IPv6 source address, forcing an IPv6 destination.
     * Used by direct() for the account's initial source and by EdgeThrottleFallbackManager
     * to hop to another source address when the edge throttles the current one.
     */
    public static IvacHttpClient boundToIpv6(Inet6Address source, String phone) {
        String remoteHost = source.getHostAddress();

        OkHttpClient okHttp = baseBuilder(phone, remoteHost)
            .socketFactory(new BoundSocketFactory(source))
            .dns(new Ipv6OnlyDns())
            .build();

        IvacHttpClient client = new IvacHttpClient(okHttp, phone, remoteHost);
        client.prewarm();
        return client;
    }

    /**
     * Bypass client: DNS override maps api.ivacbd.com → bypassIp.
     * The URL stays api.ivacbd.com so OkHttp sets TLS SNI correctly.
     */
    public static IvacHttpClient bypass(String bypassIp, String phone) {
        OkHttpClient okHttp = baseBuilder(phone, bypassIp)
            .dns(hostname -> {
                if (Constants.API_CF_HOST.equals(hostname)) {
                    try {
                        return List.of(InetAddress.getByName(bypassIp));
                    } catch (UnknownHostException e) {
                        return Dns.SYSTEM.lookup(hostname);
                    }
                }

                return Dns.SYSTEM.lookup(hostname);
            })
            .build();

        IvacHttpClient client = new IvacHttpClient(okHttp, phone, bypassIp);
        client.prewarm();

        return client;
    }

    /**
     * Forward proxy client (HTTP CONNECT, Basic auth) — e.g. Oxylabs. Used as a fallback when
     * IVAC's edge/WAF throttles the worker's own egress IP (sign-in 429 "Too many request
     * detected", no server-stated cooldown). proxyUrl format: http://user:pass@host:port.
     */
    public static IvacHttpClient proxy(String proxyUrl, String phone) {
        URI uri = URI.create(proxyUrl);
        String userInfo = uri.getUserInfo();
        int sep = userInfo == null ? -1 : userInfo.indexOf(':');
        String username = sep >= 0 ? userInfo.substring(0, sep) : userInfo;
        String password = sep >= 0 ? userInfo.substring(sep + 1) : "";

        Proxy javaProxy = new Proxy(Proxy.Type.HTTP, new InetSocketAddress(uri.getHost(), uri.getPort()));
        Authenticator proxyAuthenticator = (route, response) -> response.request().newBuilder()
            .header("Proxy-Authorization", Credentials.basic(username, password))
            .build();

        String remoteHost = "proxy:" + uri.getHost();
        OkHttpClient okHttp = baseBuilder(phone, remoteHost)
            .proxy(javaProxy)
            .proxyAuthenticator(proxyAuthenticator)
            .build();

        IvacHttpClient client = new IvacHttpClient(okHttp, phone, remoteHost);
        client.prewarm();
        return client;
    }

    private void prewarm() {
        Request request = new Request.Builder()
            .url(Constants.BASE_URL)
            .head()
            .build();

        okHttpClient.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(@NotNull Call call, @NotNull IOException e) {
            }

            @Override
            public void onResponse(@NotNull Call call, @NotNull Response response) {
                response.close();
            }
        });
    }

    private static OkHttpClient.Builder baseBuilder(String phone, String remoteHost) {
        return new OkHttpClient.Builder()
            .connectTimeout(180, TimeUnit.SECONDS)
            .readTimeout(180, TimeUnit.SECONDS)
            .writeTimeout(180, TimeUnit.SECONDS)
            .connectionPool(SHARED_POOL)
            .followRedirects(false)
            .addInterceptor(new BrowserHeadersInterceptor())
            .addInterceptor(new ApiLogInterceptor(phone, remoteHost));
    }

    public String getRemoteHost() {
        return remoteHost;
    }

    // Cancels all in-flight calls dispatched through this client's dispatcher.
    // Safe to call from another thread — each IvacHttpClient has its own Dispatcher.
    public void cancelInFlightCalls() {
        okHttpClient.dispatcher().cancelAll();
    }

    // Cancels in-flight calls (running + queued) whose request URL path contains the given
    // substring, leaving all other calls untouched. Used to abort pending OTP verify calls once
    // a slot is reserved, without cancelling the winning slot reserve or the payment call.
    // Returns the number of calls cancelled. runningCalls() includes synchronous execute() calls.
    public int cancelCallsForPath(String pathSubstring) {
        Dispatcher dispatcher = okHttpClient.dispatcher();
        int cancelled = 0;
        for (Call call : dispatcher.runningCalls()) {
            if (call.request().url().encodedPath().contains(pathSubstring)) {
                call.cancel();
                cancelled++;
            }
        }
        for (Call call : dispatcher.queuedCalls()) {
            if (call.request().url().encodedPath().contains(pathSubstring)) {
                call.cancel();
                cancelled++;
            }
        }
        return cancelled;
    }

    public String getAccessToken() {
        return accessToken;
    }

    public void setAccessToken(String token) {
        this.accessToken = token;
    }

    public String postUnauthenticated(String url, Object body) throws IOException {
        return postUnauthenticated(url, body, null);
    }

    public String postUnauthenticated(String url, Object body, Map<String, String> extraHeaders) throws IOException {
        String jsonBody = HttpUtil.serializeBody(body);
        Request.Builder builder = new Request.Builder()
            .url(url)
            .header("Content-Type", "application/json")
            .post(RequestBody.create(jsonBody, JSON_TYPE));
        if (extraHeaders != null) {
            for (Map.Entry<String, String> header : extraHeaders.entrySet()) {
                builder.header(header.getKey(), header.getValue());
            }
        }
        Request request = builder.build();
        try (Response response = okHttpClient.newCall(request).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";

            if (!response.isSuccessful()) {
                throw new HttpStatusException(response.code(), responseBody, "POST failed: HTTP " + response.code());
            }

            HttpUtil.validateSuccessFlag(responseBody, url);
            return responseBody;
        }
    }

    public RawResponse getRaw(String path) throws IOException {
        String token = accessToken;
        Request.Builder builder = new Request.Builder()
            .url(Constants.BASE_URL + path)
            .header("Content-Type", "application/json")
            .get();
        if (token != null) {
            builder.header("Authorization", "Bearer " + token);
        }
        try (Response response = okHttpClient.newCall(builder.build()).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";
            return new RawResponse(response.code(), responseBody);
        }
    }

    /**
     * GET an absolute URL with no auth header and without following redirects — used for the
     * post-payment gateway callback. A 302 with a Location header signals payment success, so
     * the redirect must be observed directly rather than followed. The bypass DNS still applies
     * when the host is api.ivacbd.com, so the call routes through this client's bypass IP.
     */
    public RedirectResponse getAbsoluteNoAuthNoRedirect(String absoluteUrl) throws IOException {
        OkHttpClient noRedirectClient = okHttpClient.newBuilder()
            .followRedirects(false)
            .followSslRedirects(false)
            .build();
        Request request = new Request.Builder()
            .url(absoluteUrl)
            .get()
            .build();
        try (Response response = noRedirectClient.newCall(request).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";
            return new RedirectResponse(response.code(), response.header("Location"), responseBody);
        }
    }

    public RawResponse postRawNoAuthRetry(String path, Object body) throws IOException {
        return doPost(Constants.BASE_URL + path, body, -1, null);
    }

    public RawResponse postRawNoAuthRetry(String path, Object body, int readTimeoutMs) throws IOException {
        return doPost(Constants.BASE_URL + path, body, readTimeoutMs, null);
    }

    public RawResponse postRawNoAuthRetry(String path, Object body, int readTimeoutMs, Map<String, String> extraHeaders) throws IOException {
        return doPost(Constants.BASE_URL + path, body, readTimeoutMs, extraHeaders);
    }

    private RawResponse doPost(String url, Object body, int readTimeoutMs, Map<String, String> extraHeaders) throws IOException {
        String jsonBody = body != null ? HttpUtil.serializeBody(body) : "{}";
        String token = accessToken;

        Request.Builder builder = new Request.Builder()
            .url(url)
            .header("Content-Type", "application/json")
            .post(RequestBody.create(jsonBody, JSON_TYPE));

        if (token != null) {
            builder.header("Authorization", "Bearer " + token);
        }

        if (extraHeaders != null) {
            for (Map.Entry<String, String> entry : extraHeaders.entrySet()) {
                builder.header(entry.getKey(), entry.getValue());
            }
        }

        OkHttpClient callClient = (readTimeoutMs > 0)
            ? okHttpClient.newBuilder().readTimeout(readTimeoutMs, TimeUnit.MILLISECONDS).build()
            : okHttpClient;

        try (Response response = callClient.newCall(builder.build()).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";
            return new RawResponse(response.code(), responseBody);
        }
    }

    /**
     * Authenticated POST with an empty body, matching the site's POST /appointment call
     * (fetch body:null, no Content-Type — only Authorization). Creates the appointment record
     * that the file service requires; without it upload returns 404 "Appointment not found."
     */
    public RawResponse postEmpty(String path) throws IOException {
        Request.Builder builder = new Request.Builder()
            .url(Constants.BASE_URL + path)
            .post(RequestBody.create(new byte[0], null));

        if (accessToken != null) {
            builder.header("Authorization", "Bearer " + accessToken);
        }

        try (Response response = okHttpClient.newCall(builder.build()).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";
            return new RawResponse(response.code(), responseBody);
        }
    }

    /**
     * Multipart POST for PDF upload (POST /file/upload_file). Sends only the fields the
     * IVAC site sends — form parts "files" (application/pdf) + "isPrimary" ("true"/"false"),
     * and headers Authorization (auto) + x-token (raw captcha) + x-sec-runtime-state (bundle
     * token, passed in by the caller from config; rotates on redeploy). No Content-Type is set
     * here; OkHttp's MultipartBody adds the multipart boundary. Do NOT add X-Device-ID — the file
     * service rejects it with 404 "Appointment not found." (see api-tester's ivacFileUpload).
     */
    public RawResponse postMultipartFile(String path, byte[] fileBytes, String fileName, boolean isPrimary, String xToken, String runtimeState) throws IOException {
        String token = accessToken;

        RequestBody fileBody = RequestBody.create(fileBytes, MediaType.get("application/pdf"));
        MultipartBody multipart = new MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart("files", fileName != null && !fileName.isBlank() ? fileName : "document.pdf", fileBody)
            .addFormDataPart("isPrimary", isPrimary ? "true" : "false")
            .build();

        Request.Builder builder = new Request.Builder()
            .url(Constants.BASE_URL + path)
            .post(multipart);

        if (token != null) {
            builder.header("Authorization", "Bearer " + token);
        }
        if (xToken != null && !xToken.isEmpty()) {
            builder.header("x-token", xToken);
        }
        if (runtimeState != null && !runtimeState.isBlank()) {
            builder.header("x-sec-runtime-state", runtimeState);
        }

        try (Response response = okHttpClient.newCall(builder.build()).execute()) {
            String responseBody = response.body() != null ? response.body().string() : "";
            return new RawResponse(response.code(), responseBody);
        }
    }

    public void close() {
        okHttpClient.dispatcher().executorService().shutdownNow();
        okHttpClient.connectionPool().evictAll();
    }

    public String getPhone() {
        return phone;
    }

    public record RawResponse(int statusCode, String body) {
    }

    public record RedirectResponse(int statusCode, String location, String body) {
    }

    public static class HttpStatusException extends IOException {
        private final int statusCode;
        private final String body;

        public HttpStatusException(int statusCode, String body, String message) {
            super(message);
            this.statusCode = statusCode;
            this.body = body;
        }

        public int getStatusCode() {
            return statusCode;
        }

        public String getBody() {
            return body;
        }
    }
}
