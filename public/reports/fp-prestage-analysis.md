# Forgot-Password Pre-Stage Flow — QA Analysis Report

**Date:** 2026-03-12
**Branch:** develop_preotp_v1
**Feature:** `use_forgot_password_prestage`
**Account:** phone=01974540761, tag=DDE6
**Scenario:** Bot starts 1:20 PM BDT, window opens 1:31 PM BDT

---

## Config (live — fetched from mashmininet.site/api/config)

| Field | Value |
|---|---|
| `windowStartTime` | `13:31:00` BDT (1:31 PM) |
| `windowEndTime` | `23:59` |
| `forgotPasswordLeadSeconds` | `90` → sendOtp starts at **1:29:30 PM** |
| `signinRetryLeadSeconds` | `15` → sendOtp deadline **1:30:45 PM** (75s window) |
| `prestageOtpTimeoutMs` | `20000` — **config field, never used in code** (Bug #1) |
| `defaultTimeoutMs` | `21000` → probeActivationDelayMs in Pipeline |
| `otpIntervalDelayMs` | `3000` → Firebase poll interval |
| `otpTimeoutMs` | `120000` → normal-flow OTP wait |
| `maxRetries` | `100` (account-level override) |
| Lane 0 IP | `202.136.75.76` |
| Lane 1 IP | `202.136.75.78` |

---

## Step-by-Step Timeline (1:20 PM → 1:35 PM)

| BDT Time | What Happens | Class:Line |
|---|---|---|
| **1:20:00 PM** | Bot starts. `secondsUntilWindow=660`, `availableTimeForPrestage=645 ≥ 20` → pre-stage ENABLED. Logs "waiting 570s before sendOtp (at 1:29:30 PM)" | `AccountWorker:135` |
| **1:20:00 – 1:29:30** | **SLEEP 570s** | `AccountWorker:141` |
| **1:29:30 PM** | `sendOtpUntilWindow()` starts. Deadline = 1:30:45 PM (75s available) | `ForgotPasswordPreStager:81` |
| **1:29:30 PM** | `POST /iams/api/v1/forgot-password/sendOtp` via IP `202.136.75.76` — Body: `{"email":"IVA.CDH.K01.2@gmail.com","otpChannel":"PHONE"}` | `ForgotPasswordClient:131` |
| **~1:29:31 PM** | HTTP 200 → saves `requestId_FP`. Returns `true` | `ForgotPasswordPreStager:99` |
| **~1:29:31 PM** | `captureTimeout = max(5000, 1:30:45 − 1:29:31) = 74000ms` | `AccountWorker:175` |
| **1:29:31 PM** | `captureOtp(74000)` — Firebase cleared, polls every 3s | `ForgotPasswordPreStager:170` |
| **~1:29:40 PM** | OTP arrives in Firebase. `capturedOtp` saved. `capturedAtMs = ~1:29:40 PM` | `ForgotPasswordPreStager:187` |
| **~1:29:40 PM** | `captchaTokenService.fetchToken()` → `GET https://mashmininet.site/api/captcha/get` | `AccountWorker:185` |
| **~1:29:41 PM** | `signinEarly(captchaToken)` starts. `POST /iams/api/v1/auth/signin` — server is in **503 maintenance** | `AuthClient:413` |
| **1:29:41 – 1:31:00 PM** | **503 retry loop every 5s** (~17 attempts). Each attempt: wait 5s, retry same captcha | `AuthClient:461` |
| **~1:31:01 PM** | Server opens → HTTP 200, JWT received. `sessionExpiresAt = 1:31:01 + 300s = 1:36:01 PM` | `AuthClient:443` |
| **1:31:01 PM** | Logs: "TIMING FP signinEarly JWT received −1s before window" (negative = 1s after window) | `AccountWorker:187` |
| **1:31:01 PM** | `dualPoller.clearBothSources(phone)` — removes new signin OTP from Firebase | `AccountWorker:193` |
| **1:31:02 PM** | `hasValidOtp()` check: OTP age = 82s < 270s → **valid ✓** | `ForgotPasswordPreStager:221` |
| **1:31:02 PM** | `verifySingleOtp(fpVerifyReq)` — `POST /iams/api/v1/otp/verifySigninOtp` Body: `{requestId: "FP-req-xxx", phone: "01974540761", email: "IVA.CDH.K01.2@gmail.com", otpCode: "XXXXXX", otpChannel: "PHONE"}` | `AuthClient:538` |
| **~1:31:03 PM** | *(assumed 200 + verified=true)* → `fpPathSucceeded = true`, `otpAlreadyVerified = true` | `AccountWorker:211` |
| **1:31:03 PM** | OtpContext built with **signin's requestId** (different from `requestId_FP`) | `AccountWorker:204` |
| **1:31:03 PM** | `warmUpLaneForWindow(lane1Client)` — TCP warmup for Lane 1 IP `202.136.75.78`, 10s timeout | `AccountWorker:250` |
| **1:31:03 PM** | `waitForAllowedWindow("13:31:00", "23:59")` — already in window → returns immediately | `AccountWorker:251` |
| **1:31:04 PM** | `storeSessionData()` → `POST https://mashmininet.site/api/account-sessions` | `AuthClient:593` |
| **1:31:05 PM** | `PipelineOrchestrator.execute()` starts, `otpAlreadyVerified=true` | `PipelineOrchestrator:240` |
| **1:31:05 PM** | Lane 0: `otpVerified.set(true)` immediately, exits — OTP verify **skipped** | `PipelineOrchestrator:339` |
| **1:31:05 PM** | Lane 1: sees `otpVerified=true` within 100ms → **NO 21s delay** → enters slot loop | `PipelineOrchestrator:530` |
| **1:31:05 PM** | `POST /iams/api/v1/slots/reserveSlot` via IP `202.136.75.78` with captcha token | `SlotService` |
| **If 200 + reservationId** | Slot reserved → `runPaymentPhase()` | `PipelineOrchestrator:716` |
| **If FULL / 429** | Retry with 15s/20s/30s ±25% jitter backoff | `PipelineOrchestrator:695` |

---

## Token Expiry Map

| Token | Obtained At | Expires At | Remaining at Slot Booking (1:31:05) |
|---|---|---|---|
| JWT (signinEarly) | ~1:31:01 PM | ~1:36:01 PM | ~4 min 56s ✓ |
| Forgot-password OTP | ~1:29:40 PM | ~1:34:10 PM (270s TTL fallback) | ~3 min 5s ✓ |
| Captcha token | ~1:29:40 PM | Single-use | Used in signinEarly ✓ |

---

## Critical Race Conditions

### Race 1 — signinEarly misses first window open (likely ~5s delay)
Server opens at exactly 1:31:00. The 503 retry fires at 1:30:56 (503), next attempt at 1:31:01.
Result: JWT received at ~1:31:01 → slot booking starts **~1:31:05 PM, ~5 seconds late**.
Cannot avoid this without reducing the 5s retry interval on 503.

### Race 2 — OTP expiry (safe)
OTP captured at ~1:29:40, used at 1:31:02 → age 82s. 270s TTL gives 188s headroom. ✓

### Race 3 — JWT expiry (safe)
JWT obtained 1:31:01, expires 1:36:01. Slot booking window is 5 minutes. ✓

### Race 4 — sendOtp 429 pressure → captureOtp gets only 5s
If sendOtp hits 3 consecutive 429s (15s+20s+30s backoffs = 73s consumed of 75s window),
sendOtp succeeds at 1:30:43. `captureTimeout = max(5000, 1:30:45 − 1:30:43) = 5000ms`.
Only **5 seconds** for Firebase polling (1–2 attempts at 3s interval).
High probability of `captureOtp` timeout → **fallback to normal flow**.

---

## Edge Case Analysis

### Timing Edge Cases

**T1 — Firebase OTP arrives AFTER captureOtp timeout**
`captureOtp` polls for up to `captureTimeout` ms. If OTP doesn't arrive → logs "captureOtp timed out — fallback".
Firebase is cleared at start of next cycle (AccountWorker:116). **Safe fallback. ✓**

**T2 — Forgot-password OTP expires before hasValidOtp() check**
`capturedAtMs` ~1:29:40. `hasValidOtp()` uses 270s TTL. Expiry at ~1:34:10.
signinEarly would need to retry for 4.5+ minutes (55+ 503 attempts) for this to fail. Very unlikely.

**T3 — signinEarly blocks past window open indefinitely**
`signinEarly` has **no deadline, no max-retry count**. If server stays in 503 past window open,
the entire AccountWorker thread is stuck in pre-stage. PipelineOrchestrator never starts.
If 503 persists until (e.g.) 1:33:00, slot booking starts 2 minutes late.
If 503 never recovers, the bot loops forever in this cycle.

**T4 — expiresAt is ISO format → NumberFormatException → 270s TTL fallback**
`ForgotPasswordPreStager.parseExpiresAt()` calls `Long.parseLong()`.
If server returns `"2026-03-12T13:34:30.000Z"`, throws `NumberFormatException` → `otpExpiresAtMs = 0`.
Falls back to 270s from capturedAtMs. **Handled gracefully but TTL may be approximate.**

---

### API Failure Edge Cases

**A1 — sendOtp returns 503**
5xx → 3s retry delay. 75s window allows ~25 attempts. **Safe. ✓**

**A2 — sendOtp returns 200 but requestId is null** ← BUG #2
`ForgotPasswordPreStager.java:99-105` returns `true` even if `getData().getRequestId()` is null.
`requestId_FP = null` → `OtpVerifyRequest(null, ...)` → server likely returns 400/404.
404 triggers false-verified bug (see A3). 400 throws IOException → fallback.
**No explicit null-requestId log — failure mode is silent.**

**A3 — verifySingleOtp returns 404 "otp not found"** ← BUG #3
`AuthClient.verifySingleOtp():540-547` treats 404 "otp not found" as ALREADY VERIFIED.
If IVAC API rejects `requestId_FP` with 404 (wrong session namespace), bot sets
`otpAlreadyVerified=true` when OTP was NEVER verified.
Lane 1 enters slot loop → gets persistent 401 → `RestartSignInException` → full re-signin.
Bot recovers but pre-stage advantage is wasted and the false-verified path is confusing.

**A4 — verifySigninOtp accepts requestId_FP?** ← CRITICAL UNKNOWN
The entire forgot-password pre-stage depends on whether IVAC's `/otp/verifySigninOtp`
accepts a `requestId` from `/forgot-password/sendOtp`.

| Scenario | IVAC API Response | Bot Outcome |
|---|---|---|
| A: Same OTP pool, requestId accepted | 200 + verified=true | Pre-stage works ✓ |
| B: requestId rejected as 404 | 404 "not found" | False-verified → 401s → restart |
| C: requestId rejected as 400 | 400 | IOException → fallback to normal flow |

**Must be confirmed by live test before production use.**

**A5 — Firebase never receives OTP (captureOtp timeout)**
After timeout: `capturedOtp = null` → logs "captureOtp timed out — fallback" → `otpContext = null` → normal flow. **Safe fallback. ✓**

**A6 — signinEarly returns 401 (invalid credentials)**
`AuthClient:469-472`: throws `IOException("Authentication cannot recover")` → propagates to
`AccountWorker` catch block → logs "Stopping worker — unrecoverable auth error" → **worker exits permanently**. ✓

**A7 — Captcha service down during signinEarly retry**
Initial captcha fetched before signinEarly. If stale, first 200 attempt gets HTTP 400 → fetches new captcha. Only 1 extra captcha call. **Safe. ✓**

---

### Cross-Endpoint Edge Cases

**C1 — requestId_FP vs OtpContext.requestId**
`verifySingleOtp` uses `requestId_FP`. OtpContext is built with signin's `requestId`.
Since `otpAlreadyVerified=true`, Lane 0 skips `/otp/verifySigninOtp` entirely.
OtpContext requestId is used only for `storeSessionData`. No collision. **✓**

**C2 — Firebase contamination by new signin OTP**
Sequence: forgot-password OTP captured in memory → signinEarly → new signin OTP may arrive in Firebase → `clearBothSources` removes it before it can contaminate.
`capturedOtp` is in memory, not re-fetched from Firebase. **Safe. ✓**

**C3 — Phone/email consistency**
`sendOtp` body: `{"email":"IVA.CDH.K01.2@gmail.com","otpChannel":"PHONE"}`.
`verifySingleOtp` body: same phone, same email. Consistent. **✓**

---

### Fallback Edge Cases

**F1 — Pre-stage fails, normal flow runs**
Every failure path sets `fpPathSucceeded=false`, `otpContext=null`.
`AccountWorker:264`: `if (otpContext == null)` → normal captchaPrefetch + warmup + auth flow. **Clean fallback. ✓**

**F2 — Stuck-forever path**
`signinEarly` in `AuthClient` has no deadline, no max retries for 503.
If the server never exits 503, the bot loops forever in the pre-stage block.
`PipelineOrchestrator` never starts. This is a legitimate hang risk.

**F3 — Thread/resource cleanup**
Pre-stage wrapped in `try/catch/finally { fpClient.close() }` (`AccountWorker:232-237`).
`primaryClient`, `lane1Client`, `dualPoller` closed in outer `finally`. **Complete cleanup. ✓**

---

## Verdict

### 1. WILL IT WORK?

| Scenario | Verdict |
|---|---|
| Bot starts at 1:20 PM, window at 1:31 PM | **YES — pre-stage runs** |
| verifySigninOtp accepts requestId_FP | **UNKNOWN — must be live-tested** |
| If API accepts requestId_FP | **YES, slot booking starts ~1:31:05 (5s late)** |
| If API rejects with 404 | **FALSE VERIFIED → 401s → restart → normal flow (safe but slow)** |
| If API rejects with 400 | **IOException → fallback → normal flow** |

---

### 2. CRITICAL BUGS

| # | Bug | File:Line | Severity |
|---|---|---|---|
| **Bug 1** | `prestageOtpTimeoutMs` config field is never read — dead config | `AccountWorker:175`, `AppConfig:215` | MEDIUM |
| **Bug 2** | `sendOtpUntilWindow` returns `true` even when `requestId` is null in response | `ForgotPasswordPreStager:99-105` | HIGH |
| **Bug 3** | 404 "not found" on `verifySingleOtp` treated as success (false-verified) | `AuthClient:540-547` | HIGH |

---

### 3. EDGE CASES TO FIX

| # | Issue | Severity |
|---|---|---|
| E1 | `signinEarly` has no deadline — can block forever if server stays in 503 | HIGH |
| E2 | sendOtp 429 pressure → captureOtp gets only 5s floor (1–2 Firebase poll attempts) | MEDIUM |
| E3 | `expiresAt` ISO format → silently falls back to 270s TTL | LOW |
| E4 | **verifySigninOtp + requestId_FP — IVAC API contract unconfirmed** | CRITICAL |

---

### 4. Recommended Fixes

**Fix 1 — Null check on requestId in sendOtpUntilWindow**
`ForgotPasswordPreStager.java:99`:
```java
// Add null check before returning true
if (fpResp != null && fpResp.getData() != null
        && fpResp.getData().getRequestId() != null
        && !fpResp.getData().getRequestId().isEmpty()) {
    requestIdFp = fpResp.getData().getRequestId();
    parseExpiresAt(fpResp.getData().getExpiresAt());
    return true;
}
ConsoleLogger.log(phone, "sendOtp: response missing requestId — fallback", "WARN");
return false;
```

**Fix 2 — Add deadline to signinEarly in the FP path**
`AccountWorker.java:186` — pass `windowStartMs` + buffer as deadline so signinEarly stops if JWT
cannot be obtained before a reasonable time (e.g., window + 60s):
```java
SigninResponse earlySignin = authClient.signinEarly(captchaToken, windowStartMs + 60_000L);
```
Add a deadline parameter and check loop in `AuthClient.signinEarly()`.

**Fix 3 — Remove or use prestageOtpTimeoutMs**
Either wire it into `captureTimeout`:
```java
// AccountWorker.java:175
long captureTimeout = Math.max(appConfig.getPrestageOtpTimeoutMs(),
        windowStartMs - appConfig.getSigninRetryLeadSeconds() * 1000L - System.currentTimeMillis());
```
Or remove the field from AppConfig, SettingSeeder, and the config JSON to avoid confusion.

**Fix 4 — Confirm IVAC API contract**
Before production: test `POST /otp/verifySigninOtp` with a `requestId` from `/forgot-password/sendOtp`.
If rejected: the pre-stage verify must use the signin requestId, which requires capturing the OTP
AFTER `signinEarly` completes (flipping the current order). This is a fundamental design change.
