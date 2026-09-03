---
name: kb_cloudflare_403_okhttp_ua
description: "IVAC Cloudflare 403 (HTML block) on sign-in was OkHttp's default User-Agent; fixed by BrowserHeadersInterceptor sending a Chrome fingerprint"
metadata: 
  node_type: memory
  type: project
  originSessionId: 1f2236e0-9ebe-4229-9f70-9c4f83014d6f
---

**Symptom (July 2026):** every `POST /auth/sign-in-v2` (and all IVAC calls) returned **403 with an HTML body** (`bot_logs.response_body="html"`, `remote_ip=api.ivacbd.com`), console `Sign-in 403 on api.ivacbd.com — thread exiting`. 15k+ per window, all threads exiting.

**Diagnosis:** HTML 403 = **Cloudflare edge block**, NOT the IVAC app (app always returns JSON; only CF returns an HTML "you've been blocked" page). Cause was IVAC tightening Cloudflare bot management, which flagged OkHttp's default `User-Agent: okhttp/x.y.z`. Confirmed our code did NOT regress — the production request builders (`networking/*`, `service/*`, `HttpUtil`) **never** sent a custom UA (only test files `OriginIpVerifyTest` ever had `PostmanRuntime`); the sign-in path always relied on OkHttp's default UA.

**Fix (CONFIRMED WORKING):** `ipms_java/.../networking/BrowserHeadersInterceptor.java` — added to `IvacHttpClient.baseBuilder` (before `ApiLogInterceptor`), stamps a Chrome fingerprint on **every** IVAC request: `User-Agent: Mozilla/5.0 …Chrome/126…`, `Accept`, `Accept-Language`, `sec-ch-ua*`, `sec-fetch-*`. Bot version `blitz_v_3.5 → blitz_v_3.6` (commit `e1e3bdd`).
- **NO Referer/Origin** — the IVAC app rejects Referer with `403 {"error":"invalid_referer"}` (app-level, distinct from the CF block).
- **Accept-Encoding left unset** so OkHttp keeps transparent gzip decode.

**Deploy:** repo push alone does nothing — must Rebuild JAR (`POST /api/bot/package`) + re-run install one-liner on slots.

**Escalation if CF starts blocking again:** this only fixes the **header-level** check. If CF fingerprints the **TLS handshake (JA3)** — OkHttp can't disguise that — headers won't help; fall back to the CF Worker relay (`cf-worker/ivac-cf.js`, deployed at `CF_WORKER_URL` in `.env`) or a residential/BD proxy. See [[kb_cf_bypass]], [[kb_ivac_origin_topology]], [[kb_authentication]].
