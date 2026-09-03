---
name: feedback_captcha_algo_fix_order
description: "When asked to fix captcha algorithm after IVAC redeployment, patch the live-JS sidecar first so encryption stays live, then fix Python/PHP"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 29567041-3faf-41c4-9000-55e2bd581bb1
---

When the user says "fix captcha algorithm", "fix cap algo", or similar after an IVAC redeploy, follow this exact order. Do NOT do the old slow full sequential investigation (analyze → diagnose → check Python → fix) before touching encryption.

1. Find what changed in the new bundle — version → module dispatch, skip/enc_len params, and secret for each token type (login + reserve). Needs the BD proxy to fetch the live bundle (see [[reference_bd_proxy]]) unless already wired in.
2. **Patch the live-JS sidecar FIRST** (`captcha_live_runtime.cjs`, sidecar config, `encrypt_meta.json`, module dispatch) so the bot immediately gets correctly encrypted captcha tokens — this is the priority. The sidecar runs the site's own encrypt code, so no PHP re-port is needed.
3. Reload the sidecar (`POST /reload` or restart `ipms-captcha-encrypt`) to make it live.
4. THEN fix `analyze_captcha_algo.py` and supporting code (`CaptchaAlgorithmService`, attribution, etc.) — captcha is already working by now, so this doesn't block the bot.

**Recovery path:** prefer the atomic `CaptchaAlgorithmService::analyze($proxy)` (NOT raw `--bundle` + `/reload`, which desyncs disk). See [[project_captcha_live_js_engine]].

**Why:** The sidecar runs the site's own bundle code — fixing it is fast and makes encryption correct immediately. The Python analyzer and PHP code can be fixed without blocking captcha generation, so they come second.

**How to apply:** Any time the trigger phrase is "fix captcha algorithm" / "fix cap algo" — get the sidecar live first, then do the cleanup.
