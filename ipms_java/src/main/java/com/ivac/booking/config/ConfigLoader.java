package com.ivac.booking.config;

import com.google.gson.Gson;
import com.ivac.booking.util.EnvLoader;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;

import java.io.IOException;
import java.util.Objects;
import java.util.concurrent.TimeUnit;

public class ConfigLoader {

    private static final int MAX_ATTEMPTS = 3;

    public static AppConfig load() {

        for (int attempt = 1; true; attempt++) {

            try {
                return fetchOnce();
            } catch (Exception exception) {

                if (attempt == MAX_ATTEMPTS) {
                    break;
                }

            }
        }

        return null;
    }

    private static AppConfig fetchOnce() throws IOException {
        OkHttpClient client = new OkHttpClient.Builder()
                .connectTimeout(90, TimeUnit.SECONDS)
                .readTimeout(90, TimeUnit.SECONDS)
                .build();

        try {
            Request.Builder builder = new Request.Builder().url(Objects.requireNonNull(ConfigUrlResolver.resolve())).get();

            String slotApiKey = System.getProperty("slot.api.key");

            if (slotApiKey == null || slotApiKey.isBlank()) {
                slotApiKey = EnvLoader.get("SLOT_API_KEY");
            }

            if (slotApiKey != null && !slotApiKey.isBlank()) {
                builder.addHeader("Authorization", "Bearer " + slotApiKey);
            }

            Request request = builder.build();

            try (Response response = client.newCall(request).execute()) {
                if (!response.isSuccessful()) {
                    throw new IOException("Remote config request failed: HTTP " + response.code());
                }

                ResponseBody body = response.body();
                if (body == null) {
                    throw new IOException("Remote config response body is null");
                }

                String json = body.string();
                return new Gson().fromJson(json, AppConfig.class);
            }

        } finally {
            client.dispatcher().executorService().shutdownNow();
            client.connectionPool().evictAll();
        }
    }
}
