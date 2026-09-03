package com.ivac.booking;

import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.Executors;

/**
 * HTTP client for portal slot API (heartbeat, command polling).
 * Used only in distributed VPS mode (SLOT_API_KEY present in .env).
 */
public class PortalClient {

    private static final Logger log = LoggerFactory.getLogger(PortalClient.class);
    private static final Gson GSON = new Gson();

    private final String baseUrl;
    private final String apiKey;
    private final HttpClient http;

    public PortalClient(String baseUrl, String apiKey) {
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.apiKey = apiKey;
        this.http = HttpClient.newBuilder()
                .connectTimeout(Duration.ofSeconds(10))
                .version(HttpClient.Version.HTTP_2)
                .followRedirects(HttpClient.Redirect.NEVER)
                .executor(Executors.newVirtualThreadPerTaskExecutor())
                .build();
    }

    /**
     * POST /api/account-sessions — stores JWT token, timestamps, and signin request ID in portal after sign-in.
     * Async fire-and-forget; errors are logged but not rethrown.
     *
     * @param signinRequestId requestId from IVAC sign-in response (may be null)
     * @param serverTimeMs    IVAC server timestamp at sign-in (epoch ms; 0 to omit)
     */
    public void storeJwt(String phone, String token, long generatedAtMs, long expiresAtMs,
                         String signinRequestId, long serverTimeMs) {
        Map<String, Object> payload = new HashMap<>();
        payload.put("phone", phone);
        payload.put("jwt_token", token);
        payload.put("jwt_generated_at_ms", generatedAtMs);
        payload.put("jwt_expires_at_ms", expiresAtMs);
        if (signinRequestId != null) {
            payload.put("request_id", signinRequestId);
        }
        if (serverTimeMs > 0) {
            payload.put("signed_in_server_time", Instant.ofEpochMilli(serverTimeMs).toString());
        }

        String body = GSON.toJson(payload);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/account-sessions"))
                .header("Authorization", "Bearer " + apiKey)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .timeout(Duration.ofSeconds(15))
                .build();

        http.sendAsync(request, HttpResponse.BodyHandlers.discarding())
                .exceptionally(e -> {
                    log.warn("[PortalClient] storeJwt failed for {}: {}", phone, e.getMessage());
                    return null;
                });
    }

    /**
     * POST /api/account-sessions — records that the current JWT has been OTP-verified.
     * Async fire-and-forget; errors are logged but not rethrown.
     */
    public void storeOtpVerified(String phone, String otpCode, String serverTime) {
        Map<String, Object> payload = new HashMap<>();
        payload.put("phone", phone);
        payload.put("otp_code", otpCode);
        payload.put("otp_verified_server_time", serverTime);

        String body = GSON.toJson(payload);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/account-sessions"))
                .header("Authorization", "Bearer " + apiKey)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .timeout(Duration.ofSeconds(15))
                .build();

        http.sendAsync(request, HttpResponse.BodyHandlers.discarding())
                .exceptionally(e -> {
                    log.warn("[PortalClient] storeOtpVerified failed for {}: {}", phone, e.getMessage());
                    return null;
                });
    }

    /**
     * POST /api/slots/heartbeat — marks this slot online and reports worker state.
     * Returns the pending_command string (e.g. "start", "stop", "restart"), or null if none.
     */
    public String heartbeat(String workerState) throws IOException {
        String body = GSON.toJson(Map.of("worker_state", workerState, "bot_version", BotVersion.VERSION));
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/slots/heartbeat"))
                .header("Authorization", "Bearer " + apiKey)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .timeout(Duration.ofSeconds(15))
                .build();

        try {
            log.info("→ Sending heartbeat to portal (state: {})", workerState);
            HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
            if (response.statusCode() < 200 || response.statusCode() >= 300) {
                log.warn("[Heartbeat] HTTP {}", response.statusCode());
                return null;
            }
            JsonObject json = GSON.fromJson(response.body(), JsonObject.class);
            JsonElement cmd = json.get("pending_command");
            return (cmd == null || cmd.isJsonNull()) ? null : cmd.getAsString();
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            log.warn("[Heartbeat] Request interrupted: {}", e.getMessage());
            return null;
        }
    }
}
