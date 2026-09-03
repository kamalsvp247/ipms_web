---
name: project-sms-forwarder-android
description: Android SMS forwarder app that posts every received SMS to the IPMS OTP endpoint
metadata: 
  node_type: memory
  type: project
  originSessionId: 854be714-cc37-4bf2-9990-28fff5d3f055
---

The `ipms_sms_android/` directory inside the repo is a standalone Android app (Kotlin, Compose, package `site.mashmininet.smsforwarder`) that forwards every received SMS to the portal's OTP ingest endpoint ([[reference-otp-ingest-api]]).

**Why:** Bot workers need to receive IVACBD OTP SMS in real time. The Android device sitting on a SIM is the only path for that — it intercepts the SMS via a manifest broadcast receiver and POSTs it to the portal, which parses and stores the OTP in `otp_codes`.

**How to apply:**
- The endpoint URL is **hardcoded** in `data/api/RetrofitClient.kt` to `https://ipms.senda.fit/` — do not reintroduce a user-configurable API URL setting unless asked.
- Request body schema is `{phone, msg}`. `phone` = the SIM owner number from settings (SIM1 or SIM2, picked by `smsMessage.simSlot`); `msg` = raw SMS body. Don't filter the body — the server detects IVACBD itself.
- Originally moved into the repo from a separate git checkout in May 2026; the embedded `.git` was removed at that time. Treat it as part of this repo, not a submodule.
- Build with `./gradlew assembleDebug` (Linux) or `.\gradlew.bat assembleDebug` (Windows). Requires JDK that matches AGP 8.13 / Kotlin 2.0.21.
- Settings screen has 3 sections: SIM Configuration, App Permissions, Danger Zone. No API config section (URL is fixed).
