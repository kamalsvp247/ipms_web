package com.ivac.booking.util;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import okhttp3.MediaType;
import okhttp3.Request;
import okhttp3.RequestBody;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;

public class HttpUtil {
    private static final Logger log = LoggerFactory.getLogger(HttpUtil.class);
    private static final Gson gson = new Gson();
    private static final MediaType JSON_MEDIA_TYPE = MediaType.get("application/json; charset=utf-8");

    /**
     * Parses the retry-after duration from a 429 response body.
     * Matches "retry after N second(s)" (case-insensitive). Returns 0 if not found.
     */
    public static long parseRetryAfterSeconds(String body) {
        if (body == null || body.isEmpty()) {
            return 0;
        }
        java.util.regex.Matcher m = java.util.regex.Pattern
            .compile("retry after (\\d+) second", java.util.regex.Pattern.CASE_INSENSITIVE)
            .matcher(body);
        return m.find() ? Long.parseLong(m.group(1)) : 0;
    }

    public static String serializeBody(Object obj) {
        return obj != null ? gson.toJson(obj) : "{}";
    }

    public static String serializeToJson(Object obj) {
        return serializeBody(obj);
    }

    public static RequestBody createJsonBody(Object obj) {
        String json = serializeToJson(obj);
        return RequestBody.create(json, JSON_MEDIA_TYPE);
    }

    public static <T> T parseJson(String json, Class<T> type) {
        return gson.fromJson(json, type);
    }

    public static JsonObject parseJsonObject(String json) {
        return gson.fromJson(json, JsonObject.class);
    }

    // Check successFlag field in response body
    public static void validateSuccessFlag(String responseJson, String endpoint) throws IOException {
        if (responseJson == null || responseJson.isEmpty()) {
            return;
        }
        try {
            JsonObject obj = parseJsonObject(responseJson);
            if (obj != null && obj.has("successFlag") && !obj.get("successFlag").getAsBoolean()) {
                String msg = obj.has("message") ? obj.get("message").getAsString() : "unknown";
                throw new IOException(endpoint + " -> successFlag=false: " + msg);
            }
        } catch (IOException e) {
            throw e;
        } catch (Exception e) {
            log.debug("Could not parse successFlag from {}: {}", endpoint, e.getMessage());
        }
    }

    public static class RawResponse {
        public final int statusCode;
        public final String body;

        public RawResponse(int statusCode, String body) {
            this.statusCode = statusCode;
            this.body = body;
        }

        public boolean isSuccessful() {
            return statusCode >= 200 && statusCode < 300;
        }

        public boolean isUnauthorized() {
            return statusCode == 401;
        }

        public JsonObject parseBody() {
            return parseJsonObject(body);
        }
    }

    // Helper: build POST request with auth token
    public static Request buildAuthenticatedPostRequest(String url, Object body, String accessToken) {
        Request.Builder builder = new Request.Builder()
                .url(url)
                .post(RequestBody.create(serializeToJson(body), JSON_MEDIA_TYPE))
                .header("Content-Type", "application/json");

        if (accessToken != null && !accessToken.isEmpty()) {
            builder.header("Authorization", "Bearer " + accessToken);
        }

        return builder.build();
    }

    // Helper: build GET request
    public static Request buildGetRequest(String url) {
        return new Request.Builder()
                .url(url)
                .get()
                .build();
    }

    // Helper: build POST request without auth
    public static Request buildUnauthenticatedPostRequest(String url, Object body) {
        return new Request.Builder()
                .url(url)
                .post(RequestBody.create(serializeToJson(body), JSON_MEDIA_TYPE))
                .header("Content-Type", "application/json")
                .build();
    }
}
