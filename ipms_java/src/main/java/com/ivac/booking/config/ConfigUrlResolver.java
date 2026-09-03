package com.ivac.booking.config;

import com.ivac.booking.Constants;
import com.ivac.booking.util.EnvLoader;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

public class ConfigUrlResolver {

    public static String resolve() {

        String slotApiKey = System.getProperty("slot.api.key");
        String appUrl = EnvLoader.get("APP_URL");
        String gmail = EnvLoader.get("BOT_CONFIG_GMAIL");

        if (slotApiKey != null && !slotApiKey.isBlank()) {
            return Constants.PORTAL_URL + "/api/config";
        }

        if (appUrl != null && !appUrl.isBlank() && gmail != null && !gmail.isBlank()) {
            String base = appUrl.trim().replaceAll("/+$", "");
            return base + "/api/config?gmail=" + URLEncoder.encode(gmail.trim(), StandardCharsets.UTF_8);
        }

        return null;
    }
}
