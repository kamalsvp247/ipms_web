# IPMS Web Memory

## Project Overview
- Laravel 12 + Inertia.js v2 + Vue 3 + TailwindCSS v4 + Java bot (`ipms_java/`)
- MySQL DB: `ipms_db` (prod), `ipms_test` (tests). No SQLite driver — use MySQL for tests
- JDK 26 (Adoptium Temurin) at `/usr/lib/jvm/jdk-26` — both local and VPS
- Pre-existing test failures (ignore): `CaptchaProxyTest`, `BurstOrchestratorTest`, `SlotServiceTest`, `PaymentServiceTest`

## CF Bypass
- [Cloudflare 403 = okhttp UA](kb_cloudflare_403_okhttp_ua.md) — HTML 403 on sign-in was CF blocking OkHttp's default User-Agent; fixed by BrowserHeadersInterceptor (Chrome fingerprint) in IvacHttpClient, blitz_v_3.6. CONFIRMED working. No Referer/Origin. Escalate to CF Worker relay if JA3-blocked
- [CF Bypass details](kb_cf_bypass.md) — OkHttp3 Dns lambda, shared pool (100/15min), bypass_ips table
- [Bypass IP cleanup](project_bypass_ip_cleanup.md) — valid = 500(GET-not-supported)/503; cleanup undo via cache snapshot + restore endpoint
- [IVAC origin topology](kb_ivac_origin_topology.md) — API tier = AWS **EKS** (cluster `9c154d7b…gr7.ap-south-1.eks`, kube-apiserver exposed at 13.232.38.16) behind a **shared/recycled ap-south-1 ALB** (13.206.53.10 now = `*.nykaafashion.com`); **no stable origin IP exists**, api always Cloudflare-only in DNS; bypass_ips empty (bot uses CF). Website origin = 78.128.60.143 (Telehouse). **api 429s are app-level per-account quotas** (login "wait Xs"/reserveSlot/webfile-block), NOT edge limits — origin IP won't fix them; real fix = parse server-stated wait vs flat 20s

## Captcha Bundle Versioning (Rollback)
- [Bundle versioning architecture](project_captcha_bundle_versioning.md) — content-addressed storage, activate older bundles for rollback, unclean extraction repair, `captcha:import-current-bundle` backfill
- [IVAC bundle fetch — proxy-free](kb_ivac_bundle_fetch.md) — CF challenges only HTML, NOT `.js`; `GET /.well-known/` leaks index.html (bundle name + version) challenge-free; download asset by name from any CF edge IP. No BD proxy needed for discovery OR download — verified end-to-end Jun 25 2026. Works during the redeploy "notice" window

## WebSocket (Reverb)
- [Reverb WebSocket setup](project_reverb_websocket.md) — systemd `ipms-reverb`, Apache ws:// proxy at `/app`, frontend uses `wss://ipms.senda.fit/app`; only Gmail page uses it

## Captcha Algorithm Extraction
- In-repo playbook: `docs/captcha-algorithm-playbook.md` — full finding/change-detection strategy, read first on captcha 400 / IVAC redeploy
- [Extraction algorithm](kb_captcha_algorithm_verification.md) — version-dispatch + decoys, ground-truth Node harness, debug protocol, timeline. **Current state Jun 21 2026:** rotated again — bundle `mqnnw71d` (hash `21674522`); **LOGIN and RESERVE both use module `v1` v8, IDENTICAL skip7/enc25 — they differ ONLY by secret.** This broke attribution: dedup-by-`(skip,enc_len)` ran BEFORE labelling and dropped reserve → fixed by collecting labelled sites before dedup in both `_attribute_for_live` (py) + `attributeCallSites` (php); added `secrets_distinct` guard. Attribution rule: **`dateLabel` marks the reserve config** — no enc_len guessing. (Jun 16: was W$ v2 / QX v1.) Extraction-hardening + regression corpus + test-clobbers-live-meta footgun: see [[project_captcha_live_js_engine]].
- [Live-JS engine + sidecar](project_captcha_live_js_engine.md) — monitor verifies PHP byte-for-byte vs live bundle; Node sidecar runs the site's own encrypt code so version rotations need no PHP re-port. **Jun 16 2026:** encryption is now **sidecar-ONLY** (php/live_js/auto selector + `captcha_engine` setting removed); monitor classifies **mid-rollout vs structural** failure (amber vs red banner); Apply-Seed button hidden for a mid-rollout type; raw `analyze_captcha_algo.py` runs desync disk — restore via `CaptchaBundleVersionService::activate()`. **Jun 21 2026:** same-module-same-params dedup bug fixed; the 6-bundle corpus was deleted (gitignored, unrecoverable) and rebuilt on 4 surviving bundles; recovery for any rotation = `CaptchaAlgorithmService::analyze($proxy)` (atomic activate — NOT raw `--bundle` + `/reload`). **Jul 8 2026 (canary/skip=1 bug):** login rotated to **`C$` v2 skip 1/enc 27** (new secret) — skip=1 pulled the Turnstile `.` separator (token idx 1) INTO the transform window; both well-formedness canaries (`captcha_live_runtime.cjs::wellformedReason` + `analyze_captcha_algo.py::_wellformed_output`) rejected the passed-through `.` as out-of-charset → login extraction unclean → stuck on stale login v4 → IVAC 400. Fix: non-alphabet INPUT chars must pass through unchanged; alphabet chars still charset-validated. Also **bumped `ANALYSIS_CACHE_VERSION` v2→v3** (the analysis cache served the pre-fix null output and masked the fix). Restart sidecar after `.cjs` change (a staged/promoted bundle keeps the old canary closure). Corpus test now includes `55ffbe4d`. Full writeup in [[project_captcha_live_js_engine]].
- **Jul 9 2026 (IIFE-keyed method / string-in-brace-walk):** live rotated to `mrd34y1o` (raw `f1814fe5`), **login==reserve share ONE config** = module `y0` v5 skip1/enc29 (secret has a literal backtick — write meta via Python). Analyzer failed ("No encrypt modules exposed / no call sites") because `_enclosing_computed_method`'s backward brace walk was NOT string-aware and a `"cRVI}"` string in the secret concat (config trapped in `static[function(){…}()+…](){…}`) corrupted the depth → method never found → secret null. Fix: string-skip in the backward walk (mirror `_match_paren_back`); bumped `ANALYSIS_CACHE_VERSION` v3→v4 + cleared cache. No `.cjs` change. Recovery = manual sidecar-first meta write + restart, then `analyze('')` heals (atomic activate). Corpus adds `f1814fe5` (secrets_distinct=false). See [[project_captcha_live_js_engine]].
- **Transform Seeds page removed (Jun 2026)** — `CaptchaEncryptionService` ignores seeds entirely (sidecar-only); `CaptchaTransformSeed` model + `CaptchaAlgorithmService` kept for monitor attribution only; `/captcha-transform-seeds` route, controller, Vue page all deleted
- **Algorithm Monitor grid is 5 cells (Jun 2026)** — "Status / ⚠ Update Needed" cell removed from both Login and Reserve panels; was PHP-centric and misleading on live_js
- [Analysis processing cache](project_captcha_analysis_cache.md) — ~3s "processing" = two cold Node evals of the 2MB bundle; content-addressed cache (key = header-stripped bundle identity) makes unchanged-bundle re-analysis ~205ms; bundle-version rows now carry download/processing/healthy timing shown on the monitor

## Captcha Delivery Latency (Jul 2026)
- [Provider racing + fastest delivery](project_captcha_race_delivery.md) — Jul 30 2026: an on-demand captcha is raced across N providers (`CaptchaRaceCoordinator`, `captcha_requests.race_parent_id`, width in Redis `captcha:race_width` default 3); the first token is delivered and **every loser is rewritten as a `source='pool'` row**, so the extra spend is pulled-forward inventory. **Load-bearing invariant: the `on_demand` row is NEVER dispatched to a provider** — it is a pure delivery slot, otherwise "Pending because queued" vs "Pending because its own attempt died" is undecidable. Two stalls bigger than the solve were fixed with it: `PortalCaptchaClient` slept **500ms before its first poll** (POST now returns a pooled token inline when the caller names `type`; opt-in so an old JAR still works), and captcha workers ran `--sleep=1` (now `0.1`). **`block_for` is a trap** — with `--queue=captcha_priority,captcha` a blocking pop stalls the pool filler's queue. Pool claim stays **oldest-first** (expiry 120s vs ~270s Turnstile life, so FIFO costs no freshness and keeps the hit rate). Race attempts carry **no phone** or a width-3 race triples the daily per-account quota. `blitz_v_8.0`

## In-House Captcha Solver (Jul 2026)
- [Self-hosted Turnstile solver](project_in_house_captcha_solver.md) — Node+headless-Chrome sidecar on `127.0.0.1:8788` (systemd `ipms-in-house-captcha`), `/in-house-captcha` page, `InHouseCaptchaClient`. Synthetic-page interception: real URL, swapped body, so the token binds to the real (site key, hostname) and IVAC is never contacted. **Two load-bearing settings, either alone gives ZERO tokens: a writable `HOME` (else crashpad CHECK/SIGTRAP — `www-data`'s `/var/www` is root-owned) and no automation markers (CF 403s `HeadlessChrome` UA / `navigator.webdriver`).** ~87-96% per attempt; the failure is a silent stall with an all-2xx trace, recovered by a fresh context → 10s attempt cap, 3 retries. **Wired in as the `in_house` CaptchaProviderType (Jul 28)** — it is synchronous, so `SolveCaptchaJob` completes the request inline, the poller never sees it (`vendor_task_id` null), and **the job owns the Redis slot accounting**; admin-only on 4 layers incl. `CaptchaProviderController` refusing the type to non-admins

## UI Design System
- [Dense compact design](ui_design_system.md) — emerald/blue/red/orange accents, zinc neutrals
- [Glassmorphism + Dark Mode](glassmorphism_theme.md) — frosted glass, beam + film grain

## JWT Session State Persistence
- [JWT OTP session state](project_jwt_otp_session_state.md) — `is_otp_verified` column + `signinRequestId` stored after sign-in; bot restart fast-paths to slot if OTP already verified, or restores signinRequestId pair if not

## Race Architecture (Java Bot)
- [Tick-based OTP/slot/payment, dual OTP, client assignments](kb_race_architecture.md) — tick schedule, dual FP+signin OTP, slot fires on otpVerifyStarted, constants

## Authentication (Java Bot)
- [Sign-in + forgot-password sendOtp + OTP verify](kb_authentication.md) — request/response shape, retry semantics, parallelism, 3 OTP pairs (sms-fp/email-fp/signin)

## Reserve Slot & Appointment Dates (Jul 2026)
- [Reserve slot ID + appointment date rotation](project_reserve_slot_appointment_dates.md) — reserve URL `/slots/{reserveSlotId}/reserve-slot` uses portal Setting `reserve_slot_id` (deployment constant, NOT appointmentId); per-account `appointment_dates` JSON (from/to range expanded); reserve sends ONE rotated date STRING (array → `invalid.json` 400); sign-in `/auth/sign-in-v4`; api-tester PDF upload x-token; `blitz_v_4.6`

## PDF Upload & Booking Config (Jul 2026)
- [One-time PDF upload + booking-config flow](project_pdf_upload_booking_config.md) — gates slot reserve; per-account `pdfs`(base64)/`pdf_uploaded`/`booking_configured`/`booking_city` cols; upload needs RAW captcha x-token + multipart (no X-Device-ID); parallel upload + sha256 resume cache; slot-auth `/api/accounts/{id}/pdfs` + `/setup-state`; reserveSlotId auto-synced from bundle in `CaptchaAlgorithmService::analyze`; `blitz_v_4.7`

## Auto Payment (Jul 2026)
- [Per-account auto payment](project_auto_payment.md) — toggle on `/accounts` + method/wallet/PIN captured at that moment; headless Chrome (`app/Scripts/dgepay_payment_driver.cjs`) drives the real dg-epay SPA and writes `payment_links.callback_url`, the field the bot's `PaymentCallbackService` already polls. **No Java changes.** Chain: link ingest → `AutoPaymentDispatcher` → `AutoPaymentJob` (redis queue `payments`) → `PaymentAutomationService` → `PaymentCallbackRouter`; per-minute `payments:sweep-auto` backstop. **Double-charge guards are load-bearing:** unique `payment_automation_attempts.payment_link_id`, conditional `pending|failed→running` claim, MAX_ATTEMPTS=3, Redis concurrency cap, is_fake/non-dgepay/existing-callback skips. Driver needs BOTH a writable `HOME` and `PUPPETEER_CACHE_DIR=storage/app/puppeteer` (reuses the captcha solver's Chrome). MFS OTP: `MfsOtpParser` + `otp_codes.is_mfs`, **IVAC classified first** so booking OTPs are never reclassified; driver reads via per-run per-wallet `PaymentOtpTicket`. **Nagad is the only HAR-verified method; Rocket is speculative phone+PIN (HAR shows DBBL card rails) left enabled to fail live; bKash unimplemented.** New systemd unit `ipms-payment-worker` must be started. **Jul 29: the `payment:automation_stop_at` dry-run brake was removed — a dispatched job now charges for real**
- [dg-epay checkout flow (HAR-traced)](project_dgepay_payment_flow.md) — Nagad/Rocket step sequences, `uniquetxnid`==`tran_id`==`reservation_id`, apiv2 apikey/signature guards, no Turnstile on the dgepay/bank path
- [Bank checkout box-inputs trap](kb_bank_checkout_box_inputs.md) — Nagad/DBBL hide the real value in split `maxlength=1` boxes and the NAMED field is a hidden RSA ciphertext holder; typing into it submits an EMPTY value while the gateway still renders the next page. Cost 3 live runs and a wrong "SMS forwarder is broken" diagnosis. `dgepay_payment_driver.cjs --selftest` guards it
- [Click-by-text picks the wrapper](kb_click_by_text_wrapper_trap.md) — `querySelectorAll` returns ancestors first, so "first element containing 'nagad'" is the page wrapper: the helper returns true, no handler fires, and the step dies on a silent 45s timeout (link 379). Rank matches shortest-label-first, verify the click had an effect, and never mouse-click a container

## VPS Manager
- [LightNode provisioning](project_vps_manager.md) — auto-provision/destroy VPS, SSH install, `vps_instances` table, queue worker

## Worker Management
- [Worker deletion cascades](project_worker_deletion.md) — deleting agent slot unassigns all its accounts

## Header Design
- [Dhaka time in header](project_header_dhaka_time.md) — persistent header displays current BDT time + window times centrally

## OTP Ingest API
- [POST/GET /otp endpoint](reference_otp_ingest_api.md) — public, accepts {phone, msg}; shared GET+POST controller

## SMS Forwarder (Android)
- [ipms_sms_android app](project_sms_forwarder_android.md) — hardcoded to /otp, posts {phone, msg} per SMS; no API URL setting

## Scheduler
- [Scheduler wiring](reference_scheduler.md) — root cron `* * * * * cd ipms_web && schedule:run`; bot_logs noise purged every 5 min

## API Tester
- [API Tester architecture](project_api_tester.md) — session persistence, OTP verify payload (code not otp), file overview, booking config auto-save, appointment_id_updated_at, right sidebar

## Workflow
- [Post-change commands](feedback_post_change_commands.md) — chown + chmod + npm run build after every Laravel/Vue change
- [Build & permissions](feedback_build_and_permissions.md) — run `npm run build && chown -R www-data && chmod -R 775` automatically after EVERY change, no prompting
- [PHP OPcache / FPM reload](feedback_php_opcache_fpm_reload.md) — edited PHP needs `optimize:clear` + `systemctl reload php8.4-fpm`; tinker bypasses OPcache so it falsely passes while the live page serves stale code

## Vue / UI Gotchas
- [Reka UI Checkbox API](kb_reka_ui_checkbox.md) — use `:model-value` + `@update:model-value`, never `:checked`/`@update:checked`
- [Laravel encrypted cast crash](kb_encrypted_cast_decrypt_exception.md) — `'encrypted'` cast throws on old APP_KEY; replace with custom Attribute + try/catch (Account.password, User.plain_password fixed)

## Ops / Permissions
- [Captcha storage perms](feedback_captcha_storage_perms.md) — `storage/app/captcha` gets root-owned if script ran as root; fix with `chown -R www-data:www-data`

## Code Style / Feedback
- [English-only comments](feedback_english_only.md) — no Hindi/Urdu in code or logs
- [JAR build rules](feedback_no_jar_build.md) — never `mvn package` as root; use portal button for VPS JAR
- [JavaDoc style](feedback_javadoc_style.md) — plain text only, no HTML tags, bullet dashes for lists
- [UI button styles](feedback_ui_buttons.md) — danger buttons use outline+red, not variant=destructive
- [Don't over-investigate small changes](feedback_minimal_for_small_changes.md) — when user prescribes the fix, skip the cause-and-effect analysis
- [Keep JWT, resend OTP — don't re-signin](feedback_jwt_resend_otp.md) — OTP poll-timeout/verify-mismatch must resend forgot-password (dual channel) while JWT is still valid; restart only when JWT about to expire
- [Don't share research publicly](feedback_no_public_sharing.md) — never expose captcha/bot/IVAC research to external services or public channels
- [Bump bot version on every Java change](feedback_bot_version_bump.md) — update `BotVersion.VERSION` (pattern: `blitz_v_X.Y`) with every Java bot feature or behaviour change
- [Captcha 400 debug protocol](feedback_captcha_debug_protocol.md) — 3-way PHP/JS/Python output comparison first; always verify hex constants with python3
- [Captcha algo fix order](feedback_captcha_algo_fix_order.md) — on "fix captcha algorithm": patch live-JS sidecar FIRST (bot stays live), then fix Python analyzer
- [Short simple summaries](feedback_short_simple_summaries.md) — always a few plain lines: what broke, what changed, what it means. No long write-ups
