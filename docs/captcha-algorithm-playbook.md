# Captcha Algorithm — Finding & Change-Detection Playbook

Internal notes for the IVAC Turnstile token-transform algorithm: how it works, how to
extract it when IVAC redeploys, how to detect a change, and the mistakes to avoid.
Written June 10 2026 after a full re-derivation. **Keep this in sync with the code.**

> Sensitive: do not paste any of this (tokens, secrets, algorithm) into external
> services or public channels. Local repo / memory only.

---

## 0. What this is

IVAC protects sign-in and slot-reserve with Cloudflare Turnstile. The browser solves
Turnstile, then the IVAC JS bundle **encrypts (transforms) the raw Turnstile token**
before POSTing it to `api.ivacbd.com`. The raw token is never sent. Our portal solves
Turnstile centrally and applies the **same transform** in PHP before handing the token
to the bot. If our transform is wrong → IVAC returns `400 Captcha verification failed`.

Two distinct transforms / token types:

| | LOGIN (sign-in) | RESERVE (slot) |
|---|---|---|
| `type` param sent to portal | `turnstile` | `turnstile_encrypted` |
| Java payload key | `c` (`SigninRequest.c`) | `c` (`Map.of("c", ...)`) |
| PHP method | `CaptchaTokenTransformer::transformLogin()` | `transformReserve()` |
| Seed row (`captcha_transform_seeds`) | `token_type='login'` | `token_type='reserve'` |

Charset (both): `0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_` (64 chars).
Transform window: skip the first `SKIP` chars (the `0.` prefix + a few), transform the
next `ENCLEN` chars, leave the rest. `SKIP`/`ENCLEN` come from the bundle config and are
stored as `offset`/`length` on the seed row.

---

## 1. The single most important fact: the algorithm is VERSION-DISPATCHED

The bundle ships **~10 versioned encrypt modules**. The live config object carries a
`version` field that selects which module runs. **The version — and therefore the
algorithm — changes on essentially every IVAC redeploy.**

Consequence: you **cannot** pick the algorithm by grepping for a magic constant. The
bundle contains several **decoy generators** (real in earlier redeploys, dead now):

- modular-squaring, `MOD = 0xe8d6ca6163 = 1000036000099`, seed `314159265` → was version 9
- polynomial `% 67` → was version 6
- logistic map `3.99 * l * (1 - l)`, skip 100 warmup → was version 10
- RC4-64 KSA/PRGA → an older reserve version
- LCG `1103515245` → used by the *current* reserve (v2) but ALSO present elsewhere

Every one of those constants is grep-able in the live bundle even when it's a decoy.
**Grepping a constant = wrong answer.** This burned a whole afternoon (see §6).

There's also a **self-test trap**: each bundle integrity-tests with `secretKey123`,
`skip=9`, `enc_len=19`. Never apply those.

---

## 2. Current live algorithms (June 11 2026 bundle `mq94v8ib-CCsWYnl1.js`)

**LOGIN = version 6, RESERVE = version 5.** (Were v3/v2 on the June 10 `mq7xwtrf` bundle —
both rotated on the June 11 redeploy. **Both are now ADDITIVE.**) Active seed rows:
- login: `offset` (SKIP) = 7, `length` (ENCLEN) = 23
- reserve: `offset` = 4, `length` = 22

Live config objects in the bundle: `startAt:7,length:23,version:6` (login),
`startAt:4,length:22,version:5` (reserve). Version→module map is `wV` (`5→A0`, `6→J0`);
encrypt fns: login `E0` (schedule `N0`), reserve `p0` (schedule `h0`).

### LOGIN (version 6) — polynomial-in-k mod 67, applied ADDITIVELY (JS N0)

```
coeffCount = max(3, secretLen)
coeff[n]   = (charCode(secret[n % secretLen]) + n) % 67   for n in 0..coeffCount-1
for output position p (k = p + 1):           # k = 1 .. encLen
    acc = 0; pow = 1
    for c in coeff:  acc = (acc + c*pow) % 67;  pow = (pow*k) % 67
    shift = acc % 64
out_char = CHARSET[(idx + shift) % 64]        # ADDITIVE
```
No magic constant. PHP: `transformLogin()` → `loginShifts()`.

### RESERVE (version 5) — three LFSRs + multiplexer, applied ADDITIVELY (JS h0)

```
d=74565, f=424090, w=773615
for each secret char c:  d ^= 1|c;  f ^= (c<<2)|1;  w ^= 1|(c<<4)
for each output char:
    acc = 0
    repeat 6 times:
        t = 1 & (d ^ (d>>2) ^ (d>>3) ^ (d>>5));  d = (d>>1) | (t<<15)   # 16-bit
        n = 1 & (f ^ (f>>1) ^ (f>>2) ^ (f>>7));  f = (f>>1) | (n<<16)   # 17-bit
        r = 1 & (w ^ (w>>1) ^ (w>>2) ^ (w>>22)); w = (w>>1) | (r<<23)   # 24-bit
        m = (t & n) ^ ((~t) & r)        # d-bit multiplexes between f-bit and w-bit
        acc = (acc<<1) | (m & 1)
    shift = acc % 64
out_char = CHARSET[(idx + shift) % 64]        # ADDITIVE (v2 was substitution; v5 is not)
```
PHP: `transformReserve()` → `reserveShifts()`.

NOTE: reserve flipped from SUBSTITUTION (v2 Feistel) to ADDITIVE (v5) on this redeploy.
Always re-derive both algorithms — additive vs substitution and the schedule both change.

---

## 3. Ground-truth extraction — the ONLY reliable method

Never trust a snippet, a constant, or a version number. Execute the real module.

1. **Fetch the bundle through a BrightData/DataImpulse proxy.** Cloudflare blocks
   non-BD IPs. `cloudscraper` via the proxy. (Monitor stores the proxy in localStorage
   `captcha_monitor_proxy`.) Bundle is dumped to `storage/app/captcha/ivac-bundle.js`.
2. **Find the login config:** `iQ(token, config)` call inside SignInPage → the config var
   (e.g. `iU`) = `{secret, startAt (=SKIP), length (=ENCLEN), version}`. Same shape for
   reserve, found near `appointmentDate` / `reserveSlot` context.
3. **Find the version → module map** `oQ = {1:SX, 2:HX, 3:m$, ...}`. Login = `oQ[version]`.
   Each module = `Object.freeze(Object.defineProperty({__proto__:null, decryptText:X,
   encryptText:Y}, ...))`.
4. **Run the real module in Node.** Inject `globalThis.__enc = <encryptFn>;` immediately
   BEFORE the module's `Object.freeze(...)` line, load a DOM stub + the patched bundle in
   Node, then call `__enc(testToken, realSecret, SKIP, ENCLEN)`. Reuse the `DOM_STUB`
   from `analyze_captcha_algo.py`. The script resolves the real (decoded) secret string by
   wrapping the secret var's assignment and reading it back at runtime — robust against
   RC4/obfuscated secret storage.
5. **To decode an obfuscated op** like `r[f(0,406)](a,b)`: expose the base string
   decoders (`globalThis.__FX = FX; __n$ = n$;`), compute the key string, look it up in
   the local `r = {...}` op table. Read the local helper defs (`f(e,t)=FX(t+199)`,
   `a(e,t)=FX(t+700)`, `m(e,t)=n$(e-1183,t)`, etc.).

That gives the authoritative encrypted string. Port it to PHP and verify byte-for-byte.

---

## 4. Where everything lives (code map)

- **Production transform:** `app/Support/CaptchaTokenTransformer.php`
  (`transformLogin` + `transformReserve`). This is what runs in prod.
- **Seeds:** `captcha_transform_seeds` table, one active row per `token_type`.
  `CaptchaTransformSeed::activeForType('login'|'reserve')`. Fields: `seed`, `offset`(SKIP),
  `length`(ENCLEN). Controllers read these and pass to the transformer; fall back to
  hardcoded constants if no row.
- **Solve path:** bot → `POST /api/captcha/request` → `SolveCaptchaJob` (Redis queue
  `captcha`) → provider → `CaptchaRequestController::show()` applies the transform on read.
- **Monitor (change detection):** `/captcha-algorithm-monitor`
  - Python detector: `app/Scripts/analyze_captcha_algo.py`
  - Service: `app/Services/CaptchaAlgorithmService.php` (`analyze()`)
  - UI: `resources/js/pages/CaptchaAlgorithm/Index.vue` (Login + Reserve panels)
  - Snapshots: `captcha_algorithm_snapshots` (change tracking)

---

## 5. The monitor verifies PHP against the LIVE bundle (ground truth, automated)

As of June 11 2026 the monitor no longer compares PHP against a hand-written reference.
`_REFERENCE_JS` was **deleted**. Instead `analyze_captcha_algo.py` runs the **live
bundle's own encrypt module** (via `captcha_live_runtime.cjs`, §3 automated) on the fixed
test token and returns those as `impl_check.{login,reserve}`. `CaptchaAlgorithmService`
compares the PHP transformer's output to them.

The monitor reports two signals per panel:

1. **"PHP Implementation: Matches / DIFFERS from the live bundle"** — the **authoritative**
   check, now true ground truth. If it says DIFFERS, the algorithm **version rotated** and
   the PHP port is stale. There is nothing to keep in sync anymore — the reference IS the
   live bundle. The result is cached as `captcha:php_matches_live:{type}` (drives `auto`).
   Manual reproduction:
   ```bash
   node app/Scripts/captcha_live_runtime.cjs '{"bundle_path":"storage/app/captcha/ivac-bundle.js","jobs":[{"type":"login","module":"J0","token":"0.Abc123-_xYz...","secret":"<login seed>","skip":7,"enc_len":23}]}'
   php -r 'require "vendor/autoload.php"; use App\Support\CaptchaTokenTransformer; echo CaptchaTokenTransformer::transformLogin($t,$s,7,23);'
   ```

2. **"Algorithm / Fingerprint OK / MISSING"** — a whole-bundle **constant scan**.
   **Unreliable** (decoy modules ship those constants). Hint only; #1 is the verdict.

**When IVAC redeploys / captcha starts 400ing:**
1. Open the monitor, paste a BD proxy, **Run Analysis** (refreshes the bundle + meta and
   reloads the encrypt sidecar).
2. If a panel shows **Changed** (offset/length/seed) → click **Apply Login/Reserve Seed**.
3. If a panel shows **DIFFERS from the live bundle** → the algorithm version rotated. Two
   options:
   - **Fastest, no code:** set the Encryption Engine to **Live JS** (sidecar runs the
     site's own code). See §8.
   - **Re-port:** derive the new algorithm via §3, update `CaptchaTokenTransformer.php`,
     re-run, confirm "Matches the live bundle".

---

## 6. What happened June 10 2026 (so I don't repeat it)

Captcha started returning `400`. The journey, with the wrong turns:

1. Tried the **logistic-map** algorithm from a pasted snippet → still 400. (Snippet was a
   decoy / stale.)
2. `git checkout` reverted PHP to **modular-squaring** → still 400. (Also a decoy.)
3. Realised the bundle is **version-dispatched** with ~10 modules and that grepping
   constants only finds decoys. Found the config objects: login `version:3`, reserve
   `version:2`.
4. **Executed the real modules in Node** (§3). Login v3 = a **64-byte cellular automaton**
   (not any grep-able constant). Reserve v2 = **LCG + 8-round Feistel substitution** (not
   the RC4 the PHP had).
5. Two subtle obfuscation traps in the login op-decode: `op1` was `&` (so `charCode & 1`,
   not `+1`) and `op4` was `<` (so `s < 6`, not `s & 6`). Resolved by exposing the base
   decoders and looking up exact op-table keys. Re-verified byte-for-byte.
6. Ported both to PHP, verified against ground truth → login fixed, then reserve fixed.
   User confirmed both work live.
7. **Then the monitor still said "Output DIFFERS"** — because `_REFERENCE_JS` in the
   Python script was still the old modular-squaring + RC4 decoys. Synced it to v3/v2 →
   monitor green. (This §5 lesson is the whole reason that file exists.)

Net lessons:
- Never trust a constant, a snippet, or a version number — execute the live module.
- Login and reserve are independent and can change separately.
- Additive (login) vs substitution (reserve) is a real structural difference.
- After any PHP algorithm change, update `_REFERENCE_JS` too.

---

## 7. Quick "is it broken?" checklist

- [ ] Bot logs show `400 Captcha verification failed` on sign-in (login) and/or reserve.
- [ ] Run the monitor with a BD proxy.
- [ ] Seeds **Changed**? → Apply the seed(s). Done.
- [ ] Seeds match but **DIFFERS from the live bundle**? → algorithm version rotated →
      switch engine to **Live JS** (§8), or re-derive (§3) + update
      `CaptchaTokenTransformer.php` → re-run → "Matches the live bundle".
- [ ] `chown www-data` the edited files; no portal restart needed for seed/PHP changes.

Related memory: `kb_captcha_algorithm_verification.md` (extraction detail + timeline);
`project_captcha_live_js_engine.md` (sidecar + engine).

---

## 8. Live-JS engine — encrypt with the site's own code (no PHP re-port)

For when re-porting PHP on every redeploy is too much: run the live bundle's actual
encrypt module in production.

- **Runtime** `app/Scripts/captcha_live_runtime.cjs` (+ `captcha_dom_stub.cjs`): loads the
  cached bundle headless, neuters the React scheduler, wraps in an IIFE (so reloads don't
  redeclare `__mockEl`), auto-exposes every `encryptText` module. One-shot CLI for §3/§5.
- **Sidecar** `app/Scripts/captcha_encrypt_server.cjs`: persistent process (systemd
  `ipms-captcha-encrypt`, `deploy/ipms-captcha-encrypt.service`, `127.0.0.1:8787`). Bundle
  loaded once (~1s); each encrypt ~0.3ms. `POST /encrypt {type,token,secret,skip,encLen}`,
  `GET /health`, `POST /reload` (auto-reloads on bundle/meta mtime; the monitor calls it).
- **Meta** `storage/app/captcha/encrypt_meta.json`: per-type `{module,version,skip,enc_len}`
  written by the analyzer; tells the sidecar which module is login vs reserve.
- **Engine** `settings.captcha_engine` (default `php`), `App\Services\Captcha\CaptchaEncryptionService`
  used by `CaptchaRequestController::show` + `CaptchaTokenController::storeSolved`:
  `php` (PHP transformer) | `live_js` (sidecar, PHP fallback) | `auto` (PHP until the
  monitor flags a type stale, then sidecar). UI: "Encryption Engine" panel.
- Install/operate: `deploy/README-captcha-encrypt.md`.

NOTE: the params (secret/skip/enc_len) come from the active DB seed, not the bundle — so
still Apply the seed after a redeploy even when using Live JS; only the **algorithm** comes
from the bundle.

---

## 9. Config-object EMISSION shapes (Aug 11 2026 — "No live modules resolved")

The extractor finds login/reserve by anchoring on the `startAt:` key of the captcha config
object, then reading `startAt` / `length` / `version` and the enclosing object's `secret:`.
**IVAC rotates how that object is EMITTED, independently of the algorithm.** Every rotation
so far has broken a *structural assumption* in the scanner, never the anchor itself.

Shapes seen so far, all in `_scan_config_literals`:

| Date | Emission | What broke |
|---|---|---|
| ~Jun 2026 | `const VAR={secret:…,startAt:2,length:19,version:8}` | — (baseline) |
| Jul 2026 | values wrapped: `startAt:Number("2"),length:a[W(…)](Number,"19")` | bare-int regex → fixed by `_read_config_int` |
| Jul 9 2026 | object moved inside `static[IIFE+…](){…}` | `_enclosing_computed_method` not string-aware |
| **Aug 11 2026** | **`const i=(decoy(),decoy(),…,{secret:…})`** | **owner lookup matched `IDENT\s*=\s*\{`; the object no longer follows the `=`** |

The Aug 11 symptom was misleading: the monitor said *"No encrypt modules were exposed from
the live bundle — its module-emission shape likely changed"*, but module exposure was fine
(`captcha_live_runtime.cjs` exposed all 10 modules). The real failure was **zero call
sites** → no version → no module → `"No live modules resolved (version->module mapping
failed)"`. **When you see that message, check the CONFIG scan before the MODULE regex:**

```bash
python3 - <<'PY'
import importlib.util
s=importlib.util.spec_from_file_location('a','app/Scripts/analyze_captcha_algo.py')
a=importlib.util.module_from_spec(s); s.loader.exec_module(a)
js=open('storage/app/captcha/ivac-bundle.js').read()
print('configs:', a._scan_config_literals(js))     # empty => emission shape rotated
print('modules:', len(a._extract_module_map(js)))  # 10 => module regex is FINE
PY
```

The fix made the scan structural rather than prefix-matched: locate the object as the
**innermost brace enclosing `startAt:`** (`_enclosing_open`), then resolve its owner by
walking LEFT out through any comma-sequence parens to the `=` (`_resolve_config_owner`).
That is shape-agnostic — it handles direct, sequence-wrapped, and nested-paren emissions.
Verified byte-identical against all 13 previously-working archived bundles.

### The cache footgun this exposed (fixed)

`analysis_cache/` is content-addressed **by bundle bytes**, so the failed run was cached and
the fix looked ineffective until the entry was deleted by hand. The cache key now also
carries `_EXTRACTOR_FINGERPRINT` (sha256 of `analyze_captcha_algo.py` + `captcha_probe.cjs`
+ `captcha_live_runtime.cjs`), so **any extractor edit invalidates the cache automatically**.
`ANALYSIS_CACHE_VERSION` now only needs bumping for a deliberate payload-shape change.

If you ever suspect a stale cache anyway: `rm storage/app/captcha/analysis_cache/probe_*`.

### Regression corpus

Add every bundle that breaks extraction to `CORPUS` in
`tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php` (keyed by sha256 prefix, fixtures in
`storage/app/captcha/bundles/`). NOTE: older fixtures get pruned off disk, and the test
**skips** rather than fails when a fixture is missing — a green run with mostly `s` means
the corpus has decayed, not that it passed.

---

## 10. Unattended extractor repair (Aug 11 2026)

Sections 1-9 assume a human notices the breakage and edits `analyze_captcha_algo.py`. That
is the slowest link in the chain: a rotation at 03:00 leaves the bot encrypting with a stale
algorithm until someone looks at the monitor. `App\Services\Captcha\ExtractorRepairService`
closes that gap by driving a headless Claude Code session at the extractor.

### How it triggers

`captcha-algorithm:auto-refresh` already distinguishes the two unclean outcomes:

| `extraction_alarm.severity` | Meaning | Action |
|---|---|---|
| `rollout` | IVAC's config selects a version whose module is not in the bundle yet | Wait — theirs to finish, heals itself |
| `structural` | Our extractor can no longer read the bundle | Queue a repair |

On `structural` it caches a repair request (bundle path + hash + analyzer verdict). The
separate `captcha-algorithm:auto-repair` command (also every 5 min, `withoutOverlapping(45)`)
consumes it. Two commands, not one, because a repair can run ~25 minutes and doing it inline
would stall redeploy detection itself.

### Why this is safe: the corpus decides, not the agent

The agent is never trusted to self-assess. Its output faces a deterministic gate:

1. **diff scope** — only `analyze_captcha_algo.py`, `captcha_live_runtime.cjs` and the corpus
   test may change, enforced by diffing the working tree AFTER the agent exits (not by tool
   permissions, which it could satisfy while still editing something else).
2. **corpus** — every pinned historical bundle must still extract exactly as pinned, with
   **zero skips** (`CAPTCHA_CORPUS_STRICT=1`). A fix that repairs the new shape by breaking
   an old one is rejected.
3. **new bundle** — the bundle that broke us must reach `extraction_ok`, both types resolving
   to a module, both well-formed.
4. **live apply** — `CaptchaAlgorithmService::analyze()` must auto-apply, which is what
   actually rewrites `encrypt_meta.json` and reloads the sidecar.

Any failure restores a pre-agent snapshot of every allowlisted file and deletes the probe
analysis caches, so a bad attempt leaves the extractor byte-identical to how it started.
Max 2 attempts per bundle hash; then a human is genuinely needed.

### The corpus is load-bearing now — keep it fed

Guard 2 is the entire safety argument, so the corpus can no longer be allowed to decay:

- Fixtures live in **`storage/app/captcha/corpus/`**, which nothing prunes. They used to live
  in `bundles/`, which `CaptchaBundleVersionService::pruneToLimit()` retention-prunes — every
  pinned fixture aged off disk and the suite quietly ran 1 passed / 6 skipped while still
  reporting green.
- A missing fixture **fails** under `CAPTCHA_CORPUS_STRICT=1` instead of skipping.
- `php artisan captcha-corpus:sync` restores pinned fixtures still present in the archive;
  `--adopt=<hash>` promotes an archived bundle so it can be pinned.
- Run the suite **by path**, not `--filter`: a pre-existing `makeUser()` redeclaration between
  `RbacTest` and `RoleHierarchyTest` fatals any `--filter` run repo-wide.

```
php artisan captcha-corpus:sync
CAPTCHA_CORPUS_STRICT=1 php artisan test --compact tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php
```

### Operating it

- Toggle: `CAPTCHA_AUTO_REPAIR` (default on). Binary override: `CLAUDE_BINARY`.
- Runs as the scheduler user (root), using root's Claude credentials. For unattended runs
  prefer `ANTHROPIC_API_KEY` in the environment over an interactive OAuth token that expires.
- Manual: `php artisan captcha-algorithm:auto-repair --force --bundle=<path>`. `--force` also
  bypasses the booking window.
- Outcome lands in `Cache::get('captcha:extractor_repair:last')` and the log. A successful
  repair commits to the current branch and **never pushes** — the change is already live
  (cron runs the working tree), so the commit only stops it being lost. Review before pushing.

### Booking-window trap

Both commands stand down inside `[window_start_time, window_end_time]` so the sidecar is never
reloaded mid-race. A window of `00:00:00`-`23:59:59` therefore disables **both** permanently.
`App\Console\Concerns\SkipsBookingWindow` now reports that case as a loud warning instead of an
ordinary skip — if you see it, the self-healing is off, not idle.
