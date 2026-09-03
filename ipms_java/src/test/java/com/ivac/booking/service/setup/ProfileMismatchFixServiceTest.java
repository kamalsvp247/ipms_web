package com.ivac.booking.service.setup;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.service.setup.ProfileMismatchFixService.Assessment;
import com.ivac.booking.service.setup.ProfileMismatchFixService.Verdict;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertArrayEquals;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Covers the decision that decides whether the bot rewrites the applicant form, swaps a document, or
 * stops. Every case here is a real response captured on July 31 2026.
 */
class ProfileMismatchFixServiceTest {

    private static final String NAME =
            "Name mismatch: User name 'KHANAM RIA' does not match file details name 'RIA KHANAM'";
    private static final String NAME_OTHER_PERSON =
            "Name mismatch: User name 'GOUR PRASANNA DAS' does not match file details name 'RINA DAS'";
    private static final String PASSPORT =
            "Passport number mismatch: User passport #'A05963080' does not match file details passport # 'A05780100'";
    private static final String NID =
            "Citizenship /National ID No mismatch: User Citizenship /National ID No #'7316339865' "
                    + "does not match file details Citizenship /National ID No # '1594314326798'";
    private static final String NID_AL_AMIN =
            "Citizenship /National ID No mismatch: User Citizenship /National ID No #'2350180325' "
                    + "does not match file details Citizenship /National ID No # '5128865143'";
    private static final String EMAIL =
            "Email mismatch: User email 'iva.cd.h.k012@gmail.com' does not match file details email "
                    + "'SHOEKAT1986@GMAIL.COM'";
    private static final String PHONE =
            "Phone mismatch: User phone '01350918207' does not match file details phone '01964131095'";
    private static final String DOB =
            "Date of birth mismatch: User date of birth '15-OCT-2001' does not match file details "
                    + "date of birth '15-OCT-2002'";

    @Test
    @DisplayName("name only — the form is rewritten to match the account (01781845585)")
    void nameOnlyIsFixable() {
        Assessment assessment = ProfileMismatchFixService.assess(List.of(NAME));

        assertEquals(Verdict.FIX_PDF, assessment.verdict());
        assertEquals("KHANAM RIA", assessment.find(ProfileMismatchFixService.FIELD_NAME).accountValue());
        assertEquals("RIA KHANAM", assessment.find(ProfileMismatchFixService.FIELD_NAME).fileValue());
    }

    @Test
    @DisplayName("NID only — a mistyped number is corrected too (01775835792)")
    void nidOnlyIsFixable() {
        assertEquals(Verdict.FIX_PDF, ProfileMismatchFixService.assess(List.of(NID_AL_AMIN)).verdict());
    }

    @Test
    @DisplayName("name + passport + NID — a different applicant's form (01754829815)")
    void wrongDocumentIsNotRewritten() {
        Assessment assessment =
                ProfileMismatchFixService.assess(List.of(NAME_OTHER_PERSON, PASSPORT, NID));

        assertEquals(Verdict.WRONG_DOCUMENT, assessment.verdict());
        // The holder's own passport is what identifies their form among the account's documents.
        assertEquals("A05963080", assessment.accountPassport());
    }

    @Test
    @DisplayName("email/phone are repairable now — the form is corrected, not the account (01350918207)")
    void contactDetailsAreRepairedOnTheForm() {
        assertEquals(Verdict.FIX_PDF, ProfileMismatchFixService.assess(List.of(EMAIL, PHONE)).verdict());
        assertEquals(Verdict.FIX_PDF, ProfileMismatchFixService.assess(List.of(EMAIL)).verdict());
        // And alongside another writable field.
        assertEquals(Verdict.FIX_PDF, ProfileMismatchFixService.assess(List.of(NAME, PHONE)).verdict());
    }

    @Test
    @DisplayName("date of birth is the one validated field the form editor cannot place")
    void dobStillNeedsAnOperator() {
        assertEquals(Verdict.MANUAL, ProfileMismatchFixService.assess(List.of(DOB)).verdict());
        // Even when everything next to it could have been written.
        assertEquals(Verdict.MANUAL, ProfileMismatchFixService.assess(List.of(NAME, DOB)).verdict());
    }

    @Test
    @DisplayName("an unrecognised error line stops the automation rather than being guessed at")
    void unknownErrorsAreManual() {
        Assessment assessment =
                ProfileMismatchFixService.assess(List.of(NAME, "Some brand new rule IVAC just added"));

        assertEquals(Verdict.MANUAL, assessment.verdict());
        assertEquals(List.of("Some brand new rule IVAC just added"), assessment.unrecognised());
    }

    @Test
    @DisplayName("no errors means nothing to do")
    void noErrorsIsNothing() {
        assertEquals(Verdict.NOTHING, ProfileMismatchFixService.assess(List.of()).verdict());
        assertEquals(Verdict.NOTHING, ProfileMismatchFixService.assess(null).verdict());
    }

    @Test
    @DisplayName("the portal is sent the whole profile, under the keys IVAC reads it back with")
    void profilePayloadCarriesEveryWritableField() {
        JsonObject profile = new Gson().fromJson("""
                {"userId":"db389cbf","givenName":"LIPI RANI","surName":"SARKER",
                 "passport":"A17642157","nid":"3297718599","dob":"1975-05-25T00:00:00.000Z",
                 "phone":"01606439393","email":"suvo@gmail.com","daysLeft":0,"updatable":true}
                """, JsonObject.class);

        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(
                profile, ProfileMismatchFixService.assess(List.of()));

        assertEquals("LIPI RANI", payload.get("given_name").getAsString());
        // Live GET /profile spells it "surName" (captured Aug 3 2026). Reading only the lower-case
        // spelling returned blank, the portal dropped it, and the form kept the old surname.
        assertEquals("SARKER", payload.get("surname").getAsString());
        assertEquals("A17642157", payload.get("passport").getAsString());
        assertEquals("3297718599", payload.get("nid").getAsString());
        assertEquals("01606439393", payload.get("phone").getAsString());
        assertEquals("suvo@gmail.com", payload.get("email").getAsString());
        // DOB is not written onto the form, so it is never sent.
        assertNull(payload.get("dob"));
    }

    @Test
    @DisplayName("a profile missing a field sends it blank rather than dropping the key")
    void profilePayloadToleratesAnIncompleteProfile() {
        JsonObject profile = new Gson().fromJson(
                "{\"givenName\":\"KHANAM\",\"surname\":\"RIA\",\"nid\":null}", JsonObject.class);

        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(
                profile, ProfileMismatchFixService.assess(List.of()));

        assertEquals("", payload.get("nid").getAsString());
        assertEquals("", payload.get("passport").getAsString());
        assertEquals("KHANAM", payload.get("given_name").getAsString());
    }

    @Test
    @DisplayName("the live account-988 profile produces a payload that states the whole account")
    void liveProfileIsWrittenWhole() {
        // Verbatim GET /profile for account 988, captured Aug 3 2026 (bot_logs id 1068). The surname
        // arrives ONLY as "surName"; the run that read "surname" wrote four fields out of five and
        // left the old applicant's surname on the form, so IVAC rejected it on the name alone.
        JsonObject profile = new Gson().fromJson("""
                {"userId":"6ad682bb-1947-4c2a-8e2b-e09bfcf846c0","givenName":"SANJIB","surName":"SAHA",
                 "passport":"A08419081","nid":"4192484477","dob":"1984-12-15T00:00:00.000Z",
                 "phone":"01401267377","email":"iffatass.o.cia.t.es@gmail.com","daysLeft":0,
                 "updatable":true}
                """, JsonObject.class);

        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(profile, ALL_FIVE);

        assertEquals("SANJIB", payload.get("given_name").getAsString());
        assertEquals("SAHA", payload.get("surname").getAsString());
        assertEquals("A08419081", payload.get("passport").getAsString());
        assertEquals("4192484477", payload.get("nid").getAsString());
        assertEquals("01401267377", payload.get("phone").getAsString());
        assertEquals("iffatass.o.cia.t.es@gmail.com", payload.get("email").getAsString());
    }

    @Test
    @DisplayName("a profile whose name disagrees with IVAC's stated one loses to the stated one")
    void statedNameWinsOverADisagreeingProfile() {
        // The stated name is the exact string IVAC compares the form against, so a profile that does
        // not reproduce it cannot be trusted to place the boundary either.
        JsonObject profile = new Gson().fromJson("{\"givenName\":\"SANJIB\"}", JsonObject.class);

        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(profile, ALL_FIVE);

        assertEquals("SANJIB", payload.get("given_name").getAsString());
        assertEquals("SAHA", payload.get("surname").getAsString());
    }

    @Test
    @DisplayName("a field the profile omits is filled from the account value IVAC stated")
    void profilePayloadFallsBackToTheMismatchValues() {
        // Only the name comes back; everything else has to come from the error lines, which name the
        // account's own value for each field they compare.
        JsonObject profile = new Gson().fromJson("{\"givenName\":\"SANJIB\",\"surName\":\"SAHA\"}",
                JsonObject.class);

        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(profile, ALL_FIVE);

        assertEquals("SANJIB", payload.get("given_name").getAsString());
        assertEquals("SAHA", payload.get("surname").getAsString());
        assertEquals("A08419081", payload.get("passport").getAsString());
        assertEquals("4192484477", payload.get("nid").getAsString());
        assertEquals("01401267377", payload.get("phone").getAsString());
        assertEquals("iffatass.o.cia.t.es@gmail.com", payload.get("email").getAsString());
    }

    @Test
    @DisplayName("a nameless profile splits IVAC's stated full name at the last space")
    void profilePayloadSplitsTheStatedFullName() {
        JsonObject payload = ProfileMismatchFixService.buildProfilePayload(
                new Gson().fromJson("{}", JsonObject.class), ALL_FIVE);

        assertEquals("SANJIB", payload.get("given_name").getAsString());
        assertEquals("SAHA", payload.get("surname").getAsString());
    }

    @Test
    @DisplayName("the full-name split keeps every middle name with the given name")
    void fullNameSplitsAtTheLastSpace() {
        assertArrayEquals(new String[]{"MD ABDULLAH ALL", "RUHIT"},
                ProfileMismatchFixService.splitFullName("MD ABDULLAH ALL RUHIT"));
        assertArrayEquals(new String[]{"SANJIB", "SAHA"},
                ProfileMismatchFixService.splitFullName("  SANJIB   SAHA "));
        // A single word is all given name: a blank surname is dropped by the portal rather than
        // written, so the form's surname box is left as it is instead of being cleared.
        assertArrayEquals(new String[]{"MADONNA", ""},
                ProfileMismatchFixService.splitFullName("MADONNA"));
        assertArrayEquals(new String[]{"", ""}, ProfileMismatchFixService.splitFullName(null));
    }

    /**
     * The July 31 2026 live case: account 988 holds one PDF and it is a different applicant's, so all
     * five fields disagree at once.
     */
    private static final ProfileMismatchFixService.Assessment ALL_FIVE = ProfileMismatchFixService.assess(List.of(
            "Name mismatch: User name 'SANJIB SAHA' does not match file details name 'MD ABDULLAH ALL RUHIT'",
            "Email mismatch: User email 'iffatass.o.cia.t.es@gmail.com' does not match file details "
                    + "email 'ABDULLAHRUHIT9@GMAIL.COM'",
            "Phone mismatch: User phone '01401267377' does not match file details phone '01749493520'",
            "Passport number mismatch: User passport #'A08419081' does not match file details "
                    + "passport # 'A17941228'",
            "Citizenship /National ID No mismatch: User Citizenship /National ID No #'4192484477' does "
                    + "not match file details Citizenship /National ID No # '6917048214'"));

    @Test
    @DisplayName("a payload with nothing to write is recognised, so the rewrite guard is not spent")
    void blankPayloadIsRecognised() {
        JsonObject empty = new Gson().fromJson("{}", JsonObject.class);

        assertTrue(ProfileMismatchFixService.isBlankPayload(
                ProfileMismatchFixService.buildProfilePayload(empty, ProfileMismatchFixService.assess(List.of()))));
        assertFalse(ProfileMismatchFixService.isBlankPayload(
                ProfileMismatchFixService.buildProfilePayload(empty, ALL_FIVE)));
    }

    @Test
    @DisplayName("a shrinking mismatch is progress, so the rewrite guard compares fields not accounts")
    void theGuardComparesTheFieldsStillDisagreeing() {
        // Round 1 on account 988: all five disagree. Round 2 after the rewrite: only the name does.
        // A flat once-per-account guard would refuse round 2 and leave the account unbookable.
        String round1 = ProfileMismatchFixService.signatureOf(ALL_FIVE);
        String round2 = ProfileMismatchFixService.signatureOf(ProfileMismatchFixService.assess(List.of(
                "Name mismatch: User name 'SANJIB SAHA' does not match file details name 'SANJIB RUHIT'")));

        assertEquals("email,name,nid,passport,phone", round1);
        assertEquals("name", round2);
        // Order-independent: IVAC lists its errors in whatever order it likes.
        assertEquals(ProfileMismatchFixService.signatureOf(
                        ProfileMismatchFixService.assess(List.of(PHONE, EMAIL))),
                ProfileMismatchFixService.signatureOf(
                        ProfileMismatchFixService.assess(List.of(EMAIL, PHONE))));
    }

    @Test
    @DisplayName("a wrong document is still repairable — the profile rewrite accepts that verdict too")
    void wrongDocumentIsRepairable() {
        assertEquals(Verdict.WRONG_DOCUMENT, ALL_FIVE.verdict());
        // Every value the rewrite needs is in the one response, so no second call is required to
        // decide it can proceed.
        assertEquals("A08419081", ALL_FIVE.accountPassport());
        assertEquals("SANJIB SAHA", ALL_FIVE.accountValue(ProfileMismatchFixService.FIELD_NAME));
        assertEquals("", ALL_FIVE.accountValue(ProfileMismatchFixService.FIELD_DOB));
    }

    @Test
    @DisplayName("the uploaded form's passport is read from data.overview")
    void readsPassportFromOverview() {
        String body = """
                {"data":{"overview":{"applicationId":"BGDSV0B13626","passport":"A05963080",
                 "fullName":"GOUR PRASANNA DAS"},"error":[]},"statusCode":200,"successFlag":true}
                """;

        assertEquals("A05963080", ProfileMismatchFixService.overviewPassport(body));
        assertEquals("", ProfileMismatchFixService.overviewPassport("not json"));
        assertEquals("", ProfileMismatchFixService.overviewPassport(null));
        assertNull(ProfileMismatchFixService.overview("{\"data\":{}}"));
    }
}
