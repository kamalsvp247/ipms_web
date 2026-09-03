package com.ivac.booking.service.setup;

import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.ivac.booking.config.AppConfig;
import com.ivac.booking.networking.IvacHttpClient;
import com.ivac.booking.util.ConsoleLogger;

import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;
import java.util.concurrent.ConcurrentHashMap;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Reads IVAC's per-file validation errors on a PDF upload and decides what — if anything — the bot
 * may safely do about them.
 *
 * The file service answers HTTP 200 with successFlag:true while listing every field that disagrees
 * between the signed-in ACCOUNT and the uploaded FORM in data.error[], and describing the form
 * itself in data.overview. Both sides of every comparison are therefore in that one response:
 *   "Name mismatch: User name 'GOUR PRASANNA DAS' does not match file details name 'RINA DAS'"
 * gives the account holder's name and the form's name without any further call.
 *
 * IVAC will not create the appointment while any of them disagree, so booking-config and
 * reserve-slot both 404 with "Appointment not found." until they are reconciled.
 *
 * The repair runs in one direction only: the ACCOUNT is never modified, the FORM is. The account's
 * phone is the IVAC sign-in identity and its email carries every OTP the bot depends on, so editing
 * either would lock the bot out of the very account it is repairing — and the earlier design, which
 * corrected the account through IVAC's OTP-gated profile API, could therefore never touch the two
 * fields that mismatch most often. Rewriting the form instead costs nothing, needs no OTP, and covers
 * every field the portal's editor can place.
 *
 * The verdicts:
 *   FIX_PDF        — the form is the right applicant's but disagrees with the account. The portal
 *                    rewrites the form to state the profile IVAC already holds, and the caller
 *                    re-uploads it.
 *   WRONG_DOCUMENT — two or more of name/passport/NID disagree, so the form belongs to a different
 *                    person entirely. The account may simply hold the holder's own form under another
 *                    document, so the caller looks there FIRST — but when it does not (the common case
 *                    is an account carrying exactly one, wrong, PDF) the form is rewritten from the
 *                    profile just as FIX_PDF does. Dead-ending here instead is what left an account
 *                    re-uploading the same rejected document until its JWT expired.
 *   MANUAL         — a date-of-birth mismatch, or a field this code does not recognise. DOB is the one
 *                    validated field app/Scripts/edit_passport_pdf.py cannot place, so it has to be
 *                    corrected by an operator.
 */
public class ProfileMismatchFixService {

    private static final Gson GSON = new Gson();

    // Field keys, matching the profile API's own payload keys where one exists.
    public static final String FIELD_NAME = "name";
    public static final String FIELD_PASSPORT = "passport";
    public static final String FIELD_NID = "nid";
    public static final String FIELD_EMAIL = "email";
    public static final String FIELD_PHONE = "phone";
    public static final String FIELD_DOB = "dob";

    /**
     * Each known error line, mapped to the profile field it concerns. Every pattern captures the
     * account's value first and the form's value second. The quoting is not uniform across IVAC's
     * messages (#'x' for the numbers, 'x' for the rest), so each one is matched explicitly rather
     * than by one loose pattern — an unrecognised line must fall through to MANUAL, never be
     * mis-parsed into an automated identity change.
     */
    private static final Map<String, Pattern> PATTERNS = Map.of(
            FIELD_NAME, Pattern.compile(
                    "Name mismatch:\\s*User name\\s*'([^']*)'\\s*does not match file details name\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE),
            FIELD_PASSPORT, Pattern.compile(
                    "Passport number mismatch:\\s*User passport\\s*#'([^']*)'\\s*"
                            + "does not match file details passport\\s*#\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE),
            FIELD_NID, Pattern.compile(
                    "Citizenship\\s*/National ID No mismatch:\\s*User Citizenship\\s*/National ID No\\s*#'([^']*)'\\s*"
                            + "does not match file details Citizenship\\s*/National ID No\\s*#\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE),
            FIELD_EMAIL, Pattern.compile(
                    "Email mismatch:\\s*User email\\s*'([^']*)'\\s*does not match file details email\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE),
            FIELD_PHONE, Pattern.compile(
                    "Phone mismatch:\\s*User phone\\s*'([^']*)'\\s*does not match file details phone\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE),
            FIELD_DOB, Pattern.compile(
                    "Date of birth mismatch:\\s*User (?:date of birth|dob)\\s*'([^']*)'\\s*"
                            + "does not match file details (?:date of birth|dob)\\s*'([^']*)'",
                    Pattern.CASE_INSENSITIVE));

    // The one validated field the portal's form editor cannot place, so it is the only mismatch that
    // still needs an operator. Everything else is written onto the form from the profile.
    private static final Set<String> NOT_WRITABLE = Set.of(FIELD_DOB);

    // Two or more of these disagreeing means a different person, not a mistyped field.
    private static final Set<String> IDENTITY_FIELDS = Set.of(FIELD_NAME, FIELD_PASSPORT, FIELD_NID);

    // The set of fields the last rewrite for an account was asked to fix, keyed by account id. A
    // rewrite that comes back with the SAME fields still disagreeing means the form field IVAC compares
    // is not the one being written, and repeating it would re-upload the same document every round for
    // the life of the JWT — so that is refused.
    //
    // A rewrite that comes back with FEWER (or different) fields disagreeing is progress and is allowed
    // to continue: on account 988 the first pass fixed email/phone/passport/NID and left only the
    // surname, and a flat once-per-account guard threw that away. The signature must strictly change
    // each round, so the loop is still bounded by the number of writable fields.
    private static final ConcurrentHashMap<Integer, String> LAST_ATTEMPT = new ConcurrentHashMap<>();

    public enum Verdict { NOTHING, FIX_PDF, WRONG_DOCUMENT, MANUAL }

    /**
     * One field IVAC found to disagree: what the account says, and what the form says.
     */
    public record FieldMismatch(String field, String accountValue, String fileValue) {
    }

    /**
     * The whole assessment of one upload response.
     *
     * @param verdict      what the caller may do about it
     * @param mismatches   every recognised field mismatch, in the order IVAC listed them
     * @param unrecognised error lines this code could not parse (always forces MANUAL)
     */
    public record Assessment(Verdict verdict, List<FieldMismatch> mismatches, List<String> unrecognised) {

        public FieldMismatch find(String field) {
            for (FieldMismatch mismatch : mismatches) {
                if (mismatch.field().equals(field)) {
                    return mismatch;
                }
            }
            return null;
        }

        /**
         * The account holder's passport as IVAC stated it, used to recognise the holder's own form
         * among the account's other documents. Null when passport was not one of the mismatches.
         */
        public String accountPassport() {
            FieldMismatch passport = find(FIELD_PASSPORT);
            return passport != null ? passport.accountValue() : null;
        }

        /**
         * The account holder's value for one field as IVAC stated it in the mismatch, or "" when that
         * field was not among the mismatches. Every error line names both sides of its comparison, so
         * this is a second, independent source for the same values GET /profile returns.
         */
        public String accountValue(String field) {
            FieldMismatch mismatch = find(field);
            return mismatch != null && mismatch.accountValue() != null ? mismatch.accountValue() : "";
        }

        public String describe() {
            List<String> parts = new ArrayList<>();
            for (FieldMismatch mismatch : mismatches) {
                parts.add(mismatch.field() + ": account '" + mismatch.accountValue()
                        + "' vs form '" + mismatch.fileValue() + "'");
            }
            parts.addAll(unrecognised);
            return String.join(" | ", parts);
        }
    }

    private final int accountId;
    private final String phone;
    private final AppConfig appConfig;
    private final IvacHttpClient client;
    private final PortalSetupClient portalSetup;

    public ProfileMismatchFixService(int accountId,
                                     String phone,
                                     AppConfig appConfig,
                                     IvacHttpClient client,
                                     PortalSetupClient portalSetup) {
        this.accountId = accountId;
        this.phone = phone;
        this.appConfig = appConfig;
        this.client = client;
        this.portalSetup = portalSetup;
    }

    /**
     * Classifies IVAC's data.error[] list. See the class doc for what each verdict means.
     */
    public static Assessment assess(List<String> uploadErrors) {
        List<FieldMismatch> mismatches = new ArrayList<>();
        List<String> unrecognised = new ArrayList<>();

        if (uploadErrors != null) {
            for (String error : uploadErrors) {
                if (error == null || error.isBlank()) {
                    continue;
                }
                FieldMismatch parsed = parse(error);
                if (parsed != null) {
                    mismatches.add(parsed);
                } else {
                    unrecognised.add(error);
                }
            }
        }

        if (mismatches.isEmpty() && unrecognised.isEmpty()) {
            return new Assessment(Verdict.NOTHING, mismatches, unrecognised);
        }

        // An unparsed line could be anything, so nothing is changed automatically on that account.
        if (!unrecognised.isEmpty()) {
            return new Assessment(Verdict.MANUAL, mismatches, unrecognised);
        }

        Set<String> fields = new LinkedHashSet<>();
        for (FieldMismatch mismatch : mismatches) {
            fields.add(mismatch.field());
        }

        long identityCount = fields.stream().filter(IDENTITY_FIELDS::contains).count();
        if (identityCount >= 2) {
            return new Assessment(Verdict.WRONG_DOCUMENT, mismatches, unrecognised);
        }

        for (String field : fields) {
            if (NOT_WRITABLE.contains(field)) {
                return new Assessment(Verdict.MANUAL, mismatches, unrecognised);
            }
        }

        return new Assessment(Verdict.FIX_PDF, mismatches, unrecognised);
    }

    private static FieldMismatch parse(String error) {
        for (Map.Entry<String, Pattern> entry : PATTERNS.entrySet()) {
            Matcher matcher = entry.getValue().matcher(error);
            if (matcher.find()) {
                return new FieldMismatch(entry.getKey(), matcher.group(1).trim(), matcher.group(2).trim());
            }
        }
        return null;
    }

    /**
     * Reads the applicant the uploaded form describes out of data.overview. Returns null when the
     * response carries no overview.
     */
    public static JsonObject overview(String body) {
        if (body == null || body.isBlank()) {
            return null;
        }
        try {
            JsonObject root = GSON.fromJson(body, JsonObject.class);
            if (root != null && root.has("data") && root.get("data").isJsonObject()) {
                JsonObject data = root.getAsJsonObject("data");
                if (data.has("overview") && data.get("overview").isJsonObject()) {
                    return data.getAsJsonObject("overview");
                }
            }
        } catch (Exception ignored) {
            // Unknown response shape — no overview to read.
        }
        return null;
    }

    /**
     * The passport on the uploaded form, or "" when the response carries no overview.
     */
    public static String overviewPassport(String body) {
        JsonObject overview = overview(body);
        return overview != null ? stringOrEmpty(overview, "passport") : "";
    }

    /**
     * Applies a repairable assessment: reads the profile IVAC holds for this account and has the portal
     * rewrite the primary applicant form to state it. Returns the corrected documents when the portal
     * produced them, or null when nothing usable came back — in which case the caller must stop rather
     * than re-upload the same rejected form. Never throws.
     *
     * Accepts WRONG_DOCUMENT as well as FIX_PDF. The caller is expected to have tried the account's
     * other documents first for a WRONG_DOCUMENT (see AccountSetupService.reselectPrimary) — this runs
     * only once none of them is the holder's, which for a single-document account is immediately.
     */
    public List<PortalSetupClient.PdfDoc> syncPdfToProfile(Assessment assessment) {
        if (assessment.verdict() != Verdict.FIX_PDF && assessment.verdict() != Verdict.WRONG_DOCUMENT) {
            return null;
        }
        String signature = signatureOf(assessment);
        if (signature.equals(LAST_ATTEMPT.put(accountId, signature))) {
            ConsoleLogger.log(phone, "The applicant form was already rewritten this run and IVAC still "
                    + "rejects the same field(s) (" + signature + ") — not rewriting again; the form "
                    + "field IVAC compares is not the one being written. Correct the document on the "
                    + "portal.", "ERROR");
            return null;
        }

        ConsoleLogger.log(phone, "IVAC reports an account/form mismatch — " + assessment.describe()
                + ". Rewriting the applicant form to match the account's IVAC profile", "AUTH");

        // Best-effort, deliberately not a gate. GET /profile is the better source — it alone splits the
        // name into given/surname — but it is the ONE call here whose path is not bundle-extracted
        // (settings.ivac_endpoints carries no "profile" key, so it falls back to a compiled-in
        // literal). Every value it would supply is also stated in the mismatch, so a 404 or a rotated
        // path degrades the rewrite to the assessment's own values instead of abandoning it.
        JsonObject profile = fetchProfile();
        if (profile == null) {
            profile = new JsonObject();
            ConsoleLogger.log(phone, "Profile unavailable — rewriting the form from the account values "
                    + "IVAC stated in the mismatch itself", "WARN");
        }

        JsonObject payload = buildProfilePayload(profile, assessment);
        if (isBlankPayload(payload)) {
            ConsoleLogger.log(phone, "Neither the profile nor the mismatch names a value to write onto "
                    + "the form — the document has to be corrected by hand.", "ERROR");
            return null;
        }

        List<PortalSetupClient.PdfDoc> pdfs = portalSetup.syncPdfToProfile(phone, payload);
        if (pdfs == null) {
            ConsoleLogger.log(phone, "The portal could not rewrite the applicant form — "
                    + "the document has to be corrected by hand.", "ERROR");
            return null;
        }

        ConsoleLogger.log(phone, "Applicant form rewritten from the IVAC profile — re-uploading", "OK");
        portalSetup.reportProfileName(phone, (stringOrEmpty(profile, "givenName") + " "
                + stringOrEmpty(profile, "surname")).trim());
        return pdfs;
    }

    /**
     * Reduces IVAC's profile response to the fields the portal writes onto the form, filling anything
     * the profile left blank from the account values IVAC stated in the mismatch itself.
     *
     * The profile is read back with the key "surname" while IVAC's own update API spells the same
     * field "surName"; the portal is given the read spelling, deliberately, so neither side has to
     * know about that asymmetry.
     *
     * The fallback matters because the portal drops blank values rather than writing them: a profile
     * that omits, say, the NID would leave that one field still disagreeing, the re-upload would be
     * rejected for it, and the once-per-account rewrite guard would already be spent. Every error line
     * names the account's own value for its field, so the mismatch is a complete second source for
     * all of them but the name — which arrives as one string and is split at the last space, the
     * convention IVAC's own fullName follows.
     */
    static JsonObject buildProfilePayload(JsonObject profile, Assessment assessment) {
        JsonObject payload = new JsonObject();
        String givenName = stringOrEmpty(profile, "givenName");
        // The live GET /profile response spells this "surName" — captured Aug 3 2026 on account 988:
        // {"givenName":"SANJIB","surName":"SAHA","passport":"A08419081",...}. Reading only "surname"
        // returned blank, the portal drops blanks rather than writing them, and the form kept the old
        // applicant's surname — so the rewrite fixed four fields out of five and IVAC still rejected
        // it on the name. Both spellings are accepted now.
        String surname = firstNonBlank(stringOrEmpty(profile, "surName"), stringOrEmpty(profile, "surname"));
        // The name IVAC states in the mismatch is literally the string it compares the form's fullName
        // against, so it is the authority on the target. The profile is preferred only while it agrees
        // on that whole string — then it is also the authority on WHERE the boundary between the two
        // boxes falls ("MD ABDULLAH ALL" + "RUHIT"), which a split cannot know.
        String stated = assessment.accountValue(FIELD_NAME);
        if (!stated.isBlank() && !normalizeName(givenName + " " + surname).equals(normalizeName(stated))) {
            String[] split = splitFullName(stated);
            givenName = split[0];
            surname = split[1];
        }
        payload.addProperty("given_name", givenName);
        payload.addProperty("surname", surname);
        payload.addProperty("passport", orAccountValue(profile, "passport", assessment, FIELD_PASSPORT));
        payload.addProperty("nid", orAccountValue(profile, "nid", assessment, FIELD_NID));
        payload.addProperty("phone", orAccountValue(profile, "phone", assessment, FIELD_PHONE));
        payload.addProperty("email", orAccountValue(profile, "email", assessment, FIELD_EMAIL));
        return payload;
    }

    /**
     * True when a payload carries nothing to write. The portal drops blank values, so sending one of
     * these would be a guaranteed no-op that still burns the once-per-account rewrite guard.
     */
    static boolean isBlankPayload(JsonObject payload) {
        for (String key : payload.keySet()) {
            JsonElement value = payload.get(key);
            if (value != null && !value.isJsonNull() && !value.getAsString().isBlank()) {
                return false;
            }
        }
        return true;
    }

    private static String orAccountValue(JsonObject profile, String profileKey, Assessment assessment,
                                         String field) {
        String value = stringOrEmpty(profile, profileKey);
        return !value.isBlank() ? value : assessment.accountValue(field);
    }

    /**
     * The fields an assessment is asking to have rewritten, sorted and joined, so two rounds can be
     * compared. Order-independent: IVAC lists its errors in its own order.
     */
    static String signatureOf(Assessment assessment) {
        Set<String> fields = new TreeSet<>();
        for (FieldMismatch mismatch : assessment.mismatches()) {
            fields.add(mismatch.field());
        }
        fields.addAll(assessment.unrecognised());
        return String.join(",", fields);
    }

    private static String firstNonBlank(String preferred, String fallback) {
        return !preferred.isBlank() ? preferred : fallback;
    }

    /**
     * Case- and whitespace-insensitive form of a name, for comparing two spellings of the same one.
     */
    private static String normalizeName(String name) {
        return name == null ? "" : name.trim().replaceAll("\\s+", " ").toUpperCase();
    }

    /**
     * Splits a full name into { given name, surname } at the last space — "SANJIB SAHA" becomes
     * { "SANJIB", "SAHA" }. A single-word name is all given name, since a blank surname is dropped by
     * the portal rather than written, which leaves that box on the form untouched.
     */
    static String[] splitFullName(String fullName) {
        String trimmed = fullName != null ? fullName.trim().replaceAll("\\s+", " ") : "";
        int lastSpace = trimmed.lastIndexOf(' ');
        if (lastSpace < 0) {
            return new String[]{trimmed, ""};
        }
        return new String[]{trimmed.substring(0, lastSpace), trimmed.substring(lastSpace + 1)};
    }

    private JsonObject fetchProfile() {
        try {
            IvacHttpClient.RawResponse response = client.getRaw(appConfig.getProfilePath());
            if (response.statusCode() < 200 || response.statusCode() >= 300) {
                ConsoleLogger.log(phone, "Profile fetch → HTTP " + response.statusCode()
                        + " — cannot correct the account", "ERROR");
                return null;
            }
            JsonObject root = GSON.fromJson(response.body(), JsonObject.class);
            if (root == null || !root.has("data") || !root.get("data").isJsonObject()) {
                ConsoleLogger.log(phone, "Profile fetch returned no data object — cannot correct "
                        + "the account", "ERROR");
                return null;
            }
            return root.getAsJsonObject("data");
        } catch (Exception e) {
            ConsoleLogger.log(phone, "Profile fetch failed: " + e.getMessage(), "ERROR");
            return null;
        }
    }

    private static String stringOrEmpty(JsonObject obj, String key) {
        return obj.has(key) && !obj.get(key).isJsonNull() ? obj.get(key).getAsString() : "";
    }
}
