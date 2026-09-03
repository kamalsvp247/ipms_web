package com.ivac.booking;

import com.ivac.booking.config.ConfigLoader;
import com.ivac.booking.config.ConfigUrlResolver;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.config.ProtocolConstantsRefresher;
import com.ivac.booking.networking.Ipv6SourcePool;
import com.ivac.booking.networking.PortalLogShipper;
import com.ivac.booking.service.captcha.PortalCaptchaClient;
import com.ivac.booking.util.EnvLoader;
import com.ivac.booking.util.ProcessManager;
import com.ivac.booking.util.PublicIpResolver;
import com.ivac.booking.util.StartupLogger;
import com.ivac.booking.util.TimeSync;
import com.ivac.booking.worker.AccountWorker;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.time.format.DateTimeFormatter;
import java.time.ZoneId;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Objects;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

public class AppStartup {

    private static final Logger log = LoggerFactory.getLogger(AppStartup.class);

    // Shared reference to the active booking executor so the shutdown hook can stop it.
    private static final AtomicReference<ExecutorService> activeExecutor = new AtomicReference<>(null);

    // Portal client for distributed mode — used by runBookingCycle() to store JWTs.
    private static volatile PortalClient sharedPortalClient = null;

    public static void run(String[] args) throws IOException {

        ProcessManager.killPreviousInstances();
        log.info("BLITZ Booking Automation starting...");

        String slotApiKey = (args != null && args.length > 0 && !args[0].isBlank()) ? args[0].trim() : EnvLoader.get("SLOT_API_KEY");

        if (slotApiKey != null && !slotApiKey.isBlank()) {
            System.setProperty("slot.api.key", slotApiKey);

            String portalUrl = Constants.PORTAL_URL;

            PortalLogShipper.init(portalUrl, slotApiKey);

            // Distributed VPS mode — wait for "start" command from portal.
            PortalClient portalClient = new PortalClient(portalUrl, slotApiKey);
            sharedPortalClient = portalClient;
            registerShutdownHook();
            runDistributedMode(portalClient);

        } else {
            runBookingCycle();
        }

        log.info("***IVAC Booking Automation finished***");
    }

    // ── Distributed mode ───────────────────────────────────────────────────────

    private static void runDistributedMode(PortalClient portalClient) {
        log.info("[Slot] Distributed mode — waiting for 'start' command from portal...");

        while (!Thread.currentThread().isInterrupted()) {
            try {
                ExecutorService current = activeExecutor.get();
                boolean isRunning = current != null && !current.isTerminated();

                String command = portalClient.heartbeat(isRunning ? "running" : "idle");

                if ("start".equals(command) || "restart".equals(command)) {
                    if (isRunning) {
                        log.info("[Slot] Received '{}' — stopping current booking first...", command);
                        stopCurrentBooking();
                    }

                    log.info("[Slot] Starting booking cycle...");

                    ExecutorService ex = startBookingAsync();
                    activeExecutor.set(ex);

                } else if ("stop".equals(command)) {
                    if (isRunning) {
                        log.info("[Slot] Received 'stop' command.");
                        stopCurrentBooking();
                    } else {
                        log.info("[Slot] Received 'stop' but already idle.");
                    }
                } else if ("process_restart".equals(command)) {
                    log.info("[Slot] Received 'process_restart' — restarting systemd service...");
                    stopCurrentBooking();
                    restartService();
                }

                Thread.sleep(15_000);

            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                break;
            } catch (Exception e) {
                log.warn("[Slot] Command poll error: {}", e.getMessage());
                try {
                    Thread.sleep(15_000);
                } catch (InterruptedException ie) {
                    Thread.currentThread().interrupt();
                    break;
                }
            }
        }

        stopCurrentBooking();
    }

    private static ExecutorService startBookingAsync() {

        ExecutorService executorService = Executors.newSingleThreadExecutor(runnable -> {
            Thread thread = new Thread(runnable, "booking-cycle");
            thread.setDaemon(false);

            return thread;
        });

        executorService.submit(() -> {
            try {
                runBookingCycle();
            } catch (Exception e) {
                log.error("[Slot] Booking cycle error: {}", e.getMessage(), e);
            }
        });

        executorService.shutdown();

        return executorService;
    }

    private static void stopCurrentBooking() {
        ExecutorService ex = activeExecutor.getAndSet(null);

        if (ex == null) {
            return;
        }

        ex.shutdownNow();

        try {
            if (!ex.awaitTermination(10, TimeUnit.SECONDS)) {
                log.warn("[Slot] Booking workers did not terminate in 10s");
            }
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }

        log.info("[Slot] Booking stopped. Back to idle.");
    }

    private static void runBookingCycle() {

        log.info("Running booking cycle");

        AppConfig config = ConfigLoader.load();

        syncClock(Objects.requireNonNull(config), ConfigUrlResolver.resolve());

        // Let the shared config adopt an IVAC endpoint rotation that the portal only extracts after the
        // window opens (the JS bundle is hidden behind a notice page until then). Constants only —
        // accounts, window times and tick schedules still need a restart.
        ProtocolConstantsRefresher.getInstance().bind(config);

        // Detect and log usable IPv6 egress addresses once, before any account client is built.
        // Detection is lazy-safe, so the distributed path is also covered. When no IPv6 exists,
        // resolve the public IPv4 up front so IPv4 workers ship their real identity to the portal.
        if (Ipv6SourcePool.getInstance().isEnabled()) {
            log.info("[IPv6] Per-account IPv6 source binding active for this worker.");
        } else {
            PublicIpResolver.getPublicIpv4();
        }

        List<AccountConfig> validAccounts = filterValidAccounts(config.getAccounts());

        if (validAccounts.isEmpty()) {
            log.error("No valid accounts found. Check phone and password in BLITZ");
            return;
        }

        StartupLogger.logConfigSummary(config, validAccounts);

        PortalCaptchaClient portalCaptcha = new PortalCaptchaClient(Constants.PORTAL_URL);

        log.info("Total accounts for this worker is : {}", validAccounts.size());

        ExecutorService executor = Executors.newFixedThreadPool(validAccounts.size());
        activeExecutor.set(executor);

        for (AccountConfig validAccount : validAccounts) {
            executor.submit(new AccountWorker(validAccount, config, portalCaptcha, sharedPortalClient));
        }

        executor.shutdown();

        try {
            executor.awaitTermination(Long.MAX_VALUE, TimeUnit.MILLISECONDS);
        } catch (InterruptedException e) {
            log.info("Booking cycle interrupted. Attempting to stop workers...");

            executor.shutdownNow();
            Thread.currentThread().interrupt();
        } finally {
            activeExecutor.set(null);
        }
    }

    private static void syncClock(AppConfig config, String configUrl) {
        String ipmsWebBaseUrl = config.getIpmsWebBaseUrl();

        if (ipmsWebBaseUrl == null || ipmsWebBaseUrl.isBlank()) {
            ipmsWebBaseUrl = configUrl.replaceAll("/api/config.*$", "");
        }

        TimeSync.sync(ipmsWebBaseUrl);

        String dhakaTime = TimeSync.now(ZoneId.of("Asia/Dhaka")).format(DateTimeFormatter.ofPattern("HH:mm:ss"));

        log.info("[TimeSync] Dhaka-Bangladesh time: {}", dhakaTime);
    }

    private static List<AccountConfig> filterValidAccounts(List<AccountConfig> allAccounts) {

        if (allAccounts == null || allAccounts.isEmpty()) {
            log.error("No accounts configured in remote config.");
            return List.of();
        }

        List<AccountConfig> valid = allAccounts.stream()
            .filter(a -> a.getPhone() != null && !a.getPhone().isBlank())
            .filter(a -> a.getPassword() != null && !a.getPassword().isBlank())
            .toList();

        if (valid.size() != allAccounts.size()) {
            log.warn("Filtered out {} invalid account(s)", allAccounts.size() - valid.size());
        }

        Set<String> seen = new LinkedHashSet<>();
        List<AccountConfig> deduped = valid.stream()
            .filter(a -> seen.add(a.getPhone()))
            .toList();

        if (deduped.size() != valid.size()) {
            log.warn("Removed {} duplicate account(s) by phone", valid.size() - deduped.size());
        }

        return deduped;
    }

    private static void restartService() {
        try {
            log.info("[Slot] Executing: systemctl restart ipms-bot");
            new ProcessBuilder("systemctl", "restart", "ipms-bot")
                .inheritIO()
                .start();
            // systemd will kill and restart this process; exit cleanly to allow it
            System.exit(0);
        } catch (IOException e) {
            log.warn("[Slot] Failed to restart systemd service, falling back to System.exit(0): {}", e.getMessage());
            System.exit(0);
        }
    }

    private static void registerShutdownHook() {
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            log.info("Shutdown signal received.");
            stopCurrentBooking();
            log.info("Shutdown complete.");
        }, "shutdown-hook"));
    }
}
