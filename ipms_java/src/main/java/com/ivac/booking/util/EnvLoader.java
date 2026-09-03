package com.ivac.booking.util;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

public final class EnvLoader {

    private static final Logger log = LoggerFactory.getLogger(EnvLoader.class);

    private static Map<String, String> envMap;
    private static boolean loaded;

    private EnvLoader() {
    }

    public static String get(String key) {
        if (!loaded) {
            load();
        }

        return envMap == null ? null : envMap.get(key);
    }

    private static synchronized void load() {

        if (loaded) {
            return;
        }

        loaded = true;

        Path path = resolveEnvPath();

        if (path == null || !Files.isReadable(path)) {
            envMap = Collections.emptyMap();
            return;
        }

        try {
            envMap = new LinkedHashMap<>();

            for (String line : Files.readAllLines(path)) {
                line = line.trim();

                if (line.isEmpty() || line.startsWith("#")) {
                    continue;
                }

                int eq = line.indexOf('=');

                if (eq <= 0) {
                    continue;
                }

                String key = line.substring(0, eq).trim();
                String value = line.substring(eq + 1).trim();

                if (value.startsWith("\"") && value.endsWith("\"") && value.length() >= 2) {
                    value = value.substring(1, value.length() - 1).replace("\\\"", "\"");
                } else if (value.startsWith("'") && value.endsWith("'") && value.length() >= 2) {
                    value = value.substring(1, value.length() - 1);
                }

                envMap.put(key, value);
            }

            log.debug("Loaded {} keys from {}", envMap.size(), path);
        } catch (IOException e) {
            log.warn("Could not read .env from {}: {}", path, e.getMessage());
            envMap = Collections.emptyMap();
        }
    }

    private static Path resolveEnvPath() {

        String fromEnv = System.getenv("ENV_FILE");

        if (fromEnv != null && !fromEnv.isBlank()) {
            Path p = Path.of(fromEnv.trim());
            if (Files.exists(p)) {
                return p;
            }
        }

        String userDir = System.getProperty("user.dir");

        if (userDir == null || userDir.isBlank()) {
            return null;
        }

        Path cwd = Path.of(userDir);
        Path parentEnv = cwd.resolve("../.env").normalize();

        if (Files.exists(parentEnv)) {
            return parentEnv;
        }

        Path sameDir = cwd.resolve(".env");

        if (Files.exists(sameDir)) {
            return sameDir;
        }

        return null;
    }
}
