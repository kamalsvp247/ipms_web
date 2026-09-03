---
name: project-jwt-otp-session-state
description: JWT OTP verification tracking in account_sessions + bot fast-path to slot on restart
metadata: 
  node_type: memory
  type: project
  originSessionId: 01531288-22df-45b6-acad-63e7ae4ad1fb
---

# JWT OTP Verification State Persistence (June 2026)

## What was built

`account_sessions` now tracks full JWT session state so the bot can make smart decisions on restart without re-signing in.

## New DB columns (migration 2026_06_16_100000)
- `is_otp_verified BOOLEAN DEFAULT FALSE` — true when the bot verified OTP against the current JWT
- `request_id VARCHAR(64)` (pre-existing, now populated) — IVAC `signinRequestId` from sign-in response
- `signed_in_server_time VARCHAR(64)` (pre-existing, now populated) — IVAC server time at sign-in

## How it flows

**After sign-in:** `PortalClient.storeJwt()` posts `request_id` + `signed_in_server_time` alongside the JWT.

**After OTP verify:** `PortalClient.storeOtpVerified()` posts `otp_code` + `otp_verified_server_time` → Laravel sets `is_otp_verified = true`.

**On new JWT upsert:** `is_otp_verified` is always reset to `false`.

## `/api/config` per-account response (new fields)
- `isOtpVerified` (bool) — gated on JWT validity; false if JWT expired even if DB flag is true
- `signinRequestId` (string|null) — from `account_sessions.request_id`
- `signinServerTimeMs` (int|null) — `signed_in_server_time` parsed to epoch ms

## Java bot restart logic (`AccountWorker.runCycle`)

When valid JWT found (`jwtStillValid = true`):

**Branch A — `account.isOtpVerified() == true`:**
- Skip FP sendOtp entirely
- Pre-mark `context.markOtpVerifyStarted()` + `context.setOtpVerified(null)`
- Wait for window, then go straight to slot reservation
- `RaceOrchestrator.startOtpVerifyPhase()` returns early (guard: `if context.isOtpVerified()`)

**Branch B — `account.isOtpVerified() == false`:**
- Fire FP OTPs as before
- Restore `signinRequestId` into `RaceContext` so race has a third OTP pair
- `signinResult = new SigninResult(storedToken, storedExpiry, account.getSigninRequestId(), account.getSigninServerTimeMs())`

## Key files
- `app/Models/AccountSession.php` — `is_otp_verified` in fillable + casts
- `app/Http/Controllers/Api/AccountSessionController.php` — sets/resets `is_otp_verified`
- `app/Http/Controllers/Api/PublicConfigController.php` — `isOtpVerifiedForSession()`, `signinServerTimeMs()` helpers
- `ipms_java/.../PortalClient.java` — `storeJwt(+signinRequestId, +serverTimeMs)`, `storeOtpVerified()`
- `ipms_java/.../config/AccountConfig.java` — `isOtpVerified`, `signinRequestId`, `signinServerTimeMs`
- `ipms_java/.../worker/AccountWorker.java` — Branch A/B split
- `ipms_java/.../worker/RaceOrchestrator.java` — early return guard in `startOtpVerifyPhase()`

**Why:** `isOtpVerified` gated on JWT validity — if JWT expired, portal returns `false` regardless of DB row, preventing stale state from being used.
