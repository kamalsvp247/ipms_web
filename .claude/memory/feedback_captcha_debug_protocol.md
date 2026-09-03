---
name: feedback-captcha-debug-protocol
description: How to debug captcha 400 errors — cross-validate PHP vs JS vs Python before investigating anything else
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 7bc04185-4818-488e-b335-9a52a2cc2c3b
---

When captcha returns 400 "Captcha verification failed", immediately do a 3-way output comparison before investigating attribution, seeds, bypass IPs, or anything else:

1. Run `CaptchaTokenTransformer::transformLogin($testToken, $secret, $skip, $len)` in tinker
2. Run equivalent JS (user's reference or bundle extract) in Node.js on same inputs
3. Run `python3 -c "..."` for exact arithmetic verification

If PHP ≠ JS: find first differing shift → trace which constant is wrong.
Always verify hex literals vs decimal: `python3 -c "print(0xHEXVALUE)"`.

**Why:** June 2026 — PHP had `LOGIN_MOD = 1000000000099` but correct is `0xe8d6ca6163 = 1000036000099`. Difference of 36,000,000 was invisible by inspection, caused every transformed token to be wrong. Wasted ~1 hour investigating attribution, seeds, bypass IPs, and JS floating-point — all of which were fine.

**How to apply:** Any time captcha returns 400, run the 3-way comparison first. Don't skip to other possible causes until outputs are confirmed equal.

## Fast triage for the Algorithm Monitor "Extraction Failed — Bundle Structure Changed" alarm (added Jul 8 2026 — user pushed for speed)

Do these FIRST MOVES in order; do NOT front-load reading `analyze_captcha_algo.py`/service code — the live data diagnoses it in seconds. On Jul 8 a ~3-line canary fix took far too long because I read code first and got bitten repeatedly by the perms + cache footguns below.

1. **`chown -R www-data:www-data storage/app/captcha` BEFORE anything.** A root cron (`captcha-algorithm:auto-refresh`, every 5 min) re-runs analysis as root and re-owns the bundle/meta `root:root`, so a www-data run then dies at `file_put_contents(...ivac-bundle.js): Permission denied` and aborts before extraction. This recurs — re-chown if a run fails on perms. See [[feedback_captcha_storage_perms]].
2. **Run the live diagnosis immediately** as www-data: `CaptchaAlgorithmService::analyze('')` (edge fetch is proxy-free). Dump `extraction_alarm`, `encrypt_meta`, `live_modules`, `login/reserve_impl_match`, `detected_login_*`, `logs`. This alone tells you which type failed, its new module/version/skip/enc_len/secret, and the `[GT] wellformed={...}` verdict.
3. **If wellformed is False but the module ran:** run that module directly via `captcha_live_runtime.cjs` (patchBundle→vm→registry[module](token,secret,skip,enc_len)) and inspect the raw output + `wellformedReason`. A wrong-alphabet or `.`-in-window (skip<2) is a canary false-reject, NOT a broken algorithm — see the Jul 8 skip=1 case in [[project_captcha_live_js_engine]].
4. **After ANY edit to a `.cjs` or the Python canary, clear `storage/app/captcha/analysis_cache/*` AND bump `ANALYSIS_CACHE_VERSION`** — `_run_bundle_once` memoizes probe output by bundle bytes and will serve the pre-fix result, silently masking your fix.
5. **After editing a `.cjs`, `systemctl restart ipms-captcha-encrypt`** (a `/reload`/`/promote` reuses the old in-memory canary/module closure; a staged bundle captured pre-fix code).
6. **Verify the real hot path, not just tests:** `curl -X POST 127.0.0.1:8787/encrypt` for BOTH `login` and `reserve` must return a token (not 500). Then re-run `analyze()` to heal (clean → writes meta + atomic activate + sidecar promote).

**Why:** the diagnosis is fast; the delays are environmental (perms churn + stale cache) and front-loaded reading. Ordering these first-moves correctly turns a multi-cycle debug into one pass.
