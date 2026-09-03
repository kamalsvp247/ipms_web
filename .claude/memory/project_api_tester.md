---
name: project-api-tester
description: "API Tester page architecture — controller, Vue state, OTP verify payload, sidebar, session persistence"
metadata: 
  node_type: memory
  type: project
  originSessionId: ac71f290-32fc-4f35-9628-a634ee9a81fb
---

# API Tester (`/api-tester`)

Controller: `app/Http/Controllers/Api/ApiTesterController.php`
Vue: `resources/js/pages/ApiTester/Index.vue`
Routes: under `middleware('can:bot.manage')` in `routes/api.php`

## Session Persistence
- `AccountSession` model stores `jwt_token`, `jwt_expires_at`, `jwt_generated_at`, `request_id` per phone
- `GET /api/api-tester/session/{accountId}` returns all four fields — Vue loads on account select
- Vue caches sessions in `perAccountSessions` keyed by account ID; reloads from DB only on first select
- `watch(accountId)` resets `signinRequestId` and `fpRequestId` on account switch

## OTP Verify Payload
- IVAC endpoint: `POST /otp/verifySigninOtp`
- Correct payload: `{ requestId, phone, code, otpChannel }` — field is `code` not `otp`; no `email` field
- **Why:** IVAC returns 400 "OTP required" if field is named `otp`; confirmed via `OtpVerifyRequest.java`

## File Overview
- Route: `POST /api/api-tester/file-overview` → `ApiTesterController::fileOverview()`
- Proxies to IVAC `POST /file/overview` with only Bearer token (no body)
- Response: `data[]` array of applicant documents with applicationId, fullName, passport, primary, etc.
- Result stored in Vue `perAccountSidebar` for the right sidebar

## Get Booking Config Auto-Save
- `ApiTesterController::bookingConfig()` accepts optional `account_id`
- On 200 response, extracts `data.appointmentId` and saves to `accounts.appointment_id` + `accounts.appointment_id_updated_at`
- Vue sends `account_id` in the request; also stores booking config in `perAccountSidebar`

## appointment_id_updated_at
- Migration: `2026_06_02_150617_add_appointment_id_updated_at_to_accounts_table`
- Set by `AccountService::create/update` when `appointment_id` is non-null
- Displayed below the Appt ID input in both card and table views on `/accounts`
- Table column width: `w-72` (wide enough for 36-char UUID)

## PDF Upload (`/file/upload-file`) — header conformance (Jul 9 2026)
- `uploadPdf` → `ivacFileUpload()`; multipart fields `file`, `isPrimary` (+ optional `fileNumber`) match the IVAC bundle's `FormData`.
- **BUG fixed:** upload was 404 `"Appointment not found."` (browser worked) because we sent `X-Device-ID` + `Accept: application/json`. The string `X-Device-ID`/`device` appears **ZERO** times in the 2.2 MB bundle — the site sends NO device header on ANY call; its axios interceptor adds only `Authorization`, plus `x-token` on this endpoint, and lets the browser set the multipart `Content-Type`+boundary. Sign-in/OTP tolerate the extra headers but IVAC's file service is stricter and rejects them.
- **Fix:** `ivacFileUpload` now sends only `Authorization: Bearer` + `x-token` (+ `Expect:` to kill 100-continue); curl sets the boundary. `$deviceId` param left in signature but unused for upload.
- Lesson: when an api-tester call 404s/400s but the browser works with the same token, diff our headers against the bundle — extra headers the site never sends can break stricter IVAC services.

## BD Proxy Transport (no bypass IP → proxy)
- When **no bypass IP** is selected, requests target `api.ivacbd.com` directly but tunnel through the Algorithm Monitor BD proxy (`settings.captcha_bd_proxy_url`) so they exit from a BD IP, not the portal host.
- `ApiTesterController::resolveTransport(?ip)` → `['mode' => 'resolve'|'proxy'|'direct']`; applied by `applyTransport($ch, $ip)` in all three curl builders (CURLOPT_RESOLVE for a bypass IP, else CURLOPT_PROXY).
- **Step 8 Download Invoice** calls `GET /api/payment-links/invoice` (`PaymentLinkController::invoiceDownload`). No bypass IP → Vue sends `prefer_proxy=1` → controller fetches via BD proxy first; a selected IP uses DNS resolve. BD proxy is also a final fallback after bypass IPs fail (single + ZIP paths). Helper: `PaymentLinkController::bdProxyUrl()`.

## Right Sidebar
- Appears when any account is selected; data cached per account in `perAccountSidebar`
- Shows **Booking Config**: appointmentId, date, slot, mission, IVAC center, status, applicants, amount
- Shows **File Overview**: per document — applicationId, fullName, passport, visa, commission + Primary badge
- Both show fetch timestamp; empty state placeholder until API is called
- Vue helpers: `ensureSidebar(id)`, `fmtTime(iso)`, `sidebarData` computed
