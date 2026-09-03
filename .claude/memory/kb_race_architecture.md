---
name: kb-race-architecture
description: "Java bot race architecture — tick-based OTP/slot/payment, dual OTP strategy, client assignments"
metadata: 
  node_type: memory
  type: project
  originSessionId: 3a6d00cb-ef54-40fb-94ff-579f237c0d93
---

## HTTP Client Assignments

- **Primary client** (`IvacHttpClient.direct(phone)`): sendOtp, sign-in, OTP verify (when no bypass IPs)
- **Bypass clients** (`IvacHttpClient.bypass(ip, phone)`): OTP verify, slot reservation, payment (primary excluded)
- If no bypass IPs configured: primary handles everything
- `allSlotClients` = bypass clients only (or [primary] if none)
- `allOtpServices` = one `OtpServiceImpl` per bypass client (or [primary] if none)

## Bypass Mechanism (OkHttp3 Dns)

`IvacHttpClient.bypass(ip, phone)` installs a custom OkHttp `Dns` lambda that maps `api.ivacbd.com` → bypass IP. URL stays `https://api.ivacbd.com/...` so TLS SNI is set correctly automatically. No Host header tricks needed.

Shared `ConnectionPool`: 100 idle connections, 15-min keepalive.

## Tick-Based Architecture (current)

All phases use a tick schedule built by `RaceOrchestrator.buildTickSchedule(items, shots)`:
- `shots` items per tick, cycling round-robin through all items
- If `n > shots`: multiple sequential ticks, last padded with random picks

Per-account config (all with defaults):
| Config field | Default | Purpose |
|---|---|---|
| `signinTickShots` | 10 | Sign-in parallel shots per tick |
| `signinTickIntervalMs` | 1000ms | Sign-in tick interval |
| `otpTickShots` | 10 | OTP verify shots per tick |
| `otpTickIntervalMs` | 1000ms | OTP tick interval |
| `slotTickShots` | 10 | Slot shots per tick |
| `slotTickIntervalMs` | 1000ms | Slot tick interval |
| `paymentTickShots` | 10 | Payment shots per tick |
| `paymentTickIntervalMs` | 1000ms | Payment tick interval |

## OTP Tick Strategy (dual OTP)

Two OTPs coexist per cycle:
- **FP OTP** (`currentOtp` / `currentRequestId`): pre-fetched via forgot-password T-45s before window
- **Sign-in OTP** (`signinOtp` / `signinRequestId`): sign-in triggers a new OTP; Firebase poller captures it by polling for a code *different* from the FP OTP

OTP tick alternates by slot index: even index → FP OTP + `currentRequestId`; odd index → `signinOtp` + `signinRequestId` (skipped if `signinOtp` is null).

OTP expiry: **300 seconds** from issue time. FP OTP sent at T-45s expires at T+255s into the window; sign-in OTP triggered at T-0 expires at T+300s.

On EXPIRED: if `signinOtp` is available → switch to it (no resend). If not → resend OTP via forgot-password, clear Firebase, re-poll.

## Slot Tick Firing Condition

Slot loop polls `context.isOtpVerifyStarted()` every 100ms before firing. Fires as soon as an OTP thread signals it is about to call verifyOtp — NOT on OTP verified. This ensures slots start probing with OTP in-flight, not blindly. **`slotProbeDelayMs` is removed.**

Slot 401 (OTP not yet verified) → returns `ShotOutcome.RETRY` immediately, next tick retries. No internal sleep in `fireOneShot`.

## Key Constants (from Constants.java)

| Constant | Value |
|---|---|
| `SLOT_REQUEST_TIMEOUT_MS` | 70,000ms |
| `INITIATE_REQUEST_TIMEOUT_MS` | 180,000ms |
| `RESERVATION_TTL_MS` | 270,000ms |
| `TURNSTILE_SHELF_LIFE_MS` | 20,000ms |
| `MAX_CONSECUTIVE_401S` | 9 |
| `SLOT_429_BACKOFF_MS` | 20,000ms |
| `PAYMENT_429_BASE_BACKOFF_MS` | 20,000ms |

## JWT Reuse

If `account.getAccessToken()` is non-null and `jwtExpiresAtMs > now + 30s`, sign-in is skipped entirely. Bot instead sends OTP via forgot-password directly and proceeds to race. JWT stored/fetched via `PortalClient.storeJwt()` / portal config response.

## PortalCaptchaClient

Uses JDK `HttpClient` (HTTP/2). Flow:
1. `POST /api/captcha/request` (no `type`) → returns `request_id`
2. Poll `GET /api/captcha/request/{id}?type=<type>` every **500ms** up to **65s**
3. `type=turnstile` for sign-in; `type=turnstile_encrypted` for slot

Token type applied by portal at poll time (not at request time). Shared captcha for slot tick via `SharedSlotCaptchaService`.

## Payment

Only endpoint: `POST /payment/dg-epay/initiate` (ssl/initiate removed). Uses `allClients` (bypass only). Tick-based parallel shots. Extracts `webview_url` / `redirectGatewayURL` / `GatewayPageURL` from response.

**Why:** 2 ticket endpoints removed April 2026; only dg-epay remains.
