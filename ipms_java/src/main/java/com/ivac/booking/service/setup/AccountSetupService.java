package com.ivac.booking.service.setup;

import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.ivac.booking.Constants;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.config.BookingCityOption;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.model.domain.CaptchaToken;
import com.ivac.booking.service.captcha.PortalCaptchaClient;
import com.ivac.booking.util.ConsoleLogger;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.Base64;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;
import java.util.function.BooleanSupplier;

/**
 * One-time account setup performed once per account before the slot race:
 *   1. Upload every applicant PDF (POST /file/upload-file) — primary first.
 *   2. Post the booking config (POST /appointment/appointment-booking-config).
 * IVAC rejects slot reserve until both are done. Both are guarded by persisted flags
 * (pdfUploaded / bookingConfigured) so a JWT-reuse cycle or a bot restart skips them.
 *
 * Best-effort: failures are logged and the flag is left unset so the next cycle retries;
 * the method never throws so it does not introduce a new fatal path into the race.
 */
public class AccountSetupService {

    private static final String APPOINTMENT_PATH = "/appointment";
    // Compiled-in fallback paths used only when the config omits them (bundle-extracted values win).
    private static final String UPLOAD_PATH = "/file/upload_file_v23";
    private static final String BOOKING_CONFIG_PATH = "/appointment/appointment-booking-config";
    private static final String GET_BOOKING_CONFIG_PATH = "/appointment/get-booking-config";

    // Delay between setup retry rounds. Setup retries transient failures (upload/booking-config
    // HTTP errors) until they succeed or the JWT-expiry deadline is reached.
    private static final long SETUP_RETRY_DELAY_MS = 2_000L;

    // Longer delay used when IVAC has closed uploads entirely ("Uploads are currently closed.").
    // That is a server-side gate that reopens on IVAC's own schedule, so nothing this bot does
    // makes it clear sooner — and every round burns one raw captcha solve per pending PDF. Backing
    // off keeps the step retrying until the JWT expires without spending a solve every 2 seconds.
    private static final long UPLOADS_CLOSED_RETRY_DELAY_MS = 15_000L;

    // Delay between get-booking-config polls in the background dates/appointmentId sync. Longer than
    // the setup retry delay because this poll runs alongside the live slot race and must not compete
    // with reserve for connections; nothing blocks on its result.
    private static final long BOOKING_CONFIG_SYNC_INTERVAL_MS = 3_000L;

    private static final Gson GSON = new Gson();

    /**
     * Outcome of a single setup step attempt.
     *   DONE        — step is complete, nothing more to do.
     *   RETRY       — step failed transiently (HTTP error / IO); retrying may succeed.
     *   RETRY_FAST  — like RETRY, but the next round posts something different (the next high
     *                 commission), so it runs immediately instead of sleeping out the round delay.
     *   RETRY_SLOW  — like RETRY, but the blocker is a server-side gate that only clears on IVAC's
     *                 schedule, so the next round waits UPLOADS_CLOSED_RETRY_DELAY_MS instead.
     *   GIVE_UP     — step cannot succeed without operator action (no slot key, no PDFs, no city);
     *                 retrying is pointless.
     *   RESET_CHAIN — the IVAC-side appointment (and its uploaded PDFs) was reset; the whole
     *                 create → upload → booking-config chain must be re-established from scratch.
     */
    private enum StepResult { DONE, RETRY, RETRY_FAST, RETRY_SLOW, GIVE_UP, RESET_CHAIN }

    // Cache of PDFs already uploaded to IVAC in this process, keyed by
    // "accountId:sha256(base64)". A retry after a partial failure re-uploads only the
    // PDFs missing from this set, so successful uploads are never repeated (no duplicates,
    // no wasted captcha) even across cycles within the same bot run.
    private static final Set<String> UPLOADED_CACHE = ConcurrentHashMap.newKeySet();

    private final AccountConfig account;
    private final IvacHttpClient client;
    private final PortalCaptchaClient portalCaptcha;
    private final PortalSetupClient portalSetup;

    // Held rather than snapshotted: the portal can rotate the endpoint paths and the upload
    // runtime-state header mid-window, so every call re-reads them through the AppConfig getters
    // (which fall back to the constants above per key).
    private final AppConfig appConfig;

    // Shelf life applied to a prefetched raw captcha token before an upload consumes it — the same
    // captchaShelfLifeMs the sign-in flow uses (SigninServiceImpl), so a token that aged past it
    // while waiting for OTP verify is discarded and re-solved rather than uploaded stale.
    private final long captchaShelfLifeMs;

    // PDF upload payload prefetched off the OTP-verify critical path: the applicant PDFs are
    // fetched from the portal and base64-decoded ahead of time (during the OTP-verify wait) so the
    // upload calls carry a ready byte[] the instant OTP verifies. null until prefetchPdfPayload runs.
    private volatile CompletableFuture<List<PreparedPdf>> pdfPrefetch;

    // Raw captcha tokens ("x-token") prefetched during the OTP-verify wait — one solve started per
    // applicant PDF so an upload never blocks ~10-13s on a cold Turnstile solve on the critical path
    // between the appointment call and the first upload. Drained by fetchRawCaptcha; a fresh solve is
    // started when the pool is empty (e.g. an upload retry). A raw token is valid independently of the
    // JWT, so prefetching it before OTP verifies is safe.
    private final Queue<CompletableFuture<CaptchaToken>> rawCaptchaPrefetch = new ConcurrentLinkedQueue<>();

    // Accounts whose primary document has already been re-selected in this process. A second probe
    // would re-upload every document again for nothing, so one attempt is all an account gets.
    private static final Set<Integer> PRIMARY_RESELECTED = ConcurrentHashMap.newKeySet();

    // IVAC's raw rejection body from a secondary upload, kept so the mismatch is resolved once at
    // step level: uploadOne itself can be running on several virtual threads at a time.
    private final AtomicReference<String> pendingMismatchBody = new AtomicReference<>();

    // High-commission rotation for booking config. IVAC answers 400 "Invalid High Commission." when
    // the configured centre does not match the applicant's own web file, and that never clears on
    // retry — so the candidate list (configured centre first, then every other one) is walked instead
    // of the same pair being posted until the JWT expires. Built once; the cursor keeps its place
    // across retry rounds.
    private volatile List<BookingCityOption> bookingCityCandidates;
    private final AtomicInteger bookingCityCursor = new AtomicInteger();

    // Background get-booking-config sync: started at most once per service instance, and completed
    // when the poll ends so the owner of the PortalSetupClient can wait before closing it.
    private final AtomicBoolean bookingConfigSyncStarted = new AtomicBoolean();
    private final CompletableFuture<Void> bookingConfigSync = new CompletableFuture<>();

    /**
     * A single applicant PDF fetched from the portal and already base64-decoded, ready to POST to
     * IVAC. bytes is null when the portal payload was not valid Base64 (the upload skips that doc).
     */
    public record PreparedPdf(String name, boolean isPrimary, byte[] bytes, String base64) {
    }

    public AccountSetupService(AccountConfig account, IvacHttpClient client,
                               PortalCaptchaClient portalCaptcha, PortalSetupClient portalSetup,
                               AppConfig appConfig, long captchaShelfLifeMs) {
        this.account = account;
        this.client = client;
        this.portalCaptcha = portalCaptcha;
        this.portalSetup = portalSetup;
        this.captchaShelfLifeMs = captchaShelfLifeMs;
        this.appConfig = appConfig;
    }

    // Package-private so the no-caching regression test can assert these follow a live config swap.
    String uploadPath() {
        return appConfig != null ? appConfig.getUploadFilePath() : UPLOAD_PATH;
    }

    String bookingConfigPath() {
        return appConfig != null ? appConfig.getBookingConfigPath() : BOOKING_CONFIG_PATH;
    }

    String getBookingConfigPath() {
        return appConfig != null ? appConfig.getGetBookingConfigPath() : GET_BOOKING_CONFIG_PATH;
    }

    String uploadRuntimeState() {
        return appConfig != null ? appConfig.getUploadRuntimeState() : Constants.UPLOAD_RUNTIME_STATE;
    }

    /**
     * Starts fetching and base64-decoding the applicant PDFs from the portal on a background
     * virtual thread, so the upload payload is in hand before OTP verifies — the upload step then
     * skips the portal round-trip and the decode at the competitive moment. Idempotent (starts once
     * per instance); a no-op when the PDFs are already uploaded or no slot key is present. Fetch
     * failures are captured on the future and fall back to a synchronous fetch at upload time.
     */
    public void prefetchPdfPayload() {
        if (pdfPrefetch != null || account.isPdfUploaded() || !portalSetup.hasAuth()) {
            return;
        }
        CompletableFuture<List<PreparedPdf>> future = new CompletableFuture<>();
        pdfPrefetch = future;
        Thread.ofVirtual().name("pdf-prefetch-" + account.getPhone()).start(() -> {
            try {
                List<PreparedPdf> prepared = fetchAndDecode();
                if (!prepared.isEmpty()) {
                    ConsoleLogger.log(account.getPhone(), "PDF upload payload prefetched ("
                            + prepared.size() + " doc(s)) — ready before OTP verify", "OK");
                    prefetchRawCaptchas(prepared.size());
                }
                future.complete(prepared);
            } catch (Exception e) {
                future.completeExceptionally(e);
            }
        });
    }

    /**
     * Starts one raw-captcha solve per applicant PDF on the portal so each upload's x-token is
     * already in flight (or solved) before OTP verifies. The raw Turnstile solve is ~10-13s and,
     * left on the upload's critical path, is by far the largest delay between the appointment call
     * and the first upload. Tokens are pulled by fetchRawCaptcha; any left unconsumed (e.g. a PDF
     * that fails Base64 decoding) simply expire.
     */
    private void prefetchRawCaptchas(int count) {
        for (int i = 0; i < count; i++) {
            rawCaptchaPrefetch.add(portalCaptcha.requestCaptcha("raw", account.getPhone()));
        }
        if (count > 0) {
            ConsoleLogger.log(account.getPhone(), "Prefetching " + count
                    + " raw captcha token(s) for PDF upload — overlapping the OTP-verify wait", "OK");
        }
    }

    /**
     * Runs both setup steps in order, retrying transient failures until they succeed or the
     * JWT-expiry {@code deadlineMs} is reached. Requires an OTP-verified JWT already set on the
     * client (both IVAC endpoints 401 otherwise). Never throws for a step failure — a failed
     * step just leaves its flag unset so a later cycle retries.
     *
     * Returns as soon as the chain is done — the follow-up get-booking-config fetch is left running
     * in the background so the caller can open the slot gate immediately (see startBookingConfigSync).
     * {@code abortWhen} stops that background poll once the race is over.
     */
    public void prepareForBooking(long deadlineMs, BooleanSupplier abortWhen) throws InterruptedException {
        // No get-booking-config probe runs before the chain: until appointment-booking-config has
        // posted, IVAC answers that GET with an HTTP 200 whose body is
        // {"code":404,"http_status":404,"message":"Appointment not found."} — it can never return the
        // appointmentDate[] array this early, so the call was a guaranteed-useless 11-22s round trip
        // on the setup critical path. It is started in the background once booking config is set.

        // Run the setup chain, rebuilding it from scratch if a step reports the IVAC appointment
        // (and its uploaded PDFs) were reset — see the RESET_CHAIN branches in uploadPdfsAttempt and
        // configureBookingAttempt. The JWT-expiry deadline (checked inside retryUntilDone) is the
        // only bound needed: a persistent 404 loop simply runs out its JWT and gives up, same as any
        // other stuck step.
        while (true) {
            retryUntilDone("Create appointment", deadlineMs, this::createAppointmentAttempt);
            // A RESET_CHAIN from the upload means IVAC has no appointment record, so booking-config
            // would only 404 too — go straight to the rebuild instead of spending that round trip.
            StepResult chain = retryUntilDone("PDF upload", deadlineMs, this::uploadPdfsAttempt);
            // A GIVE_UP from the upload ends the whole chain, not just its own step. Nothing IVAC
            // holds can make booking-config succeed with no accepted primary document, so it answers
            // 404 "Appointment not found." — which is RESET_CHAIN, which rebuilds, which re-uploads
            // the same unacceptable document. That loop ran 41 uploads in 100 seconds on account 988
            // (one PDF, a different applicant's) and only ever stopped at JWT expiry.
            if (chain == StepResult.GIVE_UP) {
                ConsoleLogger.log(account.getPhone(), "PDF upload cannot complete for this account — "
                        + "stopping setup instead of rebuilding the chain (booking config would 404 "
                        + "with no accepted primary document and re-upload it every round)", "ERROR");
                return;
            }
            if (chain != StepResult.RESET_CHAIN) {
                chain = retryUntilDone("Booking config", deadlineMs, this::configureBookingAttempt);
            }
            if (chain != StepResult.RESET_CHAIN) {
                break;
            }
            // IVAC lost the appointment and its PDFs. Wipe the per-account upload cache + flags so
            // create/upload actually re-run (the cache and pdf_uploaded flag would otherwise skip them),
            // then loop to re-establish the whole chain — unless the JWT is about to expire.
            invalidateSetupForRebuild();
            if (System.currentTimeMillis() + SETUP_RETRY_DELAY_MS >= deadlineMs) {
                ConsoleLogger.log(account.getPhone(), "Appointment reset near JWT expiry — giving up "
                        + "chain rebuild this cycle (will retry after re-auth)", "WARN");
                break;
            }
        }

        // Booking config is set (either just now, or already on a JWT-reuse restart), so
        // get-booking-config can finally answer with the real appointmentDate[] + appointmentId.
        // Fetch it in the BACKGROUND: reserve-slot already has the portal's configured dates and
        // must not wait on this round trip (11-22s under window load) before firing its first shot.
        startBookingConfigSync(deadlineMs, abortWhen);
    }

    private boolean isIvacDatesEmpty() {
        List<String> dates = account.getIvacAppointmentDates();
        return dates == null || dates.isEmpty();
    }

    /**
     * Starts the background get-booking-config sync (once per service instance) and returns
     * immediately. The poll retries until IVAC answers with a real payload — until
     * appointment-booking-config has propagated it keeps returning an HTTP 200 wrapping
     * 404 "Appointment not found." — then captures the appointmentDate[] for reserve-slot, the
     * appointmentId for payment, and pushes the dates back to the portal.
     *
     * No-op when booking is not configured yet (the GET cannot succeed before that), when the dates
     * are already in hand, or when a sync was already started.
     */
    public void startBookingConfigSync(long deadlineMs, BooleanSupplier abortWhen) {
        if (!account.isBookingConfigured() || !isIvacDatesEmpty()) {
            return;
        }
        if (!bookingConfigSyncStarted.compareAndSet(false, true)) {
            return;
        }
        String phone = account.getPhone();
        Thread.ofVirtual().name("booking-config-sync-" + phone).start(() -> {
            try {
                AtomicInteger attempt = new AtomicInteger();
                boolean captured = pollUntilSuccess(deadlineMs, BOOKING_CONFIG_SYNC_INTERVAL_MS, abortWhen,
                        () -> {
                            int round = attempt.incrementAndGet();
                            // The poll runs for the whole race; logging every failed round would bury
                            // the slot/payment lines, so only the first and every 5th are reported.
                            return refreshBookingConfigOnce(phone, "sync #" + round,
                                    round == 1 || round % 5 == 0);
                        });
                if (!captured && !abortWhen.getAsBoolean()) {
                    ConsoleLogger.log(phone, "get-booking-config sync gave up after "
                            + attempt.get() + " attempt(s) — reserve keeps using the portal dates "
                            + "and payment falls back to its own appointmentId fetch", "WARN");
                }
            } finally {
                bookingConfigSync.complete(null);
            }
        });
    }

    /**
     * Waits for a started background sync to finish, bounded by {@code deadlineMs}. Only needed by
     * the owner of the PortalSetupClient, so the client is not closed out from under the poll's
     * date writeback. Returns immediately when no sync is running. Never throws.
     */
    public void awaitBookingConfigSync(long deadlineMs) {
        if (!bookingConfigSyncStarted.get()) {
            return;
        }
        long remaining = deadlineMs - System.currentTimeMillis();
        if (remaining <= 0) {
            return;
        }
        try {
            bookingConfigSync.get(remaining, TimeUnit.MILLISECONDS);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        } catch (Exception ignored) {
            // Timed out or failed — the poll bounds itself by the same deadline and logs its own outcome.
        }
    }

    /**
     * Repeats {@code attempt} until it reports success, {@code abortWhen} goes true, or the deadline
     * is reached, sleeping {@code intervalMs} between rounds. Returns true when an attempt succeeded.
     * A round is not started when there is no time left to sleep afterwards, so the loop never
     * overruns its deadline. Package-private and static so the retry/abort/deadline semantics are
     * unit-testable without an HTTP client.
     */
    static boolean pollUntilSuccess(long deadlineMs, long intervalMs, BooleanSupplier abortWhen,
                                    BooleanSupplier attempt) {
        while (System.currentTimeMillis() < deadlineMs) {
            if (abortWhen.getAsBoolean()) {
                return false;
            }
            if (attempt.getAsBoolean()) {
                return true;
            }
            if (System.currentTimeMillis() + intervalMs >= deadlineMs) {
                return false;
            }
            try {
                Thread.sleep(intervalMs);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return false;
            }
        }
        return false;
    }

    /**
     * Repeats {@code step} until it returns a non-retrying result, or the deadline is reached.
     * Sleeps SETUP_RETRY_DELAY_MS between RETRY rounds, nothing after a RETRY_FAST (the next round
     * sends a different payload, so there is nothing to wait for) and UPLOADS_CLOSED_RETRY_DELAY_MS
     * after a RETRY_SLOW. The deadline is checked against the delay actually about to be slept, so a
     * slow round can never overrun the JWT expiry. Returns the terminal StepResult (DONE / GIVE_UP /
     * RESET_CHAIN), or RETRY when it gave up near the deadline, so the caller can react to a
     * RESET_CHAIN signal.
     */
    private StepResult retryUntilDone(String label, long deadlineMs, StepSupplier step) throws InterruptedException {
        while (true) {
            StepResult result = step.get();
            if (result != StepResult.RETRY && result != StepResult.RETRY_FAST && result != StepResult.RETRY_SLOW) {
                return result;
            }
            long delayMs = retryDelayMs(result);
            if (System.currentTimeMillis() + delayMs >= deadlineMs) {
                ConsoleLogger.log(account.getPhone(), label + " still incomplete near JWT expiry — "
                        + "giving up this cycle (will retry after re-auth)", "WARN");
                return StepResult.RETRY;
            }
            if (delayMs > 0) {
                Thread.sleep(delayMs);
            }
        }
    }

    private static long retryDelayMs(StepResult result) {
        return switch (result) {
            case RETRY_FAST -> 0L;
            case RETRY_SLOW -> UPLOADS_CLOSED_RETRY_DELAY_MS;
            default -> SETUP_RETRY_DELAY_MS;
        };
    }

    @FunctionalInterface
    private interface StepSupplier {
        StepResult get() throws InterruptedException;
    }

    /**
     * Creates the IVAC appointment record (POST /appointment, empty body) that the file service
     * requires before any PDF upload — without it upload 404s "Appointment not found.". IVAC
     * returns the existing record when one is already present, so the call is idempotent.
     *
     * Skipped only once booking is configured — NOT merely once the PDFs have uploaded. IVAC's
     * appointment record (and the PDFs attached to it) do not survive a session/window reset, but
     * the portal pdf_uploaded flag persists across sessions; keying the skip on pdf_uploaded made a
     * restart-resume skip this create while the IVAC-side appointment was already gone, so
     * booking-config then 404'd "Appointment not found." forever. booking_configured is the only
     * flag that reliably means the whole chain (appointment + PDFs + config) is intact for the
     * current session, so a resume with booking_configured=false always re-establishes it here.
     *
     * Only a 2xx carrying successFlag:true counts as created. A blanket "4xx means it already
     * exists" is wrong and cost a full booking window: under load IVAC answers this POST with a
     * transient 400 {"code":<n>,"message":"...INCIDENT-ID: <n>..."}, which the old code accepted as
     * DONE — no appointment was ever created, so every following upload 404'd "Appointment not
     * found." for 18 minutes. Only an explicit already-exists (409 / "already exists") is a genuine
     * terminal success; every other non-2xx is transient and retried. Retrying here does not risk
     * starving the later steps: with no appointment record the upload cannot succeed either, so
     * spending the setup budget on the step that can actually unblock the chain is strictly better.
     */
    private StepResult createAppointmentAttempt() {
        String phone = account.getPhone();
        if (account.isBookingConfigured()) {
            return StepResult.DONE;
        }

        try {
            IvacHttpClient.RawResponse response = client.postEmpty(APPOINTMENT_PATH);
            int code = response.statusCode();
            if (code >= 200 && code < 300 && isSuccessFlag(response.body())) {
                captureAppointmentIdFromBody(phone, response.body());
                ConsoleLogger.log(phone, "Appointment record created", "OK");
                return StepResult.DONE;
            }
            if (isAlreadyExists(code, response.body())) {
                ConsoleLogger.log(phone, "Create appointment → HTTP " + code
                        + " already exists — proceeding", "OK");
                return StepResult.DONE;
            }
            ConsoleLogger.log(phone, "Create appointment → HTTP " + code + " "
                    + truncate(response.body()) + " — NOT created; will retry (upload would 404 "
                    + "\"Appointment not found.\" without it)", "RETRY");
            return StepResult.RETRY;
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Create appointment failed: " + e.getMessage() + " — will retry", "RETRY");
            return StepResult.RETRY;
        }
    }

    /**
     * True when a response body carries IVAC's successFlag:true. A 2xx alone is not proof of success
     * on this API — IVAC also returns HTTP 200 envelopes wrapping an inner failure. A body that is
     * blank or unparseable is treated as NOT successful, so an unrecognised response retries rather
     * than silently marking the appointment created.
     */
    static boolean isSuccessFlag(String body) {
        if (body == null || body.isBlank()) {
            return false;
        }
        try {
            JsonObject root = GSON.fromJson(body, JsonObject.class);
            return root != null
                    && root.has("successFlag")
                    && !root.get("successFlag").isJsonNull()
                    && root.get("successFlag").getAsBoolean();
        } catch (Exception e) {
            return false;
        }
    }

    /**
     * True when a response says the record it was asked to create is already present — a 409, or any
     * body stating "already exists". That is a genuine terminal success for an idempotent create;
     * every other non-2xx is transient.
     */
    static boolean isAlreadyExists(int statusCode, String body) {
        if (statusCode == 409) {
            return true;
        }
        return body != null && body.toLowerCase().contains("already exists");
    }

    /**
     * Best-effort extraction of appointmentId from a response body. Kept defensive rather than
     * relied upon: the live POST /appointment success body is
     * {"data":null,"statusCode":200,"message":"Success","successFlag":true,...} — data is always
     * null, so the id in practice always comes from get-booking-config after booking-config posts.
     * No-op when the body has no appointmentId or one is already set.
     */
    private void captureAppointmentIdFromBody(String phone, String body) {
        if (body == null || body.isBlank()) {
            return;
        }
        if (account.getAppointmentId() != null && !account.getAppointmentId().isBlank()) {
            return;
        }
        try {
            JsonObject root = GSON.fromJson(body, JsonObject.class);
            if (root != null && root.has("data") && root.get("data").isJsonObject()) {
                JsonObject data = root.getAsJsonObject("data");
                if (data.has("appointmentId") && !data.get("appointmentId").isJsonNull()) {
                    String id = data.get("appointmentId").getAsString();
                    if (id != null && !id.isBlank()) {
                        account.setAppointmentId(id);
                        ConsoleLogger.log(phone, "appointmentId captured from /appointment: " + id, "OK");
                    }
                }
            }
        } catch (Exception ignored) {
            // Response shape unknown — appointmentId is still recoverable later via get-booking-config.
        }
    }

    private StepResult uploadPdfsAttempt() throws InterruptedException {
        String phone = account.getPhone();
        // Skip only once booking is configured (the whole IVAC-side chain is intact for this
        // session). Keying on pdf_uploaded alone made a restart-resume skip the re-upload while
        // IVAC's appointment — and the PDFs attached to it — had been reset, leaving reserve-slot
        // with no uploaded documents. When the appointment is still present IVAC answers a re-upload
        // with 409 "File already exists." which uploadOne treats as success, so re-running here is
        // cheap and self-healing rather than a duplicate upload.
        if (account.isBookingConfigured()) {
            return StepResult.DONE;
        }

        if (!portalSetup.hasAuth()) {
            ConsoleLogger.log(phone, "PDF upload needs a slot API key to fetch PDFs — skipping "
                    + "(prepare this account via the api-tester in local mode)", "WARN");
            return StepResult.GIVE_UP;
        }

        List<PreparedPdf> pdfs = awaitPreparedPdfs();
        if (pdfs.isEmpty()) {
            ConsoleLogger.log(phone, "No PDFs configured for this account — slot reserve will fail "
                    + "until PDFs are added on the portal", "WARN");
            return StepResult.GIVE_UP;
        }

        // IVAC attaches every secondary applicant to the PRIMARY applicant's record, so the
        // primary PDF must be uploaded and registered on IVAC BEFORE any secondary is sent —
        // otherwise the secondaries 404 with "Primary application not found.". Upload the primary
        // first and await its success; only then upload the remaining PDFs in parallel. (Uploading
        // all of them concurrently races the secondaries ahead of the primary and 404s them.)
        PreparedPdf primary = findPrimary(pdfs);
        if (primary != null && !UPLOADED_CACHE.contains(cacheKey(primary))) {
            ConsoleLogger.log(phone, "Uploading primary applicant PDF first: " + primary.name(), "AUTH");
            UploadAttempt attempt = uploadOne(phone, primary);
            UploadOutcome outcome = attempt.outcome();
            if (outcome == UploadOutcome.APPOINTMENT_MISSING) {
                return reportAppointmentMissing(phone);
            }
            if (outcome == UploadOutcome.UPLOADS_CLOSED) {
                return StepResult.RETRY_SLOW;
            }
            if (outcome == UploadOutcome.REGISTRATION_EXPIRED) {
                return StepResult.GIVE_UP;
            }
            if (outcome == UploadOutcome.MISMATCH) {
                return handleMismatch(phone, primary, pdfs, attempt.body());
            }
            if (outcome != UploadOutcome.SUCCESS) {
                ConsoleLogger.log(phone, "Primary PDF not yet registered on IVAC — deferring "
                        + "secondary uploads until it succeeds", "RETRY");
                return StepResult.RETRY;
            }
        }

        // Primary is registered; upload every remaining (secondary) PDF not yet uploaded in parallel.
        List<PreparedPdf> pending = new ArrayList<>();
        for (PreparedPdf pdf : pdfs) {
            if (!UPLOADED_CACHE.contains(cacheKey(pdf))) {
                pending.add(pdf);
            }
        }

        if (!pending.isEmpty()) {
            ConsoleLogger.log(phone, "Uploading " + pending.size() + "/" + pdfs.size()
                    + " secondary PDF(s) to IVAC (parallel)", "AUTH");
            // Upload all pending secondary PDFs concurrently on virtual threads — each fetches its
            // own raw captcha, so the captcha solves overlap instead of running back-to-back.
            boolean appointmentMissing = false;
            boolean uploadsClosed = false;
            try (ExecutorService pool = Executors.newVirtualThreadPerTaskExecutor()) {
                List<Future<UploadAttempt>> futures = new ArrayList<>(pending.size());
                for (PreparedPdf pdf : pending) {
                    futures.add(pool.submit(() -> uploadOne(phone, pdf)));
                }
                for (Future<UploadAttempt> future : futures) {
                    try {
                        UploadAttempt attempt = future.get();
                        if (attempt.outcome() == UploadOutcome.APPOINTMENT_MISSING) {
                            appointmentMissing = true;
                        } else if (attempt.outcome() == UploadOutcome.UPLOADS_CLOSED) {
                            uploadsClosed = true;
                        } else if (attempt.outcome() == UploadOutcome.MISMATCH) {
                            pendingMismatchBody.compareAndSet(null, attempt.body());
                        }
                    } catch (Exception ignored) {
                        // Per-PDF outcome is recorded in UPLOADED_CACHE inside uploadOne.
                    }
                }
            }
            if (appointmentMissing) {
                return reportAppointmentMissing(phone);
            }
            // A closed upload service blocks every remaining document equally, so back the whole
            // step off instead of re-solving a captcha per PDF every 2 seconds.
            if (uploadsClosed) {
                return StepResult.RETRY_SLOW;
            }
            // IVAC validates the uploaded form against the account holder ONLY for the primary
            // document — across every upload logged on July 31 2026, all 747 rejections carried
            // "primary":true and all 61 secondary uploads came back with an empty error[]. That is
            // what makes the whole repair well-defined: secondaries are companions and are SUPPOSED
            // to be other people, so their details differing from the account means nothing.
            //
            // So a mismatch reported by a secondary is not something to repair — no profile edit or
            // primary swap follows from it. It is only surfaced, in case IVAC ever changes this.
            String secondaryMismatch = pendingMismatchBody.getAndSet(null);
            if (secondaryMismatch != null) {
                ConsoleLogger.log(phone, "A secondary applicant's PDF was rejected — "
                        + ProfileMismatchFixService.assess(extractUploadErrors(secondaryMismatch)).describe()
                        + ". IVAC normally only validates the primary, so this is surfaced for an operator "
                        + "and the document counted as uploaded rather than retried.", "WARN");
            }
        }

        boolean allUploaded = true;
        for (PreparedPdf pdf : pdfs) {
            if (!UPLOADED_CACHE.contains(cacheKey(pdf))) {
                allUploaded = false;
                break;
            }
        }

        if (allUploaded) {
            account.setPdfUploaded(true);
            portalSetup.markPdfUploaded(phone);
            ConsoleLogger.log(phone, "All PDFs uploaded — pdf_uploaded set", "OK");
            return StepResult.DONE;
        }

        ConsoleLogger.log(phone, "Some PDF uploads failed — retrying only the failed ones", "RETRY");
        return StepResult.RETRY;
    }

    /**
     * Returns the prefetched, decoded PDFs, waiting on the prefetch future when it is still in
     * flight. Falls back to a synchronous fetch+decode when no prefetch ran (e.g. a lazily launched
     * setup path) or the prefetch failed. Retries reuse the same completed future, so the portal is
     * hit at most once regardless of how many upload rounds run.
     */
    private List<PreparedPdf> awaitPreparedPdfs() {
        CompletableFuture<List<PreparedPdf>> future = pdfPrefetch;
        if (future != null) {
            try {
                List<PreparedPdf> prepared = future.get();
                if (prepared != null) {
                    return prepared;
                }
            } catch (Exception e) {
                if (e instanceof InterruptedException) {
                    Thread.currentThread().interrupt();
                }
                ConsoleLogger.log(account.getPhone(), "PDF prefetch unavailable ("
                        + e.getMessage() + ") — fetching synchronously", "WARN");
            }
        }
        return fetchAndDecode();
    }

    /**
     * Fetches the applicant PDFs from the portal and base64-decodes each into a ready-to-POST byte[].
     */
    private List<PreparedPdf> fetchAndDecode() {
        List<PreparedPdf> prepared = new ArrayList<>();
        for (PortalSetupClient.PdfDoc pdf : portalSetup.fetchPdfs(account.getId())) {
            prepared.add(decodePdf(pdf));
        }
        return prepared;
    }

    /**
     * Decodes a portal PdfDoc's Base64 into a PreparedPdf with ready bytes. A malformed payload
     * keeps a null byte[] so the upload can skip it (and report it) without failing the batch.
     */
    static PreparedPdf decodePdf(PortalSetupClient.PdfDoc pdf) {
        byte[] bytes;
        try {
            bytes = Base64.getDecoder().decode(pdf.base64());
        } catch (IllegalArgumentException e) {
            bytes = null;
        }
        return new PreparedPdf(pdf.name(), pdf.isPrimary(), bytes, pdf.base64());
    }

    /**
     * Returns the primary applicant PDF (the one flagged is_primary by the portal). The portal
     * guarantees exactly one primary, but if none is flagged the first PDF is treated as primary
     * so the upload still has a defined anchor to register before the secondaries.
     */
    static PreparedPdf findPrimary(List<PreparedPdf> pdfs) {
        for (PreparedPdf pdf : pdfs) {
            if (pdf.isPrimary()) {
                return pdf;
            }
        }
        return pdfs.isEmpty() ? null : pdfs.get(0);
    }

    /**
     * Outcome of a single PDF upload.
     *   SUCCESS             — uploaded (or already present on IVAC); recorded in UPLOADED_CACHE.
     *   FAILED              — transient failure; retrying this PDF may succeed.
     *   APPOINTMENT_MISSING — IVAC has no appointment record to attach the file to; retrying the
     *                         upload alone can never succeed, the chain must be rebuilt.
     *   UPLOADS_CLOSED      — IVAC has closed uploads service-wide; retry, but slowly.
     *   MISMATCH            — HTTP 200 with a non-empty data.error[]: the form disagrees with the
     *                         account. See handleMismatch — it may be repairable, or it may mean the
     *                         wrong applicant's document is attached.
     *   REGISTRATION_EXPIRED — the applicant's IVAC web registration has aged out. Permanent for this
     *                         document; a secondary is skipped, a primary stops the account.
     */
    private enum UploadOutcome { SUCCESS, FAILED, APPOINTMENT_MISSING, UPLOADS_CLOSED, MISMATCH,
        REGISTRATION_EXPIRED }

    /**
     * One upload attempt: the outcome plus IVAC's raw response body. The body is carried through
     * because a MISMATCH response is the only place that states both sides of the comparison — the
     * account holder's details (in data.error[]) and the uploaded form's (in data.overview) — which
     * is what lets a wrong primary document be recognised and replaced without any further call.
     */
    private record UploadAttempt(UploadOutcome outcome, String body) {

        static UploadAttempt of(UploadOutcome outcome) {
            return new UploadAttempt(outcome, null);
        }
    }

    /**
     * Decides what to do about an upload IVAC rejected for an account/form mismatch, and does it.
     * Everything needed for the decision is in the one response body — see ProfileMismatchFixService.
     *
     * FIX_PDF        — have the portal rewrite the form from the account's IVAC profile and RETRY with
     *                  the corrected document. A failed rewrite is terminal: the same form would be
     *                  re-uploaded and re-rejected every round until the JWT expired.
     * WRONG_DOCUMENT — look for the holder's own form among the account's other documents and make
     *                  that one the primary, then RETRY; failing that, rewrite the form from the
     *                  profile like FIX_PDF does.
     * MANUAL         — stop. Retrying cannot clear it and every round costs a captcha solve.
     */
    private StepResult handleMismatch(String phone, PreparedPdf rejected, List<PreparedPdf> pdfs, String body) {
        List<String> errors = extractUploadErrors(body);
        ProfileMismatchFixService.Assessment assessment = ProfileMismatchFixService.assess(errors);

        switch (assessment.verdict()) {
            case FIX_PDF -> {
                return rewriteFormFromProfile(phone, assessment);
            }
            case WRONG_DOCUMENT -> {
                return reselectPrimary(phone, rejected, pdfs, assessment);
            }
            default -> {
                ConsoleLogger.log(phone, "Upload of " + (rejected != null ? rejected.name() : "the primary PDF")
                        + " is blocked by a mismatch only an operator can resolve — " + assessment.describe()
                        + ". The phone is the IVAC login and the email carries its OTP, so the bot will "
                        + "not change either; fix the account on IVAC or attach the matching form.", "ERROR");
                return StepResult.GIVE_UP;
            }
        }
    }

    /**
     * Reads the account's IVAC profile and has the portal rewrite the primary applicant form to state
     * it, then RETRIES with the corrected document. A failed rewrite is terminal: the same form would
     * otherwise be re-uploaded and re-rejected every round until the JWT expired.
     */
    private StepResult rewriteFormFromProfile(String phone, ProfileMismatchFixService.Assessment assessment) {
        List<PortalSetupClient.PdfDoc> corrected;
        try {
            corrected = new ProfileMismatchFixService(account.getId(), phone, appConfig, client,
                    portalSetup).syncPdfToProfile(assessment);
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Form rewrite failed: " + e.getMessage(), "ERROR");
            corrected = null;
        }
        if (corrected == null) {
            return StepResult.GIVE_UP;
        }
        // Continue this run with the rewritten documents; the corrected primary hashes differently,
        // so UPLOADED_CACHE sends it again on the next round.
        republishPdfs(corrected);
        return StepResult.RETRY;
    }

    /**
     * The rejected form belongs to someone else. IVAC named the account holder's own passport in the
     * mismatch, and every upload response describes the form it just received in data.overview — so
     * each remaining document is offered as the primary until one comes back describing the holder.
     * That document becomes the primary, on IVAC and on the portal, and setup continues from there.
     *
     * Costs one captcha solve per candidate, bounded by the number of documents on the account and
     * only ever reached once, since the outcome is persisted.
     *
     * When no such document exists — an account carrying exactly one, wrong, PDF is the common case —
     * the form is rewritten from the account's IVAC profile instead of dead-ending. Every route out of
     * here that used to end in GIVE_UP now tries that first: it is the only remaining move, and
     * stopping here is what left an account re-uploading the same rejected document every round for
     * the life of its JWT.
     */
    private StepResult reselectPrimary(String phone, PreparedPdf rejected, List<PreparedPdf> pdfs,
                                       ProfileMismatchFixService.Assessment assessment) {
        String holderPassport = assessment.accountPassport();
        if (holderPassport == null || holderPassport.isBlank()) {
            ConsoleLogger.log(phone, "Primary PDF belongs to a different applicant (" + assessment.describe()
                    + ") and IVAC did not state the account holder's passport, so the right document "
                    + "cannot be identified among the account's own — rewriting the form from the "
                    + "account's IVAC profile instead", "WARN");
            return rewriteFormFromProfile(phone, assessment);
        }

        if (!PRIMARY_RESELECTED.add(account.getId())) {
            ConsoleLogger.log(phone, "Primary PDF still mismatches after a re-selection this run — "
                    + "not probing again; rewriting the form from the account's IVAC profile instead", "WARN");
            return rewriteFormFromProfile(phone, assessment);
        }

        ConsoleLogger.log(phone, "Primary PDF is a different applicant's (" + assessment.describe()
                + "). Looking for the holder's own form (passport " + holderPassport + ") among the "
                + "account's other documents", "WARN");

        for (PreparedPdf candidate : pdfs) {
            if (candidate == rejected || candidate.bytes() == null) {
                continue;
            }
            UploadAttempt attempt = uploadOne(phone, new PreparedPdf(
                    candidate.name(), true, candidate.bytes(), candidate.base64()));
            String formPassport = ProfileMismatchFixService.overviewPassport(attempt.body());
            if (!holderPassport.equalsIgnoreCase(formPassport)) {
                ConsoleLogger.log(phone, candidate.name() + " is passport " + formPassport
                        + " — not the account holder; trying the next document", "WAIT");
                continue;
            }

            ConsoleLogger.log(phone, candidate.name() + " is the account holder's own form (passport "
                    + formPassport + ") — making it the primary applicant", "OK");
            // Persist the correction so a restart (and the next window) starts from the right
            // document, and mirror it into the in-memory list so this run continues with it too.
            portalSetup.reportPrimaryPdf(phone, candidate.name());
            repointPrimary(pdfs, candidate.name());
            // The candidate is registered on IVAC and cached as uploaded; the remaining documents go
            // up as secondaries on the next round.
            return StepResult.RETRY;
        }

        ConsoleLogger.log(phone, "None of the account's documents is the holder's own form (passport "
                + holderPassport + ") — rewriting the primary from the account's IVAC profile", "WARN");
        return rewriteFormFromProfile(phone, assessment);
    }

    /**
     * Rewrites the prefetched PDF list so the named document is the primary and every other one is a
     * secondary, and republishes it as the prefetch result. Without this the rest of the run would
     * keep finding the old (wrong) primary in memory even though the portal has been corrected.
     */
    private void repointPrimary(List<PreparedPdf> pdfs, String primaryName) {
        List<PreparedPdf> corrected = new ArrayList<>(pdfs.size());
        for (PreparedPdf pdf : pdfs) {
            corrected.add(new PreparedPdf(pdf.name(), primaryName.equals(pdf.name()),
                    pdf.bytes(), pdf.base64()));
        }
        pdfPrefetch = CompletableFuture.completedFuture(corrected);
    }

    /**
     * Replaces the prefetched documents with the ones the portal just rewrote, so the rest of the run
     * uploads the corrected form rather than the rejected one still held in memory.
     */
    private void republishPdfs(List<PortalSetupClient.PdfDoc> pdfs) {
        List<PreparedPdf> prepared = new ArrayList<>(pdfs.size());
        for (PortalSetupClient.PdfDoc pdf : pdfs) {
            prepared.add(decodePdf(pdf));
        }
        pdfPrefetch = CompletableFuture.completedFuture(prepared);
    }

    /**
     * Logs an upload-side "Appointment not found." and asks the orchestrator to rebuild the chain.
     */
    private StepResult reportAppointmentMissing(String phone) {
        ConsoleLogger.log(phone, "Upload → 404 Appointment not found — IVAC has no appointment "
                + "record; re-creating it and re-uploading rather than retrying the upload", "RETRY");
        return StepResult.RESET_CHAIN;
    }

    /**
     * Uploads a single (already-decoded) PDF and records success in UPLOADED_CACHE. The bytes-null
     * guard runs before the captcha fetch so a malformed doc never wastes a captcha solve.
     */
    private UploadAttempt uploadOne(String phone, PreparedPdf pdf) {
        if (pdf.bytes() == null) {
            ConsoleLogger.log(phone, "Upload skipped — bad Base64 for " + pdf.name(), "ERROR");
            return UploadAttempt.of(UploadOutcome.FAILED);
        }

        String xToken = fetchRawCaptcha();
        if (xToken == null) {
            ConsoleLogger.log(phone, "Upload skipped — could not obtain captcha for " + pdf.name(), "ERROR");
            return UploadAttempt.of(UploadOutcome.FAILED);
        }

        try {
            IvacHttpClient.RawResponse response =
                    client.postMultipartFile(uploadPath(), pdf.bytes(), pdf.name(), pdf.isPrimary(), xToken, uploadRuntimeState());
            // The real outcome, which is NOT always the transport status — see effectiveStatus.
            int status = effectiveStatus(response.statusCode(), response.body());
            // 409 "File already exists." means IVAC already has this PDF (uploaded on a prior run
            // whose cache did not survive a restart) — treat as done, not an error to retry.
            boolean alreadyExists = status == 409
                    && response.body() != null
                    && response.body().toLowerCase().contains("already exists");
            // A passport inside its post-appointment cooldown is permanently blocked for this window.
            // Skip that document and count it as done so the remaining applicants still finish setup.
            boolean passportBlocked = isRecentAppointmentBlock(status, response.body());
            boolean skipAsDone = alreadyExists || passportBlocked;
            // "Appointment not found." means the record the file attaches to is gone (or was never
            // created — a transient failure on POST /appointment). Retrying the upload can never fix
            // that, so escalate to a chain rebuild instead of looping here: the old code retried the
            // upload alone 24 times over 18 minutes, burning a raw captcha solve each round.
            if (isAppointmentNotFound(status, response.body())) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → HTTP " + status
                        + " " + truncate(response.body()), "ERROR");
                return new UploadAttempt(UploadOutcome.APPOINTMENT_MISSING, response.body());
            }
            // IVAC's upload service is shut for everyone. Nothing about this account is wrong, so
            // report it as its own outcome and let the caller back off rather than hammering it.
            // The applicant's IVAC registration has aged out. Permanent for this document: the
            // primary blocks the whole account, a secondary is skipped so the others still finish.
            if (isRegistrationExpired(status, response.body())) {
                if (pdf.isPrimary()) {
                    ConsoleLogger.log(phone, "Primary PDF " + pdf.name() + " → " + truncate(response.body())
                            + " — this applicant's IVAC web registration has expired and cannot be "
                            + "re-uploaded; register again on IVAC and attach the new form on the portal", "ERROR");
                } else {
                    ConsoleLogger.log(phone, "Skipping " + pdf.name() + " → " + truncate(response.body())
                            + " — expired registration; the remaining applicants continue", "WARN");
                    UPLOADED_CACHE.add(cacheKey(pdf));
                }
                return new UploadAttempt(UploadOutcome.REGISTRATION_EXPIRED, response.body());
            }
            // A generic IVAC incident page is a fault on their side — retry it like any 5xx.
            if (isIncidentReport(response.body())) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → IVAC incident page "
                        + truncate(response.body()) + " — server-side fault; will retry", "RETRY");
                return new UploadAttempt(UploadOutcome.FAILED, response.body());
            }
            if (isUploadsClosed(status, response.body())) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → " + truncate(response.body())
                        + " — IVAC has closed uploads; retrying every "
                        + (UPLOADS_CLOSED_RETRY_DELAY_MS / 1000) + "s until they reopen", "RETRY");
                return new UploadAttempt(UploadOutcome.UPLOADS_CLOSED, response.body());
            }
            if (!skipAsDone && (status < 200 || status >= 300)) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → HTTP " + status
                        + " " + truncate(response.body()) + " — will retry", "ERROR");
                return new UploadAttempt(UploadOutcome.FAILED, response.body());
            }
            // A 200 with a non-empty data.error[] is NOT a success — IVAC accepted the request but
            // rejected the file (e.g. the PDF's applicant name/email/phone/passport does not match the
            // account's registered details, or the primary application was not registered). Treating a
            // rejected PRIMARY as done would falsely set pdf_uploaded and cascade into "Primary
            // application not found." on the secondaries, so it stays out of the cache and is re-sent.
            if (!skipAsDone) {
                List<String> validationErrors = extractUploadErrors(response.body());
                if (!validationErrors.isEmpty()) {
                    // A secondary's mismatch is not a rejection to act on: IVAC only validates the form
                    // against the account holder for the primary, and companions are SUPPOSED to be
                    // other people. Count it as uploaded so the step can finish — leaving it out of the
                    // cache is what made the whole chain retry forever on an unfixable difference.
                    if (!pdf.isPrimary()) {
                        UPLOADED_CACHE.add(cacheKey(pdf));
                    }
                    ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → HTTP 200 but IVAC rejected it "
                            + "(data.error not empty)" + (pdf.isPrimary()
                                    ? " — NOT a success; will retry."
                                    : " — a secondary applicant, so counted as uploaded.")
                            + " Mismatches: " + String.join(" | ", validationErrors), "RETRY");
                    // The response states both sides of every comparison, so the caller can decide
                    // between rewriting the form, swapping in the holder's own document, or stopping
                    // for an operator. That decision is made once, at step level, in handleMismatch.
                    return new UploadAttempt(UploadOutcome.MISMATCH, response.body());
                }
            }
            UPLOADED_CACHE.add(cacheKey(pdf));
            if (passportBlocked) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → HTTP 409 " + truncate(response.body())
                        + " — passport is in its post-appointment cooldown; skipping this document and "
                        + "counting it as done so the remaining applicants can finish setup", "WARN");
            } else if (alreadyExists) {
                ConsoleLogger.log(phone, "Upload of " + pdf.name() + " → already exists on IVAC — skipping", "OK");
            } else {
                ConsoleLogger.log(phone, "Uploaded " + pdf.name() + " (primary=" + pdf.isPrimary() + ")", "OK");
            }
            return new UploadAttempt(UploadOutcome.SUCCESS, response.body());
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Upload of " + pdf.name() + " failed: " + e.getMessage()
                    + " — will retry next cycle", "ERROR");
            return UploadAttempt.of(UploadOutcome.FAILED);
        }
    }

    /**
     * Extracts IVAC's per-file validation errors from an upload response body (the JSON array at
     * data.error). A successful upload returns an empty array; a non-empty array means the file was
     * rejected (name/email/phone/passport mismatch, unregistered primary, etc.) even though the HTTP
     * status is 200 and successFlag is true. Returns an empty list when the body is blank or has no
     * such array, so the HTTP status alone still gates success in that case.
     */
    static List<String> extractUploadErrors(String body) {
        List<String> errors = new ArrayList<>();
        if (body == null || body.isBlank()) {
            return errors;
        }
        try {
            JsonObject root = GSON.fromJson(body, JsonObject.class);
            if (root != null && root.has("data") && root.get("data").isJsonObject()) {
                JsonObject data = root.getAsJsonObject("data");
                if (data.has("error") && data.get("error").isJsonArray()) {
                    for (JsonElement element : data.getAsJsonArray("error")) {
                        if (element != null && !element.isJsonNull()) {
                            errors.add(element.getAsString());
                        }
                    }
                }
            }
        } catch (Exception ignored) {
            // Unknown response shape — no parseable validation errors; HTTP status gates success.
        }
        return errors;
    }

    /**
     * The status an upload response really carries. The file service answers HTTP 200 while putting
     * the actual outcome in the body — every upload logged during the July 31 2026 window was an
     * HTTP 200 wrapping {"code":403,"http_status":403,"message":"Uploads are currently closed."}.
     * Judging those by the transport status alone marked all 1587 of them uploaded, cached them,
     * set pdf_uploaded, and moved on to a booking-config that then 404'd — no PDF ever reached IVAC
     * and nothing retried. So the body's own status wins whenever it states one.
     *
     * A body carrying successFlag:true is a genuine success envelope and keeps the transport status,
     * so a success payload that happens to include a "code" field can never be read as a failure.
     * An unparseable or status-less body also falls back to the transport status, leaving the
     * existing behaviour untouched for every response shape that does not do this.
     */
    static int effectiveStatus(int statusCode, String body) {
        if (body == null || body.isBlank()) {
            return statusCode;
        }
        try {
            JsonObject root = GSON.fromJson(body, JsonObject.class);
            if (root == null || isSuccessFlag(body) || isIncidentReport(body)) {
                return statusCode;
            }
            for (String key : new String[]{"http_status", "code"}) {
                if (root.has(key) && !root.get(key).isJsonNull()) {
                    int inner = root.get(key).getAsInt();
                    // Only a plausible HTTP status is trusted — "code" is also used for non-status
                    // application codes elsewhere on this API.
                    if (inner >= 100 && inner <= 599) {
                        return inner;
                    }
                }
            }
        } catch (Exception ignored) {
            // Unknown or non-JSON body — the transport status is all we have.
        }
        return statusCode;
    }

    /**
     * True when IVAC answered with one of its generic incident pages —
     * {"code":9658188,"message":"We have recorded the INCIDENT-ID: 9658188. And we are fixing it!..."}.
     * This is a server-side fault on IVAC's side, transient, and the request should simply be retried.
     *
     * It matters beyond logging: the "code" in that body is an INCIDENT NUMBER, not an HTTP status.
     * Most are far outside the status range and are ignored, but a short incident number (100-599)
     * would otherwise be read as the response's real status by effectiveStatus — a 2xx-looking one
     * would mark a failed upload as successful and cache it. So these bodies never contribute a status.
     */
    static boolean isIncidentReport(String body) {
        return body != null && body.toUpperCase().contains("INCIDENT-ID");
    }

    /**
     * True when the applicant's IVAC web registration has aged out — 400 "Application Registration
     * date 30-JUN-2026 is more than 30 days old". The document can never be accepted again: the
     * applicant has to re-register on IVAC and a fresh PDF has to be attached on the portal. Retrying
     * burns one raw captcha solve per round for nothing (254 such retries across 3 accounts on
     * July 31 2026), so this is classified as permanent rather than transient.
     */
    static boolean isRegistrationExpired(int statusCode, String body) {
        if (body == null) {
            return false;
        }
        String lower = body.toLowerCase();
        return lower.contains("application registration date") && lower.contains("days old");
    }

    /**
     * True when IVAC has closed its upload service outright — 403 "Uploads are currently closed."
     * This is a service-wide gate, not a problem with this account or this document: it clears on
     * IVAC's own schedule, so the upload keeps retrying, just on the slower cadence.
     */
    static boolean isUploadsClosed(int statusCode, String body) {
        return statusCode == 403 && body != null && body.toLowerCase().contains("uploads are currently closed");
    }

    /**
     * True when IVAC refuses a file because that passport already has a successful appointment
     * inside its cooldown — 409 "You can not upload file within 15 days after successful appointment
     * for this passport". This is a permanent per-passport rule for the window, not a transient
     * error: across every logged run, 50 retries on two accounts produced zero successes while each
     * attempt burned a raw captcha solve and held the slot gate shut. The document is therefore
     * skipped and counted as done so the account's remaining applicants can still complete setup.
     *
     * Matched on the stable phrase as well as the day count, so a changed cooldown length still
     * classifies correctly.
     */
    static boolean isRecentAppointmentBlock(int statusCode, String body) {
        if (statusCode != 409 || body == null) {
            return false;
        }
        String lower = body.toLowerCase();
        return lower.contains("after successful appointment") || lower.contains("within 15 days");
    }

    private String cacheKey(PreparedPdf pdf) {
        return account.getId() + ":" + sha256(pdf.base64());
    }

    private static String sha256(String value) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(value.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder(hash.length * 2);
            for (byte b : hash) {
                sb.append(Character.forDigit((b >> 4) & 0xF, 16));
                sb.append(Character.forDigit(b & 0xF, 16));
            }
            return sb.toString();
        } catch (NoSuchAlgorithmException e) {
            // SHA-256 is always available; fall back to identity so uploads still dedupe by content.
            return Integer.toHexString(value.hashCode());
        }
    }

    private StepResult configureBookingAttempt() {
        String phone = account.getPhone();
        if (account.isBookingConfigured()) {
            return StepResult.DONE;
        }

        List<BookingCityOption> candidates = bookingCityCandidates();
        if (candidates.isEmpty()) {
            ConsoleLogger.log(phone, "No booking city configured — slot reserve will fail "
                    + "until a city is set on the portal", "WARN");
            return StepResult.GIVE_UP;
        }

        int index = bookingCityCursor.get() % candidates.size();
        BookingCityOption candidate = candidates.get(index);
        String mission = candidate.mission();
        String ivacCenter = candidate.ivacCenter();

        try {
            IvacHttpClient.RawResponse response = client.postRawNoAuthRetry(
                    bookingConfigPath(), Map.of("mission", mission, "ivacCenter", ivacCenter));
            if (response.statusCode() < 200 || response.statusCode() >= 300) {
                // 404 "Appointment not found." means IVAC no longer has the appointment record the
                // PDFs were uploaded against (a session/window reset dropped it). Retrying booking-config
                // alone would 404 forever; the whole chain must be rebuilt — re-create the appointment
                // and re-upload the PDFs first. Signal that to the orchestrator loop.
                if (isAppointmentNotFound(response.statusCode(), response.body())) {
                    ConsoleLogger.log(phone, "Booking config → HTTP 404 Appointment not found — IVAC "
                            + "appointment/PDFs were reset; re-creating appointment and re-uploading PDFs", "RETRY");
                    return StepResult.RESET_CHAIN;
                }
                if (isInvalidHighCommission(response.body())) {
                    return rotateBookingCity(phone, candidates, mission);
                }
                ConsoleLogger.log(phone, "Booking config → HTTP " + response.statusCode()
                        + " " + truncate(response.body()) + " — will retry", "RETRY");
                return StepResult.RETRY;
            }
            ConsoleLogger.log(phone, "Booking config set (" + mission + " / " + ivacCenter + ")", "OK");
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Booking config failed: " + e.getMessage() + " — will retry", "RETRY");
            return StepResult.RETRY;
        }

        account.setBookingConfigured(true);
        reportBookingCity(phone, candidate, index);
        portalSetup.markBookingConfigured(phone);

        // Booking config is now set, so get-booking-config can return the appointmentId AND the real
        // available appointmentDate[] array. That fetch is deliberately NOT made here: it is the last
        // thing standing between this account and its first reserve-slot shot, and reserve already has
        // the portal's configured dates to fire with. prepareForBooking starts it in the background
        // instead, so the slot gate opens the moment booking config lands.
        return StepResult.DONE;
    }

    /**
     * The high commissions this account will try, cheapest guess first: its configured centre, then
     * every other one the portal offers. Built once per service instance so the rotation cursor keeps
     * its place across retry rounds.
     */
    private List<BookingCityOption> bookingCityCandidates() {
        List<BookingCityOption> cached = bookingCityCandidates;
        if (cached == null) {
            cached = BookingCityOption.orderedFor(account.getBookingMission(), account.getBookingIvacCenter(),
                    appConfig != null ? appConfig.getBookingCityOptions() : BookingCityOption.DEFAULTS);
            bookingCityCandidates = cached;
        }
        return cached;
    }

    /**
     * Moves the rotation on to the next high commission after IVAC rejected the current one, and says
     * how soon to retry. The first pass through the list retries immediately (RETRY_FAST): booking
     * config gates the whole slot race, and sleeping 2s per centre would cost the window. Once every
     * centre has been rejected once the rotation keeps cycling but at the normal pace, so a
     * server-side glitch answering "Invalid High Commission." for everything cannot become a hot loop.
     */
    private StepResult rotateBookingCity(String phone, List<BookingCityOption> candidates, String rejected) {
        if (candidates.size() == 1) {
            ConsoleLogger.log(phone, "Booking config → 400 Invalid High Commission (" + rejected
                    + ") and no other centre is available — will retry", "RETRY");
            return StepResult.RETRY;
        }

        int attempts = bookingCityCursor.incrementAndGet();
        BookingCityOption next = candidates.get(attempts % candidates.size());
        boolean firstPass = attempts < candidates.size();

        ConsoleLogger.log(phone, "Booking config → 400 Invalid High Commission (" + rejected
                + ") — trying " + next.mission() + " instead", "RETRY");

        if (!firstPass && attempts % candidates.size() == 0) {
            ConsoleLogger.log(phone, "All " + candidates.size() + " high commissions rejected by IVAC "
                    + "— cycling again; check the applicant's web file if this persists", "WARN");
        }

        return firstPass ? StepResult.RETRY_FAST : StepResult.RETRY;
    }

    /**
     * Persists the centre that actually worked when it is not the one the operator picked, so the
     * portal shows where the account is really booked and the next window starts on it instead of
     * walking the rotation again. Skipped for the configured centre (nothing changed) and for a pair
     * the portal list does not name (no city key to store).
     */
    private void reportBookingCity(String phone, BookingCityOption candidate, int index) {
        if (index == 0 || candidate.city() == null || candidate.city().isBlank()) {
            return;
        }
        ConsoleLogger.log(phone, "Booking city corrected to " + candidate.city()
                + " — IVAC rejected the configured centre", "OK");
        portalSetup.reportBookingCity(phone, candidate.city());
    }

    /**
     * True when IVAC rejected the booking config because the mission does not match the applicant's
     * own web file — {"http_status":400,"message":"Invalid High Commission."}. Retrying the same pair
     * can never clear it, so this is what drives the rotation onto the next centre.
     */
    static boolean isInvalidHighCommission(String body) {
        return body != null && body.toLowerCase().contains("invalid high commission");
    }

    /**
     * True when a response signals IVAC has no appointment record. In that state the
     * create → upload → booking-config chain must be rebuilt, not merely retried at the failing step.
     *
     * The 404 can arrive two ways, and both must match:
     *   - as the HTTP status — upload/booking-config return 404 {"http_status":404,"message":"Appointment not found."}
     *   - inside an HTTP 200 envelope — get-booking-config returns 200 with a body of
     *     {"code":404,"http_status":404,"message":"Appointment not found."} until booking-config posts
     *
     * The 404 marker is required so an unrelated status carrying the phrase is not misread as a reset.
     */
    static boolean isAppointmentNotFound(int statusCode, String body) {
        if (body == null || !body.toLowerCase().contains("appointment not found")) {
            return false;
        }
        if (statusCode == 404) {
            return true;
        }
        String compact = body.replace(" ", "");
        return compact.contains("\"http_status\":404") || compact.contains("\"code\":404");
    }

    /**
     * Clears the per-account setup state so a chain rebuild actually re-runs create + upload. The
     * process-wide UPLOADED_CACHE (keyed "accountId:sha256") and the pdf_uploaded flag would otherwise
     * make uploadPdfsAttempt skip every PDF, leaving the re-created appointment with no documents.
     * The stale appointmentId must also be cleared: create/refresh only CAPTURE an id when none is
     * set, so without this the re-created appointment's fresh id would be dropped and payment would
     * send the dead one (→ 404 "Appointment not found."), defeating the rebuild.
     * booking_configured is already false here (we only rebuild before it is set).
     */
    private void invalidateSetupForRebuild() {
        resetChainState(account, UPLOADED_CACHE);
    }

    /**
     * Resets the per-account state that would otherwise make a chain rebuild a no-op: the uploaded
     * cache entries for this account (prefix "accountId:"), the pdf_uploaded flag, and the stale
     * appointmentId (create/refresh only capture an id when none is set). Package-private + static
     * so it is unit-testable without constructing the full service.
     */
    static void resetChainState(AccountConfig account, Set<String> uploadedCache) {
        String prefix = account.getId() + ":";
        uploadedCache.removeIf(key -> key.startsWith(prefix));
        account.setPdfUploaded(false);
        account.setAppointmentId(null);
    }

    /**
     * One GET /appointment/get-booking-config round trip. Captures the appointmentId (so payment
     * never needs its own fallback fetch) and IVAC's own appointmentDate[] array (which replaces the
     * portal date range for reserve-slot and is written back to the portal).
     *
     * Returns true only once the dates are in hand — that is the poll's terminal success. Everything
     * else (transport error, non-200, the HTTP 200 wrapping 404 "Appointment not found." that IVAC
     * answers until appointment-booking-config has propagated, a payload with no dates) returns false
     * so the caller polls again. Never throws.
     *
     * {@code logFailure} throttles the failure log: the poll runs alongside the live race, so only
     * some rounds report, but a captured result always logs.
     */
    private boolean refreshBookingConfigOnce(String phone, String reason, boolean logFailure) {
        try {
            IvacHttpClient.RawResponse raw = client.getRaw(getBookingConfigPath());
            if (raw.statusCode() != 200 || raw.body() == null) {
                if (logFailure) {
                    ConsoleLogger.log(phone, "get-booking-config (" + reason + ") → HTTP "
                            + raw.statusCode() + " — retrying", "RETRY");
                }
                return false;
            }
            if (isAppointmentNotFound(raw.statusCode(), raw.body())) {
                if (logFailure) {
                    ConsoleLogger.log(phone, "get-booking-config (" + reason + ") → HTTP 200 wrapping "
                            + "404 Appointment not found — booking config not visible on IVAC yet, retrying", "RETRY");
                }
                return false;
            }
            JsonObject root = GSON.fromJson(raw.body(), JsonObject.class);
            if (root == null || !root.has("data") || !root.get("data").isJsonObject()) {
                if (logFailure) {
                    ConsoleLogger.log(phone, "get-booking-config (" + reason + ") → no data object "
                            + truncate(raw.body()) + " — retrying", "RETRY");
                }
                return false;
            }
            JsonObject data = root.getAsJsonObject("data");

            if ((account.getAppointmentId() == null || account.getAppointmentId().isBlank())
                    && data.has("appointmentId") && !data.get("appointmentId").isJsonNull()) {
                String id = data.get("appointmentId").getAsString();
                if (id != null && !id.isBlank()) {
                    account.setAppointmentId(id);
                    ConsoleLogger.log(phone, "appointmentId captured (" + reason + "): " + id, "OK");
                }
            }

            List<String> dates = extractAppointmentDates(data);
            if (dates.isEmpty()) {
                if (logFailure) {
                    ConsoleLogger.log(phone, "get-booking-config (" + reason + ") → no appointmentDate[] "
                            + "in payload — retrying", "RETRY");
                }
                return false;
            }
            applyIvacAppointmentDates(phone, reason, dates);
            return true;
        } catch (Exception e) {
            if (logFailure) {
                ConsoleLogger.log(phone, "get-booking-config (" + reason + ") failed: " + e.getMessage()
                        + " — retrying", "RETRY");
            }
            return false;
        }
    }

    /**
     * Hands IVAC's own available dates to reserve-slot and mirrors them onto the portal account, so
     * the next window starts from the real list instead of the operator's guessed range.
     *
     * Skipped when the dates are unchanged: setIvacAppointmentDates swaps the list the rotation reads,
     * which restarts its shuffled cycle — re-applying an identical list mid-race would keep resetting
     * that cycle and let the same few dates be retried while others go untried.
     */
    private void applyIvacAppointmentDates(String phone, String reason, List<String> dates) {
        if (!shouldReplaceDates(dates, account.getIvacAppointmentDates())) {
            return;
        }
        account.setIvacAppointmentDates(dates);
        ConsoleLogger.log(phone, "IVAC appointment dates captured (" + reason + "): " + dates, "OK");
        portalSetup.updateAppointmentDates(phone, dates);
    }

    /**
     * True when {@code incoming} carries dates worth applying — non-empty and different from what the
     * account already rotates. Package-private for the rotation-reset regression test.
     */
    static boolean shouldReplaceDates(List<String> incoming, List<String> current) {
        return incoming != null && !incoming.isEmpty() && !incoming.equals(current);
    }

    /**
     * Extracts the non-blank date strings from a get-booking-config data object's appointmentDate[]
     * array. Returns an empty list when the field is missing, not an array, or has no usable dates.
     */
    static List<String> extractAppointmentDates(JsonObject data) {
        List<String> out = new ArrayList<>();
        if (data.has("appointmentDate") && data.get("appointmentDate").isJsonArray()) {
            for (var element : data.getAsJsonArray("appointmentDate")) {
                if (element != null && !element.isJsonNull()) {
                    String value = element.getAsString();
                    if (value != null && !value.isBlank()) {
                        out.add(value);
                    }
                }
            }
        }
        return out;
    }

    /**
     * Returns a raw captcha token for an upload, preferring a token prefetched during the OTP-verify
     * wait (already solving or solved) over a fresh cold solve. A prefetched solve that came back
     * empty or failed falls through to a fresh solve so a single bad prefetch never loses an upload;
     * a fresh solve is also used once the pool is drained (e.g. on retries). Returns null when no
     * token can be obtained (the caller retries next cycle).
     */
    private String fetchRawCaptcha() {
        CompletableFuture<CaptchaToken> prefetched = rawCaptchaPrefetch.poll();
        if (prefetched != null) {
            String token = tokenOrNull(prefetched, captchaShelfLifeMs);
            if (token != null) {
                return token;
            }
            // Prefetched solve failed or aged past the captcha shelf life — fall through to a fresh solve.
        }
        return tokenOrNull(portalCaptcha.requestCaptcha("raw", account.getPhone()), captchaShelfLifeMs);
    }

    /**
     * Awaits a captcha future and returns its token, or null when the solve failed, was interrupted,
     * produced an empty token, or the token has aged past {@code shelfLifeMs}. The shelf-life check
     * mirrors the sign-in flow so a prefetched token that went stale during the OTP-verify wait is
     * re-solved instead of uploaded. Package-private for testing the prefetch fallback.
     */
    static String tokenOrNull(CompletableFuture<CaptchaToken> future, long shelfLifeMs) {
        try {
            CaptchaToken token = future.get();
            if (token == null || token.getToken() == null || token.getToken().isBlank()) {
                return null;
            }
            if (token.isOlderThan(shelfLifeMs)) {
                return null;
            }
            return token.getToken();
        } catch (Exception e) {
            if (e instanceof InterruptedException) {
                Thread.currentThread().interrupt();
            }
            return null;
        }
    }

    private static String truncate(String body) {
        if (body == null) {
            return "";
        }
        return body.length() > 200 ? body.substring(0, 200) : body;
    }
}
