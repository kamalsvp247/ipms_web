---
name: project_reserve_slot_appointment_dates
description: "July 2026 — reserve-slot URL now uses a portal Setting reserveSlotId; appointment dates are per-account, expanded from a range, rotated one date-string per reserve call"
metadata: 
  node_type: memory
  type: project
  originSessionId: ace45cc0-52f9-4dbc-9f86-6b3db3ec2169
---

Work completed July 9 2026 (branch `perf/captcha-monitor-processing-speedup`, bot `blitz_v_4.6`).

**Reserve-slot endpoint changed** to `POST /slots/{reserveSlotId}/reserve-slot`.
- `reserveSlotId` (e.g. `ccd3dd63-e781-48ba-a48d-c65eaa4fc663`) is a **hardcoded deployment-scoped constant** baked into IVAC's frontend bundle — verified by running the bundle's own obfuscated decoders in Node/vm (appears exactly once, no interpolation). It is **NOT** the account's `appointmentId`. IVAC rotates it on redeploy.
- Stored as portal Setting **`reserve_slot_id`** (migration `2026_07_09_010000_...`, default `ccd3dd63-...`), fillable on `Setting`, validated `nullable|string` in `SettingController`, editable in `settings/Index.vue` (input under `base_url`). Exported as `reserveSlotId` in both `PublicConfigController` (`/api/config`, the bot's real endpoint) and `ConfigExportService` (`/api/config/export`, legacy).
- Java: `AppConfig.reserveSlotId` (`@SerializedName("reserveSlotId")` + getter). `SlotReservationServiceImpl` builds the path from it; WARN + `ShotOutcome.RETRY` if blank.
- api-tester (`ApiTesterController::reserveSlot`) also builds the URL from `Setting::instance()->reserve_slot_id` (rawurlencoded), 422 if empty.

**Appointment dates — per-account, range-expanded, rotated single string.**
- Accounts page has **From date / To date** inputs; `expandDateRange(from,to)` (UTC-safe, inclusive, capped 366) expands to every date in between and submits as `appointment_dates` array. Shown in the accounts table too.
- DB: `accounts.appointment_dates` JSON (migration `2026_07_09_020000_...` dropped the earlier string `appointment_date`); `Account` fillable + `'array'` cast; validated `nullable|array` + `appointment_dates.*` => `date`.
- Config exports `appointmentDates: []` per account.
- **CRITICAL — reserve body sends ONE date string, not the array.** IVAC returns `{code:2001, message:invalid.json}` (400) if `appointmentDate` is a JSON array. Fix: `AccountConfig.nextAppointmentDate()` round-robins the list via a `transient AtomicInteger` cursor (empty list → `""`); reserve body is `Map.of("c", captchaValue, "appointmentDate", appointmentDate)` — a single string like `"2026-07-15"`, a different one each attempt.

**Other changes this session:**
- Java sign-in endpoint → `/auth/sign-in-v4` (`SigninServiceImpl`, was sign-in-v2). Also updated in api-tester (3 sites).
- api-tester PDF upload now sends `x-token` header (raw Turnstile token, like Initiate Payment): `uploadPdf` accepts `captcha_token`, `ivacFileUpload(..., ?string $captchaToken)`; Vue `callUploadPdf` does `fetchCaptcha('raw')`.

Tests: `ConfigExportTest::test_bot_config_includes_account_appointment_dates` + `test_bot_config_includes_reserve_slot_id` hit `/api/config` (not `/export`) and must `User::factory()->create(['role'=>'super_admin'])` **before** `seed(SettingSeeder)` (SettingSeeder:65 dereferences a super_admin — pre-existing footgun).

Reminder: distributed VPS workers need the JAR rebuilt via the portal **Rebuild JAR** button to ship `blitz_v_4.6`; local mode reflects immediately. See [[feedback_bot_version_bump]], [[feedback_no_jar_build]].
