package com.ivac.booking.util;

import java.io.BufferedWriter;
import java.io.File;
import java.io.FileWriter;
import java.io.IOException;
import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;

public class RateLimitObserver {

    private static final String LOG_FILE = "logs/rate-limit-obs.log";
    private static final DateTimeFormatter FMT = DateTimeFormatter
        .ofPattern("yyyy-MM-dd'T'HH:mm:ss.SSSXXX")
        .withZone(ZoneId.of("Asia/Dhaka"));

    // Singleton
    private static final RateLimitObserver INSTANCE = new RateLimitObserver();

    private BufferedWriter writer;

    private RateLimitObserver() {
        try {
            new File("logs").mkdirs();
            writer = new BufferedWriter(new FileWriter(LOG_FILE, true));
        } catch (IOException e) {
            System.err.println("[RateLimitObserver] Failed to open log: " + e.getMessage());
        }
    }

    public static RateLimitObserver getInstance() {
        return INSTANCE;
    }

    /**
     * Writes a separator at the start of each booking window — makes the log easy to split by run.
     */
    public synchronized void onWindowStart(String windowTime) {
        String timestamp = FMT.format(Instant.now());
        write(String.format("[%s] ===== WINDOW START %s =====", timestamp, windowTime));
    }

    private void write(String line) {
        if (writer == null) {
            return;
        }
        try {
            writer.write(line);
            writer.newLine();
            writer.flush();
        } catch (IOException e) {
            System.err.println("[RateLimitObserver] Write failed: " + e.getMessage());
        }
    }
}
