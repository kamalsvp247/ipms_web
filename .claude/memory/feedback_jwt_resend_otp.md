---
name: feedback-jwt-resend-otp
description: Never restart sign-in for OTP failures while JWT is still valid — resend forgot-password OTP (both channels in parallel) and keep polling instead. Restart only when JWT is genuinely about to expire.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 73933b43-322d-491c-b241-59e7150a9858
---

When the bot can't obtain a usable OTP — whether the portal poll timed out (no SMS + no email arrived) or `verifyOtp` keeps returning mismatch — DO NOT trigger `RestartSignInException`. Instead:

1. Check JWT validity (via `SessionExpiryValidator` / `raceStartedAtMs + ~880s`).
2. If JWT is still good: resend `forgot-password/sendOtp` on **both channels in parallel** (PHONE + EMAIL), update `smsRequestId` + `emailRequestId` + their `serverTimeMs`, and resume portal polling. Keep the existing JWT — do NOT re-sign-in.
3. Only when JWT is truly about to expire (e.g., remaining < safety margin), fall through to the existing `RestartSignInException` path.

**Why:** Sign-in is expensive — burns a captcha, adds latency, can trip 429s, and the JWT we already have is still authorized for OTP verify + slot + payment. Each unnecessary restart wastes the ~899s JWT window we already paid for. User has confirmed this is the desired behavior (May 2026).

**How to apply:**
- Scope: only the OTP-failure restart paths (poll timeout + persistent verify mismatch). Leave `403 invalid_referer` and `payment 401 ×3` restart logic alone — those are separate triggers with different semantics.
- Resends always go dual-channel via `dualSendOtpAndPoll`-style parallel CompletableFutures, not single-channel.
- Resend loop continues until `raceStart + ~880_000ms` (JWT expiry minus safety margin), then escalates to restart.

Related: [[kb_race_architecture]] for race orchestrator behaviour, [[project_dual_channel_otp]] for the dual-channel sendOtp design.
