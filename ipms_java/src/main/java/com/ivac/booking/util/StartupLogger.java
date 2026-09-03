package com.ivac.booking.util;

import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.List;

public final class StartupLogger {

    private static final Logger log = LoggerFactory.getLogger(StartupLogger.class);

    private StartupLogger() {
    }

    public static void logConfigSummary(AppConfig config, List<AccountConfig> validAccounts) {

        int cpus = Runtime.getRuntime().availableProcessors();

        log.info("System threads: {} CPU(s), using {} worker thread(s)", cpus, validAccounts.size());
        log.info("Config: baseUrl={}, maxRetries={}",
            config.getBaseUrl(),
            config.getMaxRetries());
        log.info("Time window: {} - {}", config.getWindowStartTime(), config.getWindowEndTime());
        log.info("Accounts: {} total, {} valid", validAccounts.size(), validAccounts.size());

        for (AccountConfig account : validAccounts) {
            log.info("  Account: {} , signin=API)",
                account.getPhone());
        }
    }
}
