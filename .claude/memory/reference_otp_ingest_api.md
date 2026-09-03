---
name: reference-otp-ingest-api
description: Public OTP ingest endpoint on the IPMS portal — accepts SMS payloads from the Android forwarder and other clients
metadata: 
  node_type: memory
  type: reference
  originSessionId: 854be714-cc37-4bf2-9990-28fff5d3f055
---

The IPMS portal exposes a public OTP ingest endpoint that accepts both `GET` (query string) and `POST` (JSON body):

- URL: `https://ipms.senda.fit/otp`
- Methods: `GET` and `POST`
- Auth: none (public, CSRF excluded for POST in `bootstrap/app.php`)
- Params/body: `phone` (string, max 20) and `msg` (string)
- Controller: `App\Http\Controllers\OtpIngestController` (single `__invoke`, shared between GET and POST)
- Routes: `routes/web.php` — `otp.ingest` (GET) and `otp.ingest.post` (POST)
- Response: `{ id, phone, otp_code, is_ivacbd, fetched_at }` — `otp_code` is parsed by `App\Support\OtpMessageParser` only when the body looks like an IVACBD message

The Android SMS forwarder ([[project-sms-forwarder-android]]) posts to this endpoint on every received SMS, sending the SIM owner's configured number as `phone` and the SMS body as `msg`.
