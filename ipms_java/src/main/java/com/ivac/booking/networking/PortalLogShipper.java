package com.ivac.booking.networking;

import com.google.gson.Gson;

import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import org.jetbrains.annotations.NotNull;

import java.io.IOException;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * Async batch shipper — sends bot log entries to the portal API without blocking bot threads.
 * Only active in distributed VPS mode (slot.api.key set). Call init() once at startup.
 * In local mode (no API key), all enqueue calls are silent no-ops.
 */
public final class PortalLogShipper {

    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private static final DateTimeFormatter TIMESTAMP_FORMAT = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss.SSS");


    private static final int BATCH_SIZE = 250;
    private static final int FLUSH_INTERVAL_MS = 3_000;
    private static final int QUEUE_CAPACITY = 50_000;

    // Batches shipped per flush cycle before yielding back to the sleep. Bounds how long the
    // flush thread can stay inside one cycle when producers outrun the portal, so shutdown
    // interrupts are still observed promptly.
    private static final int MAX_BATCHES_PER_CYCLE = 40;

    private record LogRecord(
        String accountPhone,
        String label,
        String method,
        String url,
        Integer statusCode,
        Long durationMs,
        String protocolVersion,
        String requestBody,
        String responseBody,
        String errorType,
        String errorMessage,
        String remoteHost,
        String loggedAt) {
    }

    private static volatile String baseUrl;
    private static volatile String apiKey;

    private static volatile OkHttpClient httpClient;
    private static final Gson GSON = new Gson();

    private static final LinkedBlockingQueue<LogRecord> QUEUE = new LinkedBlockingQueue<>(QUEUE_CAPACITY);
    private static final AtomicBoolean INITIALIZED = new AtomicBoolean(false);

    // Per-phone mute for OTP verify shipping. After OTP is verified for a cycle, in-flight
    // verify shots return 400 "OTP expired" — those logs are noise. Mute is cleared at the
    // start of the next cycle (sendOtp / sign-in path begins fresh).
    private static final Map<String, AtomicBoolean> OTP_VERIFY_MUTED = new ConcurrentHashMap<>();

    public static void muteOtpVerifyLogs(String phone) {
        if (phone == null) return;
        OTP_VERIFY_MUTED.computeIfAbsent(phone, k -> new AtomicBoolean(false)).set(true);
    }

    public static void unmuteOtpVerifyLogs(String phone) {
        if (phone == null) return;
        OTP_VERIFY_MUTED.computeIfAbsent(phone, k -> new AtomicBoolean(false)).set(false);
    }

    private static boolean isOtpVerifyMuted(String phone) {
        if (phone == null) return false;
        AtomicBoolean flag = OTP_VERIFY_MUTED.get(phone);
        return flag != null && flag.get();
    }

    static {
        Thread worker = new Thread(PortalLogShipper::flushLoop, "portal-log-shipper");
        worker.setDaemon(true);
        worker.start();

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            if (INITIALIZED.get()) {
                flushAll();
            }
        }, "portal-log-shipper-shutdown"));
    }

    private PortalLogShipper() {
    }

    /**
     * Call once at startup when slot.api.key is available.
     */
    public static void init(String portalBaseUrl, String slotApiKey) {
        baseUrl = portalBaseUrl.replaceAll("/+$", "");
        apiKey = slotApiKey;

        httpClient = new OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build();

        INITIALIZED.set(true);
    }

    /**
     * Enqueue an HTTP request/response log entry (from ApiLogInterceptor). Non-blocking.
     */
    public static void enqueueApi(String phone, String label, String method, String url,
                                  Integer statusCode, Long durationMs, String protocolVersion,
                                  String requestBody, String responseBody,
                                  String errorType, String errorMessage, String remoteHost) {
        if (!INITIALIZED.get()) return;

        // Drop OTP verify HTTP logs after the cycle's OTP has been verified — the in-flight
        // shots that return 400 "OTP expired" are pure noise.
        if (isOtpVerifyMuted(phone) && url != null && url.contains("/otp/verifySigninOtp")) {
            return;
        }

        String dhakaTimeNow = LocalDateTime.now(ZoneId.of("Asia/Dhaka")).format(TIMESTAMP_FORMAT);

        QUEUE.offer(new LogRecord(phone, label, method, url, statusCode, durationMs, protocolVersion,
            requestBody, responseBody, errorType, errorMessage, remoteHost, dhakaTimeNow));
    }

    /**
     * Enqueue a console log entry from ConsoleLogger. Uses method='LOG', url=message.
     */
    public static void enqueueConsole(String phone, String statusLabel, String message) {
        if (!INITIALIZED.get()) return;

        // Suppress OTP-verify-related console noise once verified for this cycle.
        if (isOtpVerifyMuted(phone) && message != null
                && (message.contains("OTP verify") || message.contains("OTP code mismatch"))) {
            return;
        }

        String ts = LocalDateTime.now(ZoneId.of("Asia/Dhaka")).format(TIMESTAMP_FORMAT);

        QUEUE.offer(new LogRecord(phone, statusLabel, "LOG", message, null, null, null,
            null, null, null, null, null, ts));
    }

    private static void flushLoop() {

        while (!Thread.currentThread().isInterrupted()) {
            try {
                Thread.sleep(FLUSH_INTERVAL_MS);

                // Drain in a loop rather than shipping a single batch per cycle. One batch per
                // FLUSH_INTERVAL_MS caps throughput at BATCH_SIZE/interval regardless of load,
                // so a handful of accounts ticking at 10 shots/s outruns the shipper and the
                // queue silently drops on offer(). Looping lets the shipper keep pace.
                int batches = 0;

                while (INITIALIZED.get() && !QUEUE.isEmpty() && batches < MAX_BATCHES_PER_CYCLE) {
                    flush();
                    batches++;
                }
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        }
    }

    private static void flush() {
        List<LogRecord> batch = new ArrayList<>(BATCH_SIZE);

        QUEUE.drainTo(batch, BATCH_SIZE);
        if (batch.isEmpty()) return;

        try {
            List<Map<String, Object>> logsList = getMapList(batch);

            String json = GSON.toJson(Map.of("logs", logsList));
            Request request = new Request.Builder()
                .url(baseUrl + "/api/slots/logs")
                .addHeader("Authorization", "Bearer " + apiKey)
                .post(RequestBody.create(json, JSON))
                .build();

            try (Response response = httpClient.newCall(request).execute()) {
                if (!response.isSuccessful()) {
                    System.err.println("[PortalLogShipper] HTTP " + response.code() + " — " + batch.size() + " entries dropped");
                }
            }
        } catch (IOException e) {
            System.err.println("[PortalLogShipper] Ship failed: " + e.getMessage() + " (" + batch.size() + " entries dropped)");
        } catch (Exception e) {
            System.err.println("[PortalLogShipper] Unexpected error: " + e.getMessage());
        }
    }

    /** Drains and ships the entire queue on shutdown — loops in batches until empty. */
    private static void flushAll() {
        List<LogRecord> batch = new ArrayList<>(BATCH_SIZE);
        while (!QUEUE.isEmpty()) {
            batch.clear();
            QUEUE.drainTo(batch, BATCH_SIZE);
            if (batch.isEmpty()) {
                break;
            }
            try {
                String json = GSON.toJson(Map.of("logs", getMapList(batch)));
                Request request = new Request.Builder()
                    .url(baseUrl + "/api/slots/logs")
                    .addHeader("Authorization", "Bearer " + apiKey)
                    .post(RequestBody.create(json, JSON))
                    .build();
                try (Response response = httpClient.newCall(request).execute()) {
                    if (!response.isSuccessful()) {
                        System.err.println("[PortalLogShipper] Shutdown flush HTTP " + response.code()
                            + " — " + batch.size() + " entries dropped");
                        break;
                    }
                }
            } catch (Exception e) {
                System.err.println("[PortalLogShipper] Shutdown flush failed: " + e.getMessage()
                    + " (" + batch.size() + " entries dropped)");
                break;
            }
        }
    }

    @NotNull
    private static List<Map<String, Object>> getMapList(List<LogRecord> logRecords) {

        List<Map<String, Object>> logsList = new ArrayList<>(logRecords.size());

        for (LogRecord logRecord : logRecords) {

            Map<String, Object> logRecordMap = new HashMap<>();
            logRecordMap.put("account_phone", logRecord.accountPhone());
            logRecordMap.put("label", logRecord.label());
            logRecordMap.put("method", logRecord.method());
            logRecordMap.put("url", logRecord.url());

            if (logRecord.statusCode() != null) logRecordMap.put("status_code", logRecord.statusCode());
            if (logRecord.durationMs() != null) logRecordMap.put("duration_ms", logRecord.durationMs());
            if (logRecord.protocolVersion() != null) logRecordMap.put("protocol_version", logRecord.protocolVersion());
            if (logRecord.requestBody() != null) logRecordMap.put("request_body", logRecord.requestBody());
            if (logRecord.responseBody() != null) logRecordMap.put("response_body", logRecord.responseBody());
            if (logRecord.errorType() != null) logRecordMap.put("error_type", logRecord.errorType());
            if (logRecord.errorMessage() != null) logRecordMap.put("error_message", logRecord.errorMessage());
            if (logRecord.remoteHost() != null) logRecordMap.put("remote_ip", logRecord.remoteHost());

            logRecordMap.put("logged_at", logRecord.loggedAt());
            logsList.add(logRecordMap);
        }

        return logsList;
    }
}
