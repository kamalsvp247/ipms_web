package com.ivac.booking.service.setup;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.ivac.booking.config.AccountConfig;
import com.ivac.booking.model.domain.CaptchaToken;
import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;
import java.util.Base64;
import java.util.List;
import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.jupiter.api.Assertions.assertArrayEquals;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the primary-applicant selection that drives upload ordering and the ahead-of-time payload
 * decode. IVAC rejects secondary applicant uploads with 404 "Primary application not found." until
 * the primary is registered, so the primary must be uploaded first — findPrimary picks which PDF
 * that is; decodePdf turns the portal Base64 into ready bytes off the OTP-verify critical path.
 */
class AccountSetupServiceTest {

    private static AccountSetupService.PreparedPdf pdf(String name, boolean primary) {
        return new AccountSetupService.PreparedPdf(name, primary, new byte[0], "");
    }

    @Test
    void picksTheFlaggedPrimaryRegardlessOfPosition() {
        List<AccountSetupService.PreparedPdf> pdfs = List.of(
            pdf("secondary-a.pdf", false),
            pdf("primary.pdf", true),
            pdf("secondary-b.pdf", false));

        assertEquals("primary.pdf", AccountSetupService.findPrimary(pdfs).name());
    }

    @Test
    void fallsBackToFirstWhenNoneFlagged() {
        List<AccountSetupService.PreparedPdf> pdfs = List.of(
            pdf("first.pdf", false),
            pdf("second.pdf", false));

        assertEquals("first.pdf", AccountSetupService.findPrimary(pdfs).name());
    }

    @Test
    void returnsNullForEmptyList() {
        assertNull(AccountSetupService.findPrimary(List.of()));
    }

    @Test
    void extractsUploadValidationErrorsFromNonEmptyErrorArray() {
        String body = "{\"data\":{\"overview\":{\"primary\":true},\"error\":["
            + "\"Name mismatch: User name 'A' does not match file details name 'B'\","
            + "\"Passport number mismatch: User passport #'X' does not match file details passport # 'Y'\""
            + "]},\"statusCode\":200,\"successFlag\":true}";

        List<String> errors = AccountSetupService.extractUploadErrors(body);

        assertEquals(2, errors.size());
        assertTrue(errors.get(0).startsWith("Name mismatch"));
    }

    @Test
    void treatsEmptyErrorArrayAsNoValidationErrors() {
        String body = "{\"data\":{\"overview\":{\"primary\":true},\"error\":[]}}";

        assertTrue(AccountSetupService.extractUploadErrors(body).isEmpty());
    }

    @Test
    void returnsNoErrorsWhenBodyBlankOrMissingErrorArray() {
        assertTrue(AccountSetupService.extractUploadErrors("").isEmpty());
        assertTrue(AccountSetupService.extractUploadErrors(null).isEmpty());
        assertTrue(AccountSetupService.extractUploadErrors("{\"data\":{\"overview\":{}}}").isEmpty());
        assertTrue(AccountSetupService.extractUploadErrors("not-json").isEmpty());
    }

    @Test
    void detectsAppointmentNotFoundOn404WithMatchingBody() {
        assertTrue(AccountSetupService.isAppointmentNotFound(404,
            "{\"http_status\":404,\"message\":\"Appointment not found.\"}"));
        assertTrue(AccountSetupService.isAppointmentNotFound(404,
            "{\"message\":\"APPOINTMENT NOT FOUND\"}"));
    }

    @Test
    void doesNotTreatOther404sOrStatusesAsAppointmentReset() {
        assertFalse(AccountSetupService.isAppointmentNotFound(404,
            "{\"message\":\"Primary application not found.\"}"));
        assertFalse(AccountSetupService.isAppointmentNotFound(400,
            "{\"message\":\"Appointment not found.\"}"));
        assertFalse(AccountSetupService.isAppointmentNotFound(404, null));
    }

    /**
     * get-booking-config answers with HTTP 200 wrapping a 404 body until appointment-booking-config
     * has posted. A status-only check reads that as success and hides a broken chain.
     */
    @Test
    void detectsAppointmentNotFoundInsideAnHttp200Envelope() {
        assertTrue(AccountSetupService.isAppointmentNotFound(200,
            "{\"code\":404,\"http_status\":404,\"message\":\"Appointment not found.\"}"));
        assertTrue(AccountSetupService.isAppointmentNotFound(200,
            "{\"code\": 404, \"message\": \"Appointment not found.\"}"));
    }

    @Test
    void treatsAGetBookingConfigPayloadWithRealDataAsPresent() {
        assertFalse(AccountSetupService.isAppointmentNotFound(200,
            "{\"data\":{\"appointmentDate\":[\"2026-07-26\"],\"appointmentId\":\"f74967a1\"},"
                + "\"statusCode\":200,\"message\":\"Success\",\"successFlag\":true}"));
    }

    /**
     * Under booking-window load IVAC answers POST /appointment with a transient
     * 400 INCIDENT-ID body. Accepting that as created leaves no appointment record, so every
     * following upload 404s "Appointment not found." — only successFlag:true means created.
     */
    @Test
    void onlyASuccessFlagBodyCountsAsCreated() {
        assertTrue(AccountSetupService.isSuccessFlag(
            "{\"data\":null,\"statusCode\":200,\"message\":\"Success\",\"successFlag\":true,"
                + "\"serverTime\":\"2026-07-23T07:16:36.121626129Z\"}"));
        assertFalse(AccountSetupService.isSuccessFlag(
            "{\"code\":45954519,\"message\":\"We have recorded the INCIDENT-ID: 45954519.\"}"));
        assertFalse(AccountSetupService.isSuccessFlag("{\"successFlag\":false}"));
        assertFalse(AccountSetupService.isSuccessFlag("not-json"));
        assertFalse(AccountSetupService.isSuccessFlag(""));
        assertFalse(AccountSetupService.isSuccessFlag(null));
    }

    /**
     * A passport inside its post-appointment cooldown can never upload during this window, so the
     * document is skipped and counted as done rather than retried — verified against the logs, where
     * 50 retries across two accounts produced zero successes.
     */
    @Test
    void detectsThePostAppointmentPassportCooldownBlock() {
        assertTrue(AccountSetupService.isRecentAppointmentBlock(409,
            "{\"http_status\":409,\"message\":\"You can not upload file within 15 days "
                + "after successful appointment for this passport\"}"));
        // A changed cooldown length must still classify as a block.
        assertTrue(AccountSetupService.isRecentAppointmentBlock(409,
            "{\"http_status\":409,\"message\":\"You can not upload file within 30 days "
                + "after successful appointment for this passport\"}"));
    }

    @Test
    void doesNotTreatOther409sOrStatusesAsAPassportCooldown() {
        assertFalse(AccountSetupService.isRecentAppointmentBlock(409,
            "{\"http_status\":409,\"message\":\"File already exists.\"}"));
        assertFalse(AccountSetupService.isRecentAppointmentBlock(400,
            "{\"message\":\"You can not upload file within 15 days after successful appointment\"}"));
        assertFalse(AccountSetupService.isRecentAppointmentBlock(409, null));
    }

    @Test
    void treatsOnlyAnExplicitAlreadyExistsAsATerminalCreateSuccess() {
        assertTrue(AccountSetupService.isAlreadyExists(409, "{\"message\":\"File already exists.\"}"));
        assertTrue(AccountSetupService.isAlreadyExists(400, "{\"message\":\"Appointment already exists\"}"));
        assertFalse(AccountSetupService.isAlreadyExists(400,
            "{\"code\":45954519,\"message\":\"We have recorded the INCIDENT-ID: 45954519.\"}"));
        assertFalse(AccountSetupService.isAlreadyExists(404, null));
    }

    @Test
    void decodesBase64PayloadAheadOfUpload() {
        String base64 = Base64.getEncoder().encodeToString("PDFBYTES".getBytes(StandardCharsets.UTF_8));

        AccountSetupService.PreparedPdf prepared =
            AccountSetupService.decodePdf(new PortalSetupClient.PdfDoc("a.pdf", base64, true));

        assertEquals("a.pdf", prepared.name());
        assertTrue(prepared.isPrimary());
        assertArrayEquals("PDFBYTES".getBytes(StandardCharsets.UTF_8), prepared.bytes());
    }

    @Test
    void keepsNullBytesForMalformedBase64() {
        AccountSetupService.PreparedPdf prepared =
            AccountSetupService.decodePdf(new PortalSetupClient.PdfDoc("bad.pdf", "!!!not base64!!!", false));

        assertNull(prepared.bytes());
        assertEquals("bad.pdf", prepared.name());
    }

    @Test
    void extractsNonBlankDatesFromBookingConfigArray() {
        JsonObject data = JsonParser.parseString(
            "{\"appointmentDate\":[\"2026-07-13\",\"2026-07-14\",\"\",null,\"2026-07-15\"]}").getAsJsonObject();

        assertEquals(List.of("2026-07-13", "2026-07-14", "2026-07-15"),
            AccountSetupService.extractAppointmentDates(data));
    }

    @Test
    void extractsEmptyWhenArrayMissingOrNotArray() {
        JsonObject noField = JsonParser.parseString("{}").getAsJsonObject();
        JsonObject notArray = JsonParser.parseString("{\"appointmentDate\":\"2026-07-13\"}").getAsJsonObject();

        assertTrue(AccountSetupService.extractAppointmentDates(noField).isEmpty());
        assertTrue(AccountSetupService.extractAppointmentDates(notArray).isEmpty());
    }

    /**
     * The dates poll runs alongside the live slot race, so its loop must stop the moment the race
     * ends rather than keep hitting IVAC for the rest of the JWT's life.
     */
    @Test
    void pollStopsOnFirstSuccessAndReportsIt() {
        AtomicInteger attempts = new AtomicInteger();

        boolean captured = AccountSetupService.pollUntilSuccess(
            System.currentTimeMillis() + 10_000L, 1L, () -> false,
            () -> attempts.incrementAndGet() == 3);

        assertTrue(captured);
        assertEquals(3, attempts.get(), "poll must stop at the first success, not keep going");
    }

    @Test
    void pollDoesNotStartAnAttemptOnceTheRaceIsOver() {
        AtomicInteger attempts = new AtomicInteger();

        boolean captured = AccountSetupService.pollUntilSuccess(
            System.currentTimeMillis() + 10_000L, 1L, () -> true,
            () -> {
                attempts.incrementAndGet();
                return false;
            });

        assertFalse(captured);
        assertEquals(0, attempts.get(), "an already-over race must not fire a single request");
    }

    @Test
    void pollAbortsBetweenRoundsWhenTheRaceEnds() {
        AtomicInteger attempts = new AtomicInteger();

        boolean captured = AccountSetupService.pollUntilSuccess(
            System.currentTimeMillis() + 10_000L, 1L, () -> attempts.get() >= 2,
            () -> {
                attempts.incrementAndGet();
                return false;
            });

        assertFalse(captured);
        assertEquals(2, attempts.get(), "poll must stop at the round after the race ends");
    }

    @Test
    void pollGivesUpAtItsDeadlineWithoutOverrunningIt() {
        AtomicInteger attempts = new AtomicInteger();
        long deadline = System.currentTimeMillis() + 40L;

        boolean captured = AccountSetupService.pollUntilSuccess(deadline, 30L, () -> false,
            () -> {
                attempts.incrementAndGet();
                return false;
            });

        assertFalse(captured);
        assertTrue(System.currentTimeMillis() <= deadline + 30L, "poll must not sleep past its deadline");
        assertTrue(attempts.get() >= 1, "poll must try at least once before the deadline");
    }

    @Test
    void pollDoesNothingWhenTheDeadlineHasAlreadyPassed() {
        AtomicInteger attempts = new AtomicInteger();

        assertFalse(AccountSetupService.pollUntilSuccess(System.currentTimeMillis() - 1L, 1L, () -> false,
            () -> {
                attempts.incrementAndGet();
                return true;
            }));
        assertEquals(0, attempts.get());
    }

    /**
     * Applying IVAC's dates swaps the list the reserve rotation reads, which restarts its shuffled
     * cycle. Re-applying an identical list on a later poll would keep resetting that cycle, so only
     * a genuine change may be applied.
     */
    @Test
    void replacesDatesOnlyWhenTheyActuallyChanged() {
        List<String> dates = List.of("2026-07-26", "2026-07-27");

        assertTrue(AccountSetupService.shouldReplaceDates(dates, null),
            "first capture must apply");
        assertTrue(AccountSetupService.shouldReplaceDates(dates, List.of()),
            "empty current list must be replaced");
        assertTrue(AccountSetupService.shouldReplaceDates(dates, List.of("2026-07-26")),
            "a changed list must be applied");
        assertFalse(AccountSetupService.shouldReplaceDates(dates, List.of("2026-07-26", "2026-07-27")),
            "an identical list must not reset the rotation cycle");
        assertFalse(AccountSetupService.shouldReplaceDates(List.of(), dates),
            "an empty payload must never wipe captured dates");
        assertFalse(AccountSetupService.shouldReplaceDates(null, dates));
    }

    @Test
    void chainRebuildResetClearsStaleAppointmentIdUploadFlagAndCache() {
        AccountConfig account = new AccountConfig();
        account.setAppointmentId("dead-appointment-id");
        account.setPdfUploaded(true);

        // id defaults to 0, so this account's cache prefix is "0:"; a different account's rows must survive.
        Set<String> cache = ConcurrentHashMap.newKeySet();
        cache.add("0:hashA");
        cache.add("0:hashB");
        cache.add("7:hashC");

        AccountSetupService.resetChainState(account, cache);

        assertNull(account.getAppointmentId(),
            "stale appointmentId must be cleared so the re-created appointment's fresh id is captured");
        assertFalse(account.isPdfUploaded(), "pdf_uploaded must reset so uploads actually re-run");
        assertEquals(Set.of("7:hashC"), cache, "only this account's cache rows should be removed");
    }

    @Test
    void returnsFreshPrefetchedCaptchaToken() {
        CompletableFuture<CaptchaToken> future =
            CompletableFuture.completedFuture(new CaptchaToken("x-token-abc", System.currentTimeMillis()));

        assertEquals("x-token-abc", AccountSetupService.tokenOrNull(future, 20_000L));
    }

    @Test
    void rejectsPrefetchedCaptchaTokenPastItsShelfLife() {
        // Solved 30s ago against a 20s shelf life — stale, so the upload re-solves rather than
        // uploading an expired token (mirrors the sign-in captcha refresh).
        CompletableFuture<CaptchaToken> future = CompletableFuture.completedFuture(
            new CaptchaToken("x-token-stale", System.currentTimeMillis() - 30_000L));

        assertNull(AccountSetupService.tokenOrNull(future, 20_000L));
    }

    /**
     * IVAC rejects a booking config whose mission does not match the applicant's own web file. The
     * same pair can never start working, so this is what moves the rotation onto the next centre.
     */
    @Test
    void detectsTheInvalidHighCommissionRejection() {
        assertTrue(AccountSetupService.isInvalidHighCommission(
            "{\"http_status\":400,\"message\":\"Invalid High Commission.\"}"));
        assertTrue(AccountSetupService.isInvalidHighCommission(
            "{\"message\":\"INVALID HIGH COMMISSION\"}"));
    }

    @Test
    void doesNotTreatOtherRejectionsAsAnInvalidHighCommission() {
        assertFalse(AccountSetupService.isInvalidHighCommission(
            "{\"http_status\":400,\"message\":\"Invalid IVAC Center.\"}"));
        assertFalse(AccountSetupService.isInvalidHighCommission(
            "{\"http_status\":404,\"message\":\"Appointment not found.\"}"));
        assertFalse(AccountSetupService.isInvalidHighCommission(null));
    }

    @Test
    void rejectsBlankOrFailedCaptchaSolve() {
        assertNull(AccountSetupService.tokenOrNull(
            CompletableFuture.completedFuture(new CaptchaToken("   ", System.currentTimeMillis())), 20_000L));
        assertNull(AccountSetupService.tokenOrNull(
            CompletableFuture.failedFuture(new RuntimeException("solve failed")), 20_000L));
    }
}
