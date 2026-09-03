package com.ivac.booking.util;

import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.time.temporal.ChronoUnit;
import java.util.concurrent.TimeUnit;

public final class TimeWindowUtil {

    private static final DateTimeFormatter TIME_FORMAT = DateTimeFormatter.ofPattern("H:mm[:ss]");
    private static final ZoneId BDT = ZoneId.of("Asia/Dhaka");

    private TimeWindowUtil() {
    }

    public static long secondsUntilWindowStart(String startTime) {
        LocalTime start = LocalTime.parse(startTime, TIME_FORMAT);
        LocalTime now = TimeSync.now(BDT);

        if (!now.isBefore(start)) {
            return 0;
        }

        return now.until(start, ChronoUnit.SECONDS);
    }

    public static String formatFutureTime(long delayMs) {
        LocalTime now = TimeSync.now(BDT);
        long delaySeconds = TimeUnit.MILLISECONDS.toSeconds(delayMs);
        LocalTime futureTime = now.plusSeconds(delaySeconds);
        DateTimeFormatter hmsFormat = DateTimeFormatter.ofPattern("HH:mm:ss");

        return futureTime.format(hmsFormat);
    }
}
