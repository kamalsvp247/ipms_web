package com.ivac.booking.service.setup;

import com.google.gson.Gson;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.ivac.booking.Constants;
import com.ivac.booking.util.ConsoleLogger;
import com.ivac.booking.util.EnvLoader;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.ResponseBody;

import java.io.Closeable;
import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;

/**
 * Slot-authenticated portal client for account setup: fetches the applicant PDFs
 * (kept out of /api/config) and reports the one-time pdf-uploaded / booking-configured
 * flags back so a bot restart does not repeat the work.
 *
 * Auth: Bearer {slot.api.key}. When no slot key is present (local mode) the flag
 * writeback is skipped and PDF fetch returns empty.
 */
public class PortalSetupClient implements Closeable {

    private static final Gson GSON = new Gson();
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    public record PdfDoc(String name, String base64, boolean isPrimary) {
    }

    private final String phone;
    private final String bearerToken;
    private final OkHttpClient httpClient;

    public PortalSetupClient(String phone) {
        this.phone = phone;

        String slotApiKey = System.getProperty("slot.api.key");
        if (slotApiKey == null || slotApiKey.isBlank()) {
            slotApiKey = EnvLoader.get("SLOT_API_KEY");
        }
        this.bearerToken = (slotApiKey != null && !slotApiKey.isBlank()) ? slotApiKey : null;

        this.httpClient = new OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .build();
    }

    public boolean hasAuth() {
        return bearerToken != null;
    }

    /**
     * GET /api/accounts/{id}/pdfs — returns the account's PDFs. Empty list on any failure.
     */
    public List<PdfDoc> fetchPdfs(int accountId) {
        List<PdfDoc> result = new ArrayList<>();
        if (bearerToken == null) {
            return result;
        }

        String url = Constants.PORTAL_URL + "/api/accounts/" + accountId + "/pdfs";
        Request request = new Request.Builder()
                .url(url)
                .header("Authorization", "Bearer " + bearerToken)
                .get()
                .build();

        try (Response response = httpClient.newCall(request).execute()) {
            if (!response.isSuccessful()) {
                ConsoleLogger.log(phone, "Fetch PDFs → HTTP " + response.code(), "WARN");
                return result;
            }
            ResponseBody body = response.body();
            if (body == null) {
                return result;
            }
            result.addAll(parsePdfs(GSON.fromJson(body.string(), JsonObject.class)));
        } catch (IOException e) {
            ConsoleLogger.log(phone, "Fetch PDFs failed: " + e.getMessage(), "WARN");
        }
        return result;
    }

    /**
     * POST /api/accounts/pdf-profile-sync — hands the portal the profile IVAC holds for this account
     * so it can rewrite the primary applicant form to state it, and returns the corrected documents.
     *
     * IVAC will not create the appointment while the uploaded form disagrees with the account, and the
     * account itself must not be touched (its phone is the sign-in identity, its email carries the
     * OTP), so the form is what gets corrected. The response carries the new documents directly, which
     * is what lets the caller re-upload without fetching them again.
     *
     * Returns null when the portal could not produce a corrected document — including the case where
     * the edit ran but a value never landed on the form, which is reported per field.
     */
    public List<PdfDoc> syncPdfToProfile(String accountPhone, JsonObject profile) {
        if (bearerToken == null || profile == null) {
            return null;
        }

        JsonObject payload = new JsonObject();
        payload.addProperty("phone", accountPhone);
        payload.add("profile", profile);

        Request request = new Request.Builder()
                .url(Constants.PORTAL_URL + "/api/accounts/pdf-profile-sync")
                .header("Authorization", "Bearer " + bearerToken)
                .post(RequestBody.create(GSON.toJson(payload), JSON))
                .build();

        try (Response response = httpClient.newCall(request).execute()) {
            ResponseBody body = response.body();
            String raw = body != null ? body.string() : "";
            if (!response.isSuccessful()) {
                // Truncated: a 422 names the fields that would not apply, but a 5xx can be a whole
                // HTML error page and this body reaches the portal log shipper.
                ConsoleLogger.log(phone, "PDF profile sync → HTTP " + response.code() + " "
                        + (raw.length() <= 500 ? raw : raw.substring(0, 500) + "…"), "ERROR");
                return null;
            }
            List<PdfDoc> pdfs = parsePdfs(GSON.fromJson(raw, JsonObject.class));
            return pdfs.isEmpty() ? null : pdfs;
        } catch (Exception e) {
            ConsoleLogger.log(phone, "PDF profile sync failed: " + e.getMessage(), "ERROR");
            return null;
        }
    }

    /**
     * Reads the shared { "pdfs": [{name, base64, is_primary}] } envelope. Entries without usable
     * Base64 are dropped rather than failing the batch.
     */
    private static List<PdfDoc> parsePdfs(JsonObject json) {
        List<PdfDoc> result = new ArrayList<>();
        if (json == null || !json.has("pdfs") || !json.get("pdfs").isJsonArray()) {
            return result;
        }

        JsonArray pdfs = json.getAsJsonArray("pdfs");
        for (JsonElement element : pdfs) {
            if (!element.isJsonObject()) {
                continue;
            }
            JsonObject obj = element.getAsJsonObject();
            String base64 = stringOrNull(obj, "base64");
            if (base64 == null || base64.isBlank()) {
                continue;
            }
            String name = stringOrNull(obj, "name");
            boolean isPrimary = obj.has("is_primary") && !obj.get("is_primary").isJsonNull()
                    && obj.get("is_primary").getAsBoolean();
            result.add(new PdfDoc(name, base64, isPrimary));
        }
        return result;
    }

    public void markPdfUploaded(String accountPhone) {
        postSetupState(accountPhone, Map.of("phone", accountPhone, "pdf_uploaded", true));
    }

    public void markBookingConfigured(String accountPhone) {
        postSetupState(accountPhone, Map.of("phone", accountPhone, "booking_configured", true));
    }

    /**
     * Records the high commission the booking config was actually accepted with, after IVAC rejected
     * the operator's configured centre with "Invalid High Commission.". Persisting it is what stops
     * the next window walking the whole rotation again.
     */
    public void reportBookingCity(String accountPhone, String city) {
        postSetupState(accountPhone, Map.of("phone", accountPhone, "booking_city", city));
    }

    /**
     * Records the IVAC profile name the bot corrected a "Name mismatch" to, so the portal shows what
     * the account is now registered as and the fix is not re-attempted on a later run.
     */
    public void reportProfileName(String accountPhone, String profileName) {
        postSetupState(accountPhone, Map.of("phone", accountPhone, "ivac_profile_name", profileName));
    }

    /**
     * Moves the primary flag onto the document IVAC confirmed belongs to the account holder, after
     * the configured primary turned out to be a different applicant's form. Persisting it here is
     * what stops the same wrong document being uploaded first on the next run.
     */
    public void reportPrimaryPdf(String accountPhone, String pdfName) {
        postSetupState(accountPhone, Map.of("phone", accountPhone, "primary_pdf_name", pdfName));
    }

    /**
     * POST /api/accounts/appointment-dates — replaces the account's portal appointment-date range
     * with the dates IVAC itself reports as available, so the operator's guessed From/To range is
     * corrected to the real list for the next window. Best-effort: a failure only costs the portal
     * update, the bot already rotates the dates in memory.
     */
    public void updateAppointmentDates(String accountPhone, List<String> dates) {
        if (bearerToken == null || dates == null || dates.isEmpty()) {
            return;
        }

        Request request = new Request.Builder()
                .url(Constants.PORTAL_URL + "/api/accounts/appointment-dates")
                .header("Authorization", "Bearer " + bearerToken)
                .post(RequestBody.create(
                        GSON.toJson(Map.of("phone", accountPhone, "appointment_dates", dates)), JSON))
                .build();

        try (Response response = httpClient.newCall(request).execute()) {
            if (response.isSuccessful()) {
                ConsoleLogger.log(phone, "Portal appointment dates updated from IVAC ("
                        + dates.size() + " date(s))", "OK");
            } else {
                ConsoleLogger.log(phone, "Update appointment dates → HTTP " + response.code(), "WARN");
            }
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Update appointment dates failed: " + e.getMessage(), "WARN");
        }
    }

    private void postSetupState(String accountPhone, Map<String, Object> payload) {
        if (bearerToken == null) {
            return;
        }

        Request request = new Request.Builder()
                .url(Constants.PORTAL_URL + "/api/accounts/setup-state")
                .header("Authorization", "Bearer " + bearerToken)
                .post(RequestBody.create(GSON.toJson(payload), JSON))
                .build();

        try (Response response = httpClient.newCall(request).execute()) {
            if (!response.isSuccessful()) {
                ConsoleLogger.log(phone, "Report setup-state → HTTP " + response.code(), "WARN");
            }
        } catch (IOException e) {
            ConsoleLogger.log(phone, "Report setup-state failed: " + e.getMessage(), "WARN");
        }
    }

    private static String stringOrNull(JsonObject obj, String key) {
        return obj.has(key) && !obj.get(key).isJsonNull() ? obj.get(key).getAsString() : null;
    }

    @Override
    public void close() {
        httpClient.dispatcher().cancelAll();
        httpClient.dispatcher().executorService().shutdown();
        httpClient.connectionPool().evictAll();
    }
}
