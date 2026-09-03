package com.ivac.booking.util;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import java.io.BufferedReader;
import java.io.InputStreamReader;

public class ProcessManager {

    private static final Logger log = LoggerFactory.getLogger(ProcessManager.class);

    public static void killPreviousInstances() {
        long myPid = ProcessHandle.current().pid();

        if (isWindows()) {
            killOnWindows(myPid);
        } else {
            killOnUnix(myPid);
        }
    }

    private static boolean isWindows() {
        return System.getProperty("os.name", "").toLowerCase().contains("win");
    }

    private static void killOnWindows(long myPid) {
        try {
            ProcessHandle.allProcesses()
                    .filter(ph -> ph.pid() != myPid)
                    .filter(ph -> ph.info().commandLine().orElse("").contains("com.ivac.booking.App"))
                    .forEach(ph -> {
                        log.info("Killing previous instance (PID {})", ph.pid());
                        ph.destroyForcibly();
                    });
        } catch (Exception e) {
            log.warn("Could not kill previous instances: {}", e.getMessage());
        }
    }

    private static void killOnUnix(long myPid) {
        try {
            Process proc = new ProcessBuilder("sh", "-c",
                    "ps aux | grep 'com.ivac.booking.App' | grep -v grep | awk '{print $2}'")
                    .redirectErrorStream(true)
                    .start();

            try (BufferedReader reader = new BufferedReader(new InputStreamReader(proc.getInputStream()))) {
                String line;
                while ((line = reader.readLine()) != null) {
                    long pid = Long.parseLong(line.trim());
                    if (pid != myPid) {
                        log.info("Killing previous instance (PID {})", pid);
                        new ProcessBuilder("kill", "-9", String.valueOf(pid)).start().waitFor();
                    }
                }
            }
            proc.waitFor();
        } catch (Exception e) {
            log.warn("Could not kill previous instances: {}", e.getMessage());
        }
    }
}
