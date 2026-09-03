---
name: kb-authentication
description: "Java bot authentication deep-dive — sign-in, forgot-password sendOtp, and OTP verify endpoints with request/response shape, retry semantics, parallelism, and key state stored in RaceContext"
metadata: 
  node_type: memory
  type: reference
  originSessionId: 1a88acc8-c011-4d78-8453-eb5c14a40770
---

# Authentication Knowledge Base

Covers the three IVAC auth endpoints the Java bot calls and how the bot orchestrates them. Source files are linked inline.

Base URL: `Constants.BASE_URL = "https://api.ivacbd.com/iams/api/v1"` ([Constants.java:10](ipms_java/src/main/java/com/ivac/booking/Constants.java))

Cross-references: [[kb-race-architecture]] for the OTP/slot/payment race; [[reference-otp-ingest-api]] for the portal-side `/otp/{phone}` consume API; the OTP "which one is picked" rule lives in `OtpCode::consumeForPhone` (newest unconsumed row matching phone + channel + `fetched_at >= since - 2s`, locked + marked consumed in one transaction).

---

## 1. Sign-In — `POST /auth/sign-in-v2`

**File**: `ipms_java/src/main/java/com/ivac/booking/service/signin/SigninServiceImpl.java`

### Request

```json
{
  "phone": "...",
  "email": "...",
  "password": "...",
  "captcha": "<turnstile-raw-token>"   // type=turnstile, NOT turnstile_encrypted
}
```
Sent via `client.postUnauthenticated(url, request)` — no Authorization header (this call mints the JWT). Captcha is the raw Turnstile token from `PortalCaptchaClient.requestCaptcha("turnstile", phone)` (no ft-transform — that's only for slot reservation).

### Response (200)

```json
{
  "data": {
    "accessToken": "<JWT>",
    "expiresAt": 899,              // SECONDS, not ms; default 899 if missing/<=0
    "requestId": "<signinRequestId>"   // also triggers an OTP server-side
  },
  "serverTime": "2026-05-21T07:30:00.123Z"   // ISO-8601, used as `since` for portal OTP poll
}
```

Persisted by `doSignin`:
- `accessToken` → `SigninResult.accessToken` → `RaceContext.setAccessToken` → propagated to every `IvacHttpClient` via `context.registerTokenListener(c::setAccessToken)` ([AccountWorker.java:169](ipms_java/src/main/java/com/ivac/booking/worker/AccountWorker.java))
- `expiresAt` (seconds) → `expiresAtMs = now + expiresAt*1000` → `SessionExpiryValidator`. JWT life = **899s**.
- `requestId` → stored as `signinRequestId` (the third OTP pair, alongside sms-fp + email-fp)
- `serverTime` → `signinServerTimeMs` (used as the `since` cutoff so portal poll ignores stale rows from prior cycles)
- JWT stored back to portal async via `portalClient.storeJwt(phone, freshToken, now, expiresAtMs)` for reuse on next bot restart (IVAC blocks re-sign-in during the 15-min window)

### Parallel architecture (`signinAtWindowTime`)

Sign-in is **multi-shot, multi-client, tick-based**:
- Waits until `windowStartMs - SIGNIN_PROBE_LEAD_MS` (100ms lead) then fires the first tick.
- Tick schedule via `RaceOrchestrator.buildTickSchedule(httpClientList, signinTickShots)` — interleaves N tick slots across the K clients (primary + each bypass). `signinTickShots` and `signinTickIntervalMs` are per-account.
- Every tick: any clients not in 429 backoff fire one signin request in parallel on **virtual threads**.
- First success → `winner.complete(result)` → cancels all in-flight calls on every client (`other.cancelInFlightCalls()`).

### Captcha management

- One initial token from `captchaFuture.get()` is passed in by `AccountWorker`.
- If stale (`isOlderThan(captchaShelfLifeMs)` — default 20s, `Constants.TURNSTILE_SHELF_LIFE_MS`), main loop fetches a fresh one. **Captcha refresh happens in the main thread only.** Virtual threads only flip `valid=false` via CAS on the shared `CaptchaEntry` record.
- `CaptchaEntry` uses **object identity** (`==`) to detect stale references — if `entryRef` has already been replaced when a late `400 captcha expired` arrives, it's ignored.
- Hard cap: `MAX_CAPTCHA_BUDGET = 100`. After 100 tokens rejected, throws `AuthenticationFatalException`.

### Status-code handling (`fireSingleSignin`)

| Status | Outcome | Behavior |
|--------|---------|----------|
| 200 success | SUCCESS | Cancel all in-flight, complete winner |
| 400 | CAPTCHA_EXPIRED | CAS-flip the entry's `valid=false`; main loop fetches fresh token |
| 401 | FATAL | `AuthenticationFatalException("Invalid credentials (HTTP 401)")` — stops the worker permanently |
| 403 | RETRY | Log + thread exits; tick loop continues |
| 429 | RATE_LIMITED | See below |
| Other / IOException / AuthFatal in `doSignin` | RETRY | Quiet retry |

### 429 handling — two modes

1. **Server-side cooldown** — body parseable as `"You can log in after X minute(s) and Y second(s)"` (`RetryUtil.parseSigninRetryAfterMs`):
   - All clients pause via shared `signin429BlockedUntilMs` (`accumulateAndGet`, take max).
   - While blocked, **captcha refresh also pauses** (no wasted tokens during cooldown).
2. **No retry-after parseable** — per-client backoff via `client429Count` + `RetryUtil.get429BackoffMs(count)`. Only that client backs off; others keep firing.

### Body-level failures

`doSignin` rejects the response if `data.accessToken == null`:
- Message contains "invalid" or "credential" → `AuthenticationFatalException` (worker stops).
- Otherwise → `IOException` → tick loop retries.

### Sign-in retry / restart triggers

`AccountWorker.runCycle`:
- `CaptchaInvalidException` → fetch fresh captcha and retry once.
- `AuthenticationFatalException` → stop worker.
- Any other exception → `RestartSignInException` → next cycle.

---

## 2. Forgot-Password Send OTP — `POST /forgot-password/sendOtp`

**File**: `ipms_java/src/main/java/com/ivac/booking/service/otp/OtpServiceImpl.java` (`sendOtp`)

### Request

```json
{
  "email": "...",
  "phone": "...",
  "otpChannel": "PHONE" | "EMAIL"   // toIvacChannel: "sms" → "PHONE", "email" → "EMAIL"
}
```
Sent via `sendClient.postRawNoAuthRetry("/forgot-password/sendOtp", body)` — no JWT.

`sendClient` is picked random per attempt from `sendOtpClients` (= bypass clients if any, else primary). On every IOException it rotates to a fresh random client.

### Response (200)

```json
{
  "data": {
    "requestId": "<fpRequestId>"
  },
  "serverTime": "2026-05-21T07:29:15.987Z"
}
```

`requestId` and `serverTimeMs` (parsed via `Instant.parse(...).toEpochMilli()`) returned as `SendOtpResult(requestId, channel, serverTimeMs)`. If response can't be parsed → `parseServerTimeMs` falls back to `System.currentTimeMillis()`.

### When it's called

1. **Pre-window dual flow** ([AccountWorker.dualSendOtpAndPoll:324](ipms_java/src/main/java/com/ivac/booking/worker/AccountWorker.java)): at `windowStart - forgotPasswordLeadSeconds` (default 45s), fires SMS + EMAIL in parallel via `CompletableFuture.supplyAsync`. After both return, applies requestId + serverTimeMs to RaceContext and starts a portal poll per channel.
2. **In-window email arming** (`fireEmailSendOtpAsync`): when alreadyInWindow and no email cache, fires EMAIL sendOtp in parallel with sign-in.
3. **JWT reuse + in-window**: dual sendOtp again before window because cached pairs may be from a previous, expired-OTP cycle.
4. **Dual-channel resend on OTP failure** ([RaceOrchestrator.resendBothChannelsParallel:435](ipms_java/src/main/java/com/ivac/booking/worker/RaceOrchestrator.java)): triggered when any verify-side failure (poll timeout, EXPIRED, CODE_MISMATCH) is detected mid-race. Single CAS gate (`dualResendInProgress`) — only one resend runs at a time.

### Retry semantics (inside `sendOtp` loop)

| Condition | Action |
|-----------|--------|
| 429 (any form) | Throw + retry with `jitteredDelayMs(SEND_OTP_429_BACKOFF_MS * consecutive429s)` = 15s × N |
| HTTP 4xx (non-429) | Sleep 3s, rotate client, retry |
| IOException other | Sleep `SEND_OTP_RETRY_DELAY_MS` = 2s, rotate client, retry |
| 2xx but empty body / null requestId | Sleep 2s, retry (same client) |
| `now >= deadlineMs` ("window opened — aborting") | Throw IOException, give up |
| Thread interrupted | Throw InterruptedException |

`deadlineMs` is `windowStartMs` (do NOT keep retrying past window open — race needs to start) for the pre-window call. The mid-race resend call uses `Long.MAX_VALUE`.

### What happens to the returned requestId

For SMS sendOtp: stored as `context.smsRequestId` + `smsServerTimeMs`.
For EMAIL: `context.emailRequestId` + `emailServerTimeMs`.
For the sign-in response's requestId: `context.signinRequestId` + `signinServerTimeMs`.

These three pairs are the OTP race "lanes":
- `sms-fp` (forgot-password SMS)
- `email-fp` (forgot-password EMAIL)
- `signin` (sign-in response, SMS channel, IVAC server may or may not actually send a new SMS)

The portal background pollers (`backgroundPoll("sms")` / `backgroundPoll("email")`) keep filling in OTPs for whichever pair has a requestId+serverTime but no OTP yet, using the smallest `since` across waiting pairs of that channel. On SMS, two pairs may share the channel — FIFO: oldest `serverTime` gets the first arriving OTP, next OTP fills the second pair.

---

## 3. OTP Verify — `POST /otp/verifySigninOtp`

**File**: `OtpServiceImpl.verifyOtp` (lines 148–220)

### Request

```json
{
  "requestId": "<from fp or signin>",
  "phone": "...",
  "email": "...",
  "otp": "<6-digit>",
  "otpChannel": "PHONE" | "EMAIL"
}
```
Sent via `client.postRawNoAuthRetry("/otp/verifySigninOtp", request, 70_000)` — 70s read timeout override.

**No Authorization header** — verify is unauthenticated; the requestId is the only credential.

### Response shapes

**200 verified**:
```json
{
  "data": { "verified": true },
  "serverTime": "..."
}
```
→ `OtpVerifyResult.verified(serverTime)` → `context.setOtpVerified(serverTime)` (CAS); first winner triggers `PortalLogShipper.muteOtpVerifyLogs(phone)` to stop log spam from losers.

**200 not verified**:
```json
{
  "data": {
    "verified": false,
    "verificationStatus": "EXPIRED" | "CODE_DOES_NOT_MATCH" | ...
  },
  "message": "...",
  "serverTime": "..."
}
```
Reason resolution: `verificationStatus` if present, else top-level `message`. Lowercased and matched against:
- "expired" → `OtpVerifyResult.expired()` → triggers dual-channel resend (NOT a sign-in restart while JWT is valid)
- "does not match" / "mismatch" → `OtpVerifyResult.mismatch()` → triggers dual-channel resend
- anything else → `OtpVerifyResult.failed(reason)` → tick loop retries

**403**:
- Retry with `Constants.OTP_VERIFY_403_BACKOFF_MS = { 2s, 4s, 8s, 16s }` (jittered, cap at last value). Recursive call increments `attempt`. **No exit condition** — recurses indefinitely until 200/404 or non-403 status.

**404**:
- Body lowercased; if contains "not found" → treated as **already verified** (`OtpVerifyResult.alreadyVerified(serverTime)`). This is the idempotency contract — IVAC garbage-collects consumed requestIds.
- Otherwise → `OtpVerifyResult.failed("unexpected 404")`.

**Other non-200**:
- Throw `IOException("OTP verify HTTP " + status)` → tick loop catches and retries.

### Parallel architecture (`RaceOrchestrator.startOtpTickLoop`)

- One verify tick fires `otpTickShots` parallel requests across `allOtpServices` (one OtpServiceImpl per bypass client, or just primary if no bypass).
- Each tick selects ready pairs (those with both requestId AND otpCode loaded) — round-robin: `readyPairs.get(slotIdx % readyPairs.size())`.
- First `verified=true` wins via CAS in `context.setOtpVerified(...)` — losers' completions become no-ops.
- Pre-tick wait loop: if no pair is ready before `otpTimeoutMs + 5s`, check JWT — if expired → `RestartSignInException`; else fire dual-channel resend and keep waiting. Repeats until either an OTP loads or JWT expires.
- On EXPIRED / CODE_MISMATCH: `handleStaleOtp` → dual-channel resend (under single CAS gate). On any per-pair resend response, `applyResendToPairs` updates that pair (and signin pair too, since signin shares the SMS channel) with the new requestId + serverTime and **nulls the current OTP** so background poller fetches fresh.

### Critical invariant: keep JWT, resend OTP

Recorded in [[feedback-jwt-resend-otp]]: OTP verify failures (poll timeout, EXPIRED, CODE_MISMATCH) must NOT trigger sign-in restart while the JWT is still valid. Only `SessionExpiryValidator.isSessionExpired()` justifies `RestartSignInException`. Otherwise: dual-channel resend, keep racing.

---

## RaceContext state related to auth

Field | Source | Used by
------|--------|--------
`accessToken` | sign-in success | every `IvacHttpClient` (Authorization header) via token listeners
`sessionExpiryValidator` | sign-in `expiresAt` | JWT-expiry gates on OTP failure + race timeout
`smsRequestId` + `smsServerTimeMs` + `smsOtp` | sendOtp(sms) + portal poll | sms-fp verify pair
`emailRequestId` + `emailServerTimeMs` + `emailOtp` | sendOtp(email) + portal poll | email-fp verify pair
`signinRequestId` + `signinServerTimeMs` + `signinOtp` | sign-in response + SMS portal poll | signin verify pair
`otpVerified` (CAS bool) + `otpVerifiedServerTimeMs` | first 200 verified | unblocks slot threads; also used in smart 401 detection for slot reservation

---

## Quick reference: endpoint summary

| Endpoint | Method | Auth | Caller | Captcha | Idempotent |
|----------|--------|------|--------|---------|------------|
| `/auth/sign-in-v2` | POST | none | `SigninServiceImpl.doSignin` | yes (turnstile raw) | no — produces fresh JWT each call |
| `/forgot-password/sendOtp` | POST | none | `OtpServiceImpl.sendOtp` | no | no — each call mints a new requestId + sends a new OTP |
| `/otp/verifySigninOtp` | POST | none (requestId is the credential) | `OtpServiceImpl.verifyOtp` | no | yes — 404 "not found" treated as success |

## Files

- `ipms_java/src/main/java/com/ivac/booking/service/signin/SigninServiceImpl.java`
- `ipms_java/src/main/java/com/ivac/booking/service/otp/OtpServiceImpl.java`
- `ipms_java/src/main/java/com/ivac/booking/service/otp/OtpService.java` (interface)
- `ipms_java/src/main/java/com/ivac/booking/service/PortalOtpClient.java` (portal OTP fetch — the "which OTP" rule)
- `ipms_java/src/main/java/com/ivac/booking/worker/RaceOrchestrator.java` (`startOtpVerifyPhase`, `backgroundPoll`, `applyOtpToWaitingPairs`, `handleStaleOtp`, `resendBothChannelsParallel`)
- `ipms_java/src/main/java/com/ivac/booking/worker/AccountWorker.java` (`runCycle`, `dualSendOtpAndPoll`, `fireEmailSendOtpAsync`, FP-pair caching)
- `ipms_java/src/main/java/com/ivac/booking/util/RetryUtil.java` (`parseSigninRetryAfterMs`, `get429BackoffMs`, `jitteredDelayMs`)
- `ipms_java/src/main/java/com/ivac/booking/util/SessionExpiryValidator.java` (JWT expiry)
- `ipms_java/src/main/java/com/ivac/booking/Constants.java` (BASE_URL, OTP_VERIFY_403_BACKOFF_MS, TURNSTILE_SHELF_LIFE_MS)
- `app/Models/OtpCode.php` (portal-side `consumeForPhone` — newest-unconsumed selection)
- `app/Http/Controllers/Api/OtpController.php` (`GET /api/otp/{phone}`)
