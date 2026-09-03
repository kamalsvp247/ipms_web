package com.ivac.booking.util;

public class TimeUtil {

    // Convert nanoseconds to milliseconds
    public static long nanoToMs(long nanoTime) {
        return nanoTime / 1_000_000;
    }

    // Calculate elapsed milliseconds from start time
    public static long getElapsedMs(long startTimeNano) {
        return nanoToMs(System.nanoTime() - startTimeNano);
    }

    // Safe sleep—handles InterruptedException
    public static void sleepMs(long ms) throws InterruptedException {
        if (ms > 0) {
            Thread.sleep(ms);
        }
    }

    // Return current time as formatted string
    public static String getCurrentTimeMs() {
        return Long.toString(System.currentTimeMillis());
    }

    // Convert epoch milliseconds to seconds
    public static long msToSeconds(long ms) {
        return ms / 1_000;
    }

    // Convert seconds to milliseconds
    public static long secondsToMs(long seconds) {
        return seconds * 1_000;
    }

    // Calculate time remaining (deadline - now)
    public static long getTimeRemainingMs(long deadlineMs) {
        return Math.max(0, deadlineMs - System.currentTimeMillis());
    }

    // Check whether deadline has expired
    public static boolean isExpired(long deadlineMs) {
        return System.currentTimeMillis() >= deadlineMs;
    }

    // Calculate time remaining until window
    public static long getTimeUntilWindowMs(long windowStartMs) {
        if (windowStartMs <= 0) return Long.MAX_VALUE;
        return getTimeRemainingMs(windowStartMs);
    }

    // Check whether window is open
    public static boolean isWindowOpen(long windowStartMs, long windowEndMs) {
        long now = System.currentTimeMillis();
        return now >= windowStartMs && now < windowEndMs;
    }

    // Check whether we are in the final seconds of window
    public static boolean isWindowClosing(long windowEndMs, long secondsBeforeEnd) {
        long now = System.currentTimeMillis();
        long thresholdMs = windowEndMs - (secondsBeforeEnd * 1_000);
        return now >= thresholdMs && now < windowEndMs;
    }

    // Format seconds into human-readable format (e.g., 5910s → "1h 38m 30s")
    public static String formatDuration(long seconds) {
        if (seconds < 0) return "0s";
        if (seconds == 0) return "0s";

        long hours = seconds / 3600;
        long minutes = (seconds % 3600) / 60;
        long secs = seconds % 60;

        StringBuilder sb = new StringBuilder();
        if (hours > 0) {
            sb.append(hours).append("h");
        }
        if (minutes > 0) {
            if (sb.length() > 0) sb.append(" ");
            sb.append(minutes).append("m");
        }
        if (secs > 0 || sb.length() == 0) {
            if (sb.length() > 0) sb.append(" ");
            sb.append(secs).append("s");
        }

        return sb.toString();
    }
}
