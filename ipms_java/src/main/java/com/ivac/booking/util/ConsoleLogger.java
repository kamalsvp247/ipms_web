package com.ivac.booking.util;

import com.ivac.booking.networking.PortalLogShipper;

import java.io.BufferedWriter;
import java.io.File;
import java.io.FileWriter;
import java.io.IOException;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.Map;

public final class ConsoleLogger {

    private static final DateTimeFormatter DATE_TIME_FORMAT = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss");
    private static final DateTimeFormatter DATE_FORMAT      = DateTimeFormatter.ofPattern("yyyy-MM-dd");
    private static final ZoneId           DHAKA_ZONE        = ZoneId.of("Asia/Dhaka");
    private static final Object           LOCK              = new Object();

    private static String       currentLogDate = null;
    private static BufferedWriter logWriter     = null;

    private static final Map<String, String> STATUS_LABELS = Map.of(
        "OK",      "SUCCESS",
        "WAIT",    "IN_PROGRESS",
        "DONE",    "DONE",
        "FAIL",    "FAIL",
        "RETRY",   "RETRY",
        "AUTH",    "AUTH",
        "POLLING", "POLLING",
        "WARN",    "WARN"
    );

    static {
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            synchronized (LOCK) {
                if (logWriter != null) {
                    try { logWriter.close(); } catch (IOException ignored) {}
                    logWriter = null;
                }
            }
        }, "console-logger-shutdown"));
    }

    private ConsoleLogger() {
    }

    /**
     * Writes a line to the current date-based bot log file (logs/bot-YYYY-MM-DD.log).
     * Rotates the file automatically at midnight (Dhaka time).
     * Caller must hold LOCK.
     */
    private static void writeToFile(String line) {
        try {
            String today = LocalDate.now(DHAKA_ZONE).format(DATE_FORMAT);
            if (!today.equals(currentLogDate) || logWriter == null) {
                if (logWriter != null) {
                    try { logWriter.close(); } catch (IOException ignored) {}
                }
                new File("logs").mkdirs();
                logWriter = new BufferedWriter(new FileWriter("logs/bot-" + today + ".log", true));
                currentLogDate = today;
            }
            logWriter.write(line);
            logWriter.newLine();
            logWriter.flush();
        } catch (IOException e) {
            System.err.println("Failed to write to bot log file: " + e.getMessage());
        }
    }

    /**
     * Log a line in human-readable format: [STATUS]  date time  [phone]  message
     */
    public static void log(String phone, String message, String status) {
        String statusLabel = STATUS_LABELS.getOrDefault(status, status);
        String time = LocalDateTime.now(DHAKA_ZONE).format(DATE_TIME_FORMAT);
        String line = "[" + statusLabel + "]  " + time + "  [" + phone + "]  " + message;

        synchronized (LOCK) {
            System.out.println(line);
            writeToFile(line);
        }

        // Ship to portal DB for the web log viewer (async, non-blocking).
        PortalLogShipper.enqueueConsole(phone, statusLabel, message);
    }

    public static void retry(String phone, String reason, long delayMs) {
        log(phone, reason + " — retrying in " + (delayMs / 1000) + "s", "RETRY");
    }

    public static void wait(String phone, long delayMs) {
        log(phone, "Waiting " + (delayMs / 1000) + "s", "WAIT");
    }
}
