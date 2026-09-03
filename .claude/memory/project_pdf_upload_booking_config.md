---
name: project_pdf_upload_booking_config
description: One-time PDF upload + booking-config flow (portal + Java bot) that gates slot reserve
metadata: 
  node_type: memory
  type: project
  originSessionId: 02edcd48-64a5-4005-8f90-573e11d10cee
---

Added Jul 9 2026 (branch `perf/captcha-monitor-processing-speedup`). IVAC's `POST /slots/{reserveSlotId}/reserve-slot` fails unless, per account: (1) all applicant PDFs are uploaded via `POST /file/upload-file` and (2) `POST /appointment/appointment-booking-config` has run. The bot now does both, **once per account**, inline after OTP verify and before the slot race (`AccountWorker.runCycle`, right after `setSessionExpiryValidator`).

**Portal**
- `accounts` columns: `pdfs` (JSON `[{name,base64,is_primary}]`, base64-in-DB), `pdf_uploaded` bool, `booking_configured` bool, `booking_city` string enum. Exactly one PDF must be primary (validated in `AccountController`; UI radio in `Accounts/Index.vue`). Editing pdfs/city resets the matching flag so the bot re-does setup.
- `App\Support\IvacBookingCities` = the 5 city → `{mission, ivacCenter}` map (Dhaka/Khulna/Chittagong/Rajshahi/Sylhet). `ApiTesterController::setBookingConfig` refactored to use it. Booking-config payload is ONLY `{mission, ivacCenter}` (proven by api-tester).
- `/api/config` (`PublicConfigController`) exports per account: `id, pdfUploaded, bookingConfigured, bookingMission, bookingIvacCenter` — **never base64** (kept lean). Mirrored in `ConfigExportService`.
- New slot-auth endpoints (`AccountBotSetupController`, routes in the pre-auth slot group): `GET /api/accounts/{account}/pdfs` (403 unless slot owns account) delivers base64; `POST /api/accounts/setup-state {phone,pdf_uploaded?,booking_configured?}` writes flags back so restarts skip.
- `CaptchaAlgorithmService::analyze()` now also `syncReserveSlotId()`: regex `/slots/{uuid}/reserve-slot` from the downloaded bundle → updates `settings.reserve_slot_id` when changed (surfaced on the monitor UI). reserveSlotId is a deployment constant, not appointmentId — see [[project_reserve_slot_appointment_dates]].

**Ordering fix — setup MUST run after OTP verify** (`blitz_v_4.9`, Jul 9 2026). Both `/file/upload-file` AND `/appointment/appointment-booking-config` require an **OTP-verified** JWT — they return **401** on the pre-OTP sign-in JWT (OTP verify does NOT return a new token; the same JWT becomes authorized server-side once OTP passes). The first cut ran setup right after sign-in (before the race verifies OTP) → both 401'd (seen on `/log-analysis`, phone 01741054480). Fix: `AccountWorker.launchSetupTask()` runs setup in a background virtual thread that `awaitOtpVerifiedUntil(jwtDeadline)` then runs; a new **`RaceContext` setup gate** (`setSetupRequired`/`markSetupComplete`/`awaitSetupComplete`, latch) makes the slot tick loop **hold all reserves until setup completes** (only when required — later windows skip it, speculative firing preserved). Both the gate wait and setup retry are **bounded by JWT validity** (`signinResult.getSessionExpiresAtMs()` / `getTimeUntilExpirySeconds()`) — past expiry the cycle re-auths anyway. `AccountSetupService.prepareForBooking(deadlineMs)` now **retries** transient upload/booking-config failures (`StepResult.DONE/RETRY/GIVE_UP`, 2s between rounds) until success or deadline; GIVE_UP (no slot key / no PDFs / no city) does not retry.

**Restart-resume on valid JWT** (Jul 9 2026): when the bot restarts and the stored JWT is still valid AND OTP already verified (`account.isOtpVerified()` reuse branch), it now sets the JWT on the clients and calls `launchSetupTask` **before the pre-window wait** — so upload/booking-config resume **immediately on restart, no re-login, no waiting for the window**. `setupTaskLaunched` flag guards the post-sign-in launch so it fires once. The not-yet-verified paths (fresh sign-in, reuse-unverified) still launch setup after sign-in; the task's `awaitOtpVerifiedUntil` returns instantly when OTP is already verified, so one helper serves both.

**409 "File already exists." = success** (Jul 9 2026): IVAC 409 on upload (PDF already uploaded on a prior run whose in-process `UPLOADED_CACHE` didn't survive restart) is treated as DONE — `uploadOne` adds it to the cache and logs "already exists — skipping" instead of retrying. Success shape is 200 `successFlag:true` with `data.overview` (applicationId etc.).

**Multipart body not logged** (Jul 9 2026): `ApiLogInterceptor.readRequestBody` no longer dumps raw PDF bytes for `multipart/*` requests — logs `(multipart/form-data upload, N bytes)`. Historical rows already in `bot_logs` are stripped server-side by `DbBotLogsController::stripMultipartBody` (detects `Content-Disposition`/leading `--`/`%PDF`) so `/log-analysis` (`/api/db-bot-logs`) doesn't ship megabytes and slow the page.

**Java bot**
- Upload needs a **raw** captcha (`PortalCaptchaClient.requestCaptcha("raw", phone)`) sent as `x-token` header — same as payment. Multipart is NEW: `IvacHttpClient.postMultipartFile()` (only had JSON before). Headers: Authorization (auto) + x-token only; NO `Content-Type: application/json`, NO `X-Device-ID` (file service 404s on it — see api-tester note in CLAUDE.md).
- `service/setup/PortalSetupClient` (slot key from `slot.api.key` sysprop, like `PortalOtpClient`) + `service/setup/AccountSetupService` orchestrates. `AccountConfig` gained `id, pdfUploaded, bookingConfigured, bookingMission, bookingIvacCenter`.
- **Upload optimization** (user asked): PDFs upload in parallel (virtual threads), each fetching its own raw captcha; a process-wide `UPLOADED_CACHE` keyed by `accountId:sha256(base64)` makes a retry after partial failure re-upload only the failed PDFs (no dupes). Whole-account `pdf_uploaded` flag flips only when all succeed.
- Local mode (no slot key) can't fetch PDFs → upload skipped with a WARN (prepare via api-tester). Production = distributed VPS with slot key.

Tests: `tests/Feature/PdfBookingConfigTest.php` (8, green). Pre-existing unrelated failures: `ConfigExportTest` (stale vs service), `BypassSlotParallelShotsTest` (missing column on this branch).
