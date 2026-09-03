package com.ivac.booking.service.setup;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * The upload service answers HTTP 200 for almost everything and puts the real outcome in the body,
 * so these classifiers decide whether a document is retried, skipped, or abandoned. Bodies are
 * verbatim from the July 31 2026 window.
 */
class UploadResponseClassificationTest {

    private static final String INCIDENT =
            "{\"code\":9658188,\"message\":\"We have recorded the INCIDENT-ID: 9658188. And we are "
                    + "fixing it!. Please come back in a while or try to reload the page.\"}";
    private static final String EXPIRED =
            "{\"code\":400,\"http_status\":400,\"message\":\"Application Registration date 30-JUN-2026 "
                    + "is more than 30 days old\"}";

    @Test
    @DisplayName("an incident page is recognised and retried, never read as an outcome")
    void detectsIncidentPage() {
        assertTrue(AccountSetupService.isIncidentReport(INCIDENT));
        assertFalse(AccountSetupService.isIncidentReport("{\"message\":\"Success\"}"));
        assertFalse(AccountSetupService.isIncidentReport(null));
    }

    @Test
    @DisplayName("a short incident number is never mistaken for an HTTP status")
    void incidentNumberIsNotAStatus() {
        // The danger case: an incident id that happens to land inside the HTTP status range would
        // otherwise be returned as the response's real status — a 2xx one would mark a failed
        // upload successful and cache it forever.
        String shortIncident = "{\"code\":204,\"message\":\"We have recorded the INCIDENT-ID: 204. "
                + "And we are fixing it!.\"}";

        assertEquals(400, AccountSetupService.effectiveStatus(400, shortIncident));
        assertEquals(400, AccountSetupService.effectiveStatus(400, INCIDENT));
    }

    @Test
    @DisplayName("an expired web registration is permanent, not a transient error")
    void detectsExpiredRegistration() {
        assertTrue(AccountSetupService.isRegistrationExpired(400, EXPIRED));
        assertTrue(AccountSetupService.isRegistrationExpired(400,
                "{\"message\":\"Application Registration date 29-JUN-2026 is more than 30 days old\"}"));
        assertFalse(AccountSetupService.isRegistrationExpired(400, "{\"message\":\"Success\"}"));
        assertFalse(AccountSetupService.isRegistrationExpired(400, null));
    }

    @Test
    @DisplayName("a body that states its own status still wins over the transport status")
    void bodyStatusStillWins() {
        assertEquals(403, AccountSetupService.effectiveStatus(200,
                "{\"code\":403,\"http_status\":403,\"message\":\"Uploads are currently closed.\"}"));
        assertEquals(200, AccountSetupService.effectiveStatus(200,
                "{\"data\":{\"error\":[]},\"statusCode\":200,\"successFlag\":true}"));
    }

    @Test
    @DisplayName("IVAC's data.error[] is extracted verbatim")
    void extractsValidationErrors() {
        String body = "{\"data\":{\"overview\":{},\"error\":[\"Name mismatch: User name 'A' does not "
                + "match file details name 'B'\"]},\"statusCode\":200,\"successFlag\":true}";

        assertEquals(1, AccountSetupService.extractUploadErrors(body).size());
        assertTrue(AccountSetupService.extractUploadErrors(
                "{\"data\":{\"error\":[]}}").isEmpty());
        assertTrue(AccountSetupService.extractUploadErrors("not json").isEmpty());
    }
}
