package com.ivac.booking.networking;

import java.io.BufferedWriter;
import java.io.FileWriter;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.LinkedBlockingQueue;

public final class ApiLogger {

    private static final String LOG_DIR = "logs";
    private static final DateTimeFormatter TIMESTAMP_FORMAT = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss.SSS");
    private static final DateTimeFormatter DATE_FORMAT = DateTimeFormatter.ofPattern("yyyy-MM-dd");

    private record LogEntry(String logFile, String content) {}

    private static final LogEntry POISON = new LogEntry("", "");
    private static final LinkedBlockingQueue<LogEntry> QUEUE = new LinkedBlockingQueue<>();
    private static final Map<String, BufferedWriter> WRITERS = new HashMap<>();

    static {
        Thread worker = new Thread(ApiLogger::drainLoop);
        worker.setDaemon(true);
        worker.setName("api-logger");
        worker.start();

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            QUEUE.offer(POISON);
            try {
                worker.join(3_000);
            } catch (InterruptedException ignored) {
            }
        }));
    }

    private ApiLogger() {
    }

    public static void log(String phone, String label, String method, String url,
            String requestHeaders, String requestBody,
            int statusCode, String responseHeaders, String responseBody,
            long durationMs, String protocolVersion, String remoteHost) {
        LocalDateTime now = LocalDateTime.now();
        LocalDateTime responseTime = now.minusNanos(durationMs * 1_000_000);

        StringBuilder sb = new StringBuilder();
        sb.append("[").append(responseTime.format(TIMESTAMP_FORMAT)).append("] ");
        if (label != null && !label.isBlank()) {
            sb.append("[").append(label).append("] ");
        }
        sb.append(method).append(" ").append(url);
        sb.append(" -> [").append(now.format(TIMESTAMP_FORMAT)).append("] ");
        sb.append(statusCode).append(" (").append(durationMs).append("ms)");
        if (protocolVersion != null && !protocolVersion.isBlank()) {
            sb.append(" [").append(protocolVersion).append("]");
        }

        if (requestBody != null && !requestBody.isEmpty()) {
            sb.append("\n  REQ: ").append(collapseWhitespace(requestBody));
        }
        boolean suppressHtmlBody = shouldSuppressHtmlBodyForSignin(statusCode, url, responseHeaders);
        if (responseBody != null && !responseBody.isEmpty() && !suppressHtmlBody) {
            sb.append("\n  RES: ").append(collapseWhitespace(responseBody));
        } else if (suppressHtmlBody) {
            sb.append("\n  RES: [HTML body suppressed for signin ").append(statusCode).append("]");
        }
        sb.append("\n\n");

        // Local file logging disabled — portal DB shipping is the primary sink.
        // enqueue(phone, sb.toString());

        // Ship structured entry to portal DB (async, non-blocking).
        String portalResponseBody = suppressHtmlBody ? null : responseBody;
        if (portalResponseBody != null && isHtmlBody(portalResponseBody)) {
            portalResponseBody = "html";
        }
        PortalLogShipper.enqueueApi(
                phone, label, method, url,
                statusCode, durationMs, protocolVersion,
                requestBody, portalResponseBody,
                null, null, remoteHost);
    }

    public static void logError(String phone, String label, String method, String url,
            String requestHeaders, String requestBody,
            String errorMessage, String remoteHost) {
        StringBuilder sb = new StringBuilder();
        sb.append("[").append(LocalDateTime.now().format(TIMESTAMP_FORMAT)).append("] ");
        if (label != null && !label.isBlank()) {
            sb.append("[").append(label).append("] ");
        }
        sb.append(method).append(" ").append(url);
        sb.append(" -> ERROR: ").append(errorMessage);

        if (requestBody != null && !requestBody.isEmpty()) {
            sb.append("\n  REQ: ").append(collapseWhitespace(requestBody));
        }
        sb.append("\n\n");

        // Local file logging disabled — portal DB shipping is the primary sink.
        // enqueue(phone, sb.toString());

        // Split "ExceptionType: message" for structured DB storage.
        String errorType = null;
        String errMsg = errorMessage;
        int colonIdx = errorMessage != null ? errorMessage.indexOf(": ") : -1;
        if (colonIdx > 0) {
            errorType = errorMessage.substring(0, colonIdx);
            errMsg = errorMessage.substring(colonIdx + 2);
        }
        PortalLogShipper.enqueueApi(
                phone, label, method, url,
                null, null, null,
                requestBody, null,
                errorType, errMsg, remoteHost);
    }

    private static void enqueue(String phone, String content) {
        String logFile = LOG_DIR + "/" + phone + "-api-debug-" + LocalDate.now().format(DATE_FORMAT) + ".log";
        QUEUE.offer(new LogEntry(logFile, content));
    }

    private static void drainLoop() {
        while (true) {
            try {
                LogEntry entry = QUEUE.take();
                if (entry == POISON) {
                    LogEntry remaining;
                    while ((remaining = QUEUE.poll()) != null && remaining != POISON) {
                        writeEntry(remaining);
                    }
                    break;
                }
                writeEntry(entry);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                break;
            }
        }
        closeAll();
    }

    private static void writeEntry(LogEntry entry) {
        try {
            Files.createDirectories(Paths.get(LOG_DIR));
            BufferedWriter writer = WRITERS.get(entry.logFile());
            if (writer == null) {
                writer = new BufferedWriter(new FileWriter(entry.logFile(), true));
                WRITERS.put(entry.logFile(), writer);
            }
            writer.write(entry.content());
            writer.flush();
        } catch (IOException e) {
            System.err.println("Failed to write API debug log: " + e.getMessage());
        }
    }

    private static void closeAll() {
        for (BufferedWriter writer : WRITERS.values()) {
            try {
                writer.close();
            } catch (IOException ignored) {
            }
        }
        WRITERS.clear();
    }

    private static String collapseWhitespace(String text) {
        return text.replaceAll("\\s+", " ");
    }

    private static boolean isHtmlBody(String body) {
        if (body == null) {
            return false;
        }
        String trimmed = body.stripLeading();
        if (trimmed.length() < 5) {
            return false;
        }
        String head = trimmed.substring(0, 5).toLowerCase();
        return head.equals("<html") || trimmed.toLowerCase().startsWith("<!doctype html");
    }

    private static boolean shouldSuppressHtmlBodyForSignin(int statusCode, String url, String responseHeaders) {
        if (statusCode != 429 && statusCode != 503) {
            return false;
        }
        if (url == null) {
            return false;
        }
        if (!url.toLowerCase().contains("/auth/signin")) {
            return false;
        }
        if (responseHeaders == null) {
            return false;
        }
        return responseHeaders.toLowerCase().contains("content-type: text/html");
    }
}
