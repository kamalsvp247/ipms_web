package com.ivac.booking.util;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.time.Instant;
import java.time.LocalTime;
import java.time.ZoneId;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicLong;

public final class TimeSync {

    private static final Logger log = LoggerFactory.getLogger(TimeSync.class);

    private static final AtomicLong offsetMs = new AtomicLong(0);

    private TimeSync() {
    }

    public static void sync(String apiBaseUrl) {
        if (apiBaseUrl == null || apiBaseUrl.isBlank()) {
            log.warn("[TimeSync] No API base URL provided, using system clock (no offset)");
            offsetMs.set(0);
            return;
        }

        String cleanUrl = apiBaseUrl.trim().replaceAll("/+$", "");
        String serverTimeUrl = cleanUrl + "/api/server-time";

        try {
            OkHttpClient client = new OkHttpClient.Builder()
                .connectTimeout(10, TimeUnit.SECONDS)
                .readTimeout(10, TimeUnit.SECONDS)
                .build();

            try {
                Request request = new Request.Builder()
                    .url(serverTimeUrl)
                    .get()
                    .build();

                try (Response response = client.newCall(request).execute()) {
                    if (!response.isSuccessful()) {
                        log.warn("[TimeSync] Failed to fetch server time: HTTP {}", response.code());
                        offsetMs.set(0);
                        return;
                    }

                    ResponseBody body = response.body();
                    if (body == null) {
                        log.warn("[TimeSync] Server time response body is null");
                        offsetMs.set(0);
                        return;
                    }

                    String json = body.string();
                    JsonElement element = JsonParser.parseString(json);
                    if (!element.isJsonObject()) {
                        log.warn("[TimeSync] Server time response is not a JSON object: {}", json);
                        offsetMs.set(0);
                        return;
                    }

                    JsonObject obj = element.getAsJsonObject();
                    if (!obj.has("serverTimeMs")) {
                        log.warn("[TimeSync] Server time response missing 'serverTimeMs' field");
                        offsetMs.set(0);
                        return;
                    }

                    long serverTimeMs = obj.get("serverTimeMs").getAsLong();
                    long localTimeMs = System.currentTimeMillis();
                    long offset = serverTimeMs - localTimeMs;

                    offsetMs.set(offset);

                    if (Math.abs(offset) > 5000) {
                        log.warn("[TimeSync] Large clock skew detected: {} ms ({}s)",
                            offset, String.format("%.1f", offset / 1000.0));
                    } else {
                        String sign = offset >= 0 ? "+" : "";
                        log.info("[TimeSync] Server time offset: {}{} ms ({}s)",
                            sign, offset, String.format("%.1f", offset / 1000.0));
                    }
                }
            } finally {
                client.dispatcher().executorService().shutdown();
                client.connectionPool().evictAll();
            }
        } catch (IOException e) {
            log.warn("[TimeSync] Failed to sync server time from {}: {}", serverTimeUrl, e.getMessage());
            offsetMs.set(0);
        }
    }

    public static LocalTime now(ZoneId zone) {
        long adjustedTimeMs = System.currentTimeMillis() + offsetMs.get();
        return Instant.ofEpochMilli(adjustedTimeMs).atZone(zone).toLocalTime();
    }
}
