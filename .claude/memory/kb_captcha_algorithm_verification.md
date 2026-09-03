---
name: captcha-algorithm-extraction-algorithm
description: "How to extract and verify IVAC captcha encryption constants, debug failures, and understand current algorithm versions"
metadata: 
  node_type: memory
  type: reference
  originSessionId: 7bc04185-4818-488e-b335-9a52a2cc2c3b
---

## Full playbook: `docs/captcha-algorithm-playbook.md`

In-repo playbook with the complete finding/change-detection strategy: version-dispatch + decoys, ground-truth Node extraction (§3), current v3/v2 algorithms, the two monitor signals (`_REFERENCE_JS` must mirror PHP), the June 10 2026 debugging journey (§6), and an "is it broken?" checklist (§7). Read it first when captcha 400s or IVAC redeploys. This memory file is the condensed version.

## CRITICAL: algorithm is selected by config `version` (June 11 2026 bundle: mq94v8ib-CCsWYnl1.js)

**UPDATE June 21 2026 — bundle `mqnnw71d-0oj7jiEX.js` (hash `21674522`):** IVAC rotated to a NEW shape that broke attribution: **login AND reserve use the SAME module `v1` version 8 with IDENTICAL skip 7 / enc_len 25** — they differ ONLY in their secret strings (login `bv7je…`, reserve `e$m#x…`). The old attribution code **deduplicated call sites by `(skip, enc_len)` BEFORE assigning labels**, so the reserve site (`BZ`, labelled reserve) was collapsed into the login site (`LV`) and dropped → "Reserve: no encrypt call site could be attributed" → extraction failed → sidecar kept last-known-good (old algo) → IVAC 400s. **Fix:** collect labelled sites (login/reserve) BEFORE dedup so they can share `(skip,enc_len)`; dedup only the UNLABELLED pool. Applied in BOTH `_attribute_for_live()` (Python) and `CaptchaAlgorithmService::attributeCallSites()` (PHP). Added `secrets_distinct` to the `--attribute` output (regression guard: login & reserve must resolve to different secrets even when params are identical). Recovery: ran `CaptchaAlgorithmService::analyze($proxy)` → clean extract → atomic activate (bundle+meta+sidecar reload); verified prod path byte-correct. See [[project_captcha_live_js_engine]].

**June 16 2026 (LATER — rollout finished, bundle `a061aee`):** login module `W$` v2 (startAt:3,length:23), reserve module `QX` v1 (startAt:7,length:21). Both shipped, resolved clean. The version numbers went DOWN (11→2/1) — proof you must never assume version direction or hardcode a version.

**Earlier June 16 2026 (bundle `mqg9wvds-UEx73nAt.js`) — mid-rollout (now resolved):** sign-in `HV` + OTP `bU` were on version 11 (startAt:5,length:21) with NO module in dispatch table `yV` (ships only 1–10) → `yV[11]` undefined → hook returns null, IVAC's own sign-in button disabled. Was an incomplete deploy, not an extractable algorithm; monitor flags this as severity `rollout` (amber). See [[project_captcha_live_js_engine]].

**Extraction hardening shipped June 16 2026** (see [[project_captcha_live_js_engine]] for full detail): version is now read off the **live config object** (not regex); attribution is **marker-only** (`dateLabel` marks reserve in 6/6 archived bundles) with NO enc_len guessing (fail loud instead); a **well-formedness + distinct-module canary** refuses poisoned/mis-attributed output; new side-effect-free `python3 analyze_captcha_algo.py - --attribute <bundle>` mode; **6-bundle regression corpus test** `tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php`. Test-suite-clobbers-live-meta footgun FIXED via `config('captcha.storage_path')` + global Pest isolation.

The bundle ships **10 versioned encrypt modules** and picks one per config `version` field. **Versions change on every IVAC redeploy** — do NOT trust a pasted snippet; always extract the version actually wired to the live config and verify against ground truth. The "candidate generators" found by grepping constants are mostly DECOYS for other versions — picking by constant alone is wrong. (Example of a full rotation: June 10 was login v3 cellular-automaton + reserve v2 Feistel-substitution; June 11 redeploy → login v6 + reserve v5, and reserve flipped from substitution to additive.)

**How to find the real algorithm (ground-truth method, the only reliable one):**
1. Find live config objects in the bundle: grep `startAt:N,length:M,version:V`. June 11: login `startAt:7,length:23,version:6`; reserve `startAt:4,length:22,version:5`.
2. Find the version→module map. June 11 it is `wV={1:...,5:()=>...A0,6:()=>...J0,...}`. Login=v6=module `J0`, reserve=v5=module `A0`. (The encrypt entry destructures `{secret:o,startAt:i,length:a,version:c}=t` then `const n=wV[c]`.)
3. Each module = `X=Object.freeze(Object.defineProperty({__proto__:null,decryptText:..,encryptText:Y},...))`. June 11: login `J0.encryptText=E0` (schedule `N0`), reserve `A0.encryptText=p0` (schedule `h0`).
4. **Run it for real**: append `;globalThis.__login_enc=J0.encryptText` right AFTER the module freeze statement (not before `const X=` — that breaks the `const`), load DOM-stub + patched bundle in Node, call `__login_enc(testToken, secret, skip, encLen)`. Reuse `DOM_STUB` from `app/Scripts/analyze_captcha_algo.py`. Secret is the real decoded string (script resolves via Node). This is the only check that catches a version rotation — the analyzer's built-in cross-validation only compares PHP vs `_REFERENCE_JS`, both of which can be a stale version.
5. To decode an obfuscated op: prettier-beautify the function body, the dead `if(opaque)` branch full of `_0x…` placeholders is the decoy — read the live (else) branch.

### LOGIN (version 6) — type=turnstile, sign-in — polynomial-in-k mod 67 (ADDITIVE)

JS generator `N0(secret, len)`, applied additively `(idx + shift) % 64`:
```
coeffCount = max(3, secretLen)
coeff[n]   = (charCode(secret[n % secretLen]) + n) % 67   for n in 0..coeffCount-1
for output position p (k = p+1; k = 1..encLen):
  acc=0; pow=1
  for c in coeff: acc=(acc + c*pow)%67; pow=(pow*k)%67
  shift = acc % 64
```
**PHP**: `transformLogin()` → `loginShifts()`. No magic constant.

### RESERVE (version 5) — type=turnstile_encrypted, slot reservation — three LFSRs + multiplexer (ADDITIVE)

ADDITIVE now (v2 was substitution): `out = CHARSET[ (idx + shift) % 64 ]`. JS `h0(secret, len)`:
```
d=74565, f=424090, w=773615
for each secret char c: d^=1|c; f^=(c<<2)|1; w^=1|(c<<4)
for each output char: acc=0; repeat 6×:
  t=1&(d ^ d>>2 ^ d>>3 ^ d>>5);  d=(d>>1)|(t<<15)    # 16-bit LFSR
  n=1&(f ^ f>>1 ^ f>>2 ^ f>>7);  f=(f>>1)|(n<<16)    # 17-bit LFSR
  r=1&(w ^ w>>1 ^ w>>2 ^ w>>22); w=(w>>1)|(r<<23)    # 24-bit LFSR
  m=(t&n)^((~t)&r)        # d-bit muxes between f-bit and w-bit
  acc=(acc<<1)|(m&1)
  shift = acc % 64
```
**PHP**: `transformReserve()` → `reserveShifts()`.

Both PHP outputs verified byte-identical to the live bundle's `E0`/`p0` on test token `0.Abc123-_xYz...` (Jun 11 2026): login `0.Abc12Q10cZ_VGO3pTNBfbcVnQVcV…`, reserve `0.Ab9oqkReVQtWZXiK5jBAbC3v…`. DB seeds (`captcha_transform_seeds`): login `671hnk6v…` 7/23, reserve `@541m3tp…` 4/22.

### Historical generators (now DECOYS in the bundle — were real in earlier redeploys)
- Modular-squaring (MOD `0xe8d6ca6163`=1000036000099, seed 314159265) = version 9
- Polynomial `%67` = version 6
- Logistic map `3.99*l*(1-l)`, fold `/256`, skip 100, `floor(1e7*l)%64` = version 10
- RC4-64 KSA/PRGA = an older reserve version

**PHP class**: `CaptchaTokenTransformer::transformReserve()` → `reserveShifts()`

### Active DB seeds (June 2026)
| type | seed | offset | length |
|------|------|--------|--------|
| login | `mg!b=zdz^y_ly!-#x_e%z65s_$&#!d1@w%@2ux%mr0d)o+6mp9` | 4 | 23 |
| reserve | `l4z&xb9q!7sfon6hhi&p5d1dgyy#f$-y%tx66sdb#0i31xg^ke` | 3 | 20 |

---

## Monitor false "Output DIFFERS" — keep `_REFERENCE_JS` in sync (June 10 2026)

The Algorithm Monitor's "PHP Implementation: Output DIFFERS from JS reference" verdict (Vue `CaptchaAlgorithm/Index.vue`, gated on `login_impl_match`/`reserve_impl_match`) does NOT execute the live bundle — it runs a **hand-written reference** embedded in `analyze_captcha_algo.py` as `_REFERENCE_JS` (its own `loginShifts`/`reserveRoundKeys`/`reserveFeistel` + `transformLogin`/`transformReserve`). `CaptchaAlgorithmService::analyze()` compares `CaptchaTokenTransformer.php` output against this reference (`impl_check.login`/`impl_check.reserve`).

**Therefore: whenever you change the algorithm in `CaptchaTokenTransformer.php`, you MUST also update `_REFERENCE_JS` in `analyze_captcha_algo.py` to the same algorithm** — else the monitor cross-validation falsely reports DIFFERS even though production captcha works. Was stale on modular-squaring (login) + RC4-64 (reserve) decoys; synced to v3 cellular automaton (additive) + v2 LCG+Feistel (substitution). Verify: extract `_REFERENCE_JS`, run in Node on the CV token, compare to `transformLogin`/`transformReserve` PHP output — must be byte-identical.

The separate "Algorithm / Fingerprint" badge (`login_magic_match`=`%67`, `magic_numbers_match`=`1103515245`&`123456789`) is a whole-bundle constant scan — unreliable (decoy modules still ship those constants); `impl_check` is the authoritative signal.

## Debugging Protocol — When Captcha Returns 400

**Step 1**: Cross-validate PHP vs JS output on a test token
```php
// PHP tinker:
$result = CaptchaTokenTransformer::transformLogin($testToken, $secret, $skip, $len);
```
```js
// Node.js:
console.log(encryptLoginCaptcha(testToken));
```

**Step 2**: If they differ, cross-validate BOTH against Python exact arithmetic
```python
# Python (arbitrary precision, always correct):
python3 -c "seed=314257223; mod=0xe8d6ca6163; print(pow(seed,2,mod))"
```

**Step 3**: Identify which side is wrong. PHP + Python agree → JS has floating-point issue. JS + Python agree → PHP constant is wrong.

**Critical lesson (June 2026)**: PHP had `LOGIN_MOD = 1000000000099` but the correct value is `0xe8d6ca6163 = 1000036000099`. The difference of 36,000,000 is invisible at a glance. **Always verify hex literals against decimal** with `python3 -c "print(0xe8d6ca6163)"` before coding them.

**Also verify**: `hex(1000036000099)` = `0xe8d6ca6163` ✓, `hex(1000000000099)` = `0xe8d4a51063` ✗

---

## Attribution — Which Site is LOGIN vs RESERVE

The Python script (`analyze_captcha_algo.py`) detects algorithm type via context-string scan:
- LOGIN markers: `signIn`, `signInButton`, `password`
- RESERVE markers: `appointmentDate`, `dateLabel`, `reserveSlot`
- Scan ±3000 chars around each config object

**Version-number fallback** (unreliable — version numbers change): version 2 = reserve, version 6 = login in June 8 bundle. In June 9 bundle: version 9 = login, version 4 = reserve. **Do not hardcode version numbers**.

**enc_len heuristic** (last resort): larger enc_len = reserve. This ALSO broke in June 9 bundle (login enc_len=23 > reserve enc_len=20). Only use if context-string scan fails.

**PHP `CaptchaAlgorithmService::attributeCallSites()`** uses Pass 0 (context-string `algorithm` field from Python), then Pass 1 (exact skip/enc_len DB match), then Pass 2 (elimination/heuristic).

---

## Snapshot Schema

`captcha_algorithm_snapshots` columns:
- `login_secret`, `login_skip`, `login_enc_len` — LOGIN attribution
- `secret`, `skip`, `encrypt_len` — RESERVE attribution
- `bundle_filename`, `fingerprint`, `created_at`

---

## Config-Object Pattern (June 8 2026+)

Old bundle: `encrypt(token, SECRET_VAR, SKIP, ENCLEN)` direct calls.
New bundle: `CJ(k, configObj)` where `configObj = {secret: ..., startAt: N, length: M, version: V}`.

Detection: scan for `startAt:\s*N,\s*length:\s*M,\s*version:\s*V` literal-integer patterns. Secret extracted by injecting `globalThis.__cfg_VAR = VAR` and running bundle in Node.js.

---

## Proxy for Analysis

Bundle fetched via BD proxy (Oxylabs or DataImpulse). Proxy URL stored in localStorage `captcha_monitor_proxy` on the Algorithm Monitor page. CF blocks non-BD IPs — always use a BD proxy.

---

## Historical Algorithm Timeline

| Period | LOGIN shift gen | RESERVE shift gen |
|--------|----------------|-------------------|
| pre-May 2026 | Polynomial %67 | Polynomial %67 |
| May 2026 | LCG (1103515245, `\|1`) | LCG+Feistel 8-round |
| June 8 2026 | Polynomial %67 (version:6) | LCG+Feistel (version:2) |
| June 9 2026 | **Modular-squaring MOD=0xe8d6ca6163** (version:9) | **RC4-64** (version:4) |
| June 10 2026 | 64-byte cellular automaton (version:3) | LCG+Feistel substitution (version:2) |
| June 11 2026 | **Polynomial-in-k mod 67** (version:6, additive) | **Three-LFSR multiplexer** (version:5, now ADDITIVE) |
| June 14 2026 | **version:8**, module `P1`, skip 7/enc_len 19 | **version:7**, module `u1`, skip 5/enc_len 17 |
| June 16 2026 (am) | **version:11**, NO module (mid-rollout) | version:9, module `J1`, skip 9/enc 19 |
| June 16 2026 (pm) | **version:2**, module `W$`, skip 3/enc 23 | **version:1**, module `QX`, skip 7/enc 21 |
| June 21 2026 | **version:8, module `v1`, skip 7/enc 25** | **version:8, module `v1`, skip 7/enc 25** (SAME module+params as login; only SECRET differs) |

## June 14 2026 redeploy — handled via live_js (PHP NOT re-ported)
Bundle `4232090b…` (hash). Login=v8 module P1 (skip7/enc19, secret `tbp&12…`), reserve=v7 module u1 (skip5/enc17, secret `y%62j!…`). Both run byte-clean in `captcha_live_runtime.cjs`. Engine is **live_js**, so I only: refreshed encrypt_meta + applied DB seeds — did NOT re-port `CaptchaTokenTransformer.php` (it's still v6/v5 = STALE; switching engine to `php` would 400 both). Active DB seeds set to login{offset7,length21→**7/19**} and reserve{**5/17**}; secrets = live bundle secrets.

**Root-cause lesson (the bug that made it 400):** running the Algorithm Monitor refreshes bundle+meta and reloads the sidecar, but it does NOT write the DB seeds — you MUST click **Apply Login Seed** + **Apply Reserve Seed** (route `POST /api/captcha-algorithm/update-seed` → `updateActiveSeed`). The live_js sidecar runs the live module but is fed **secret/offset/length from the active `captcha_transform_seeds` row**, not the bundle — so a stale seed silently 400s even though the sidecar is "healthy". After this redeploy both active seeds were from an older deploy (login 3/21, reserve 2/24) → both broken until applied.

**Analyzer fix (committed):** `encrypt_meta.json` is written by the Python `_compute_live_outputs`, which only emitted a type whose context-scan `algorithm` label was set. v7 reserve (`KZ`/`u1`) scanned as `algorithm:None` → reserve dropped from meta → sidecar 503 for reserve → silent PHP fallback. Added `_attribute_for_live()` in `analyze_captcha_algo.py` (dedup by (skip,enc_len) + context label + **elimination fallback**, mirroring PHP `attributeCallSites`) and wired it into `analyze()`; now both types always land in meta. The PHP-side `attributeCallSites` elimination only fed the snapshot/impl-check, never the meta file — that was the gap.

---

## Decoy / Self-Test Trap

Every bundle has integrity self-tests using `secretKey123`, skip=9, enc_len=19. **Never apply these.** The real production call is identified by bare-identifier seed (not string concat) or config-object `startAt`/`length` literal fields.

---

## Old Architecture Notes

### Polynomial %67 (pre-May 2026)
```
keyArr[n] = (charCode(key[n % keyLen]) + n) % 67
shift[d] = (Σ keyArr[i] * d^i) mod 67, then mod 64
```

### LCG + 8-round Feistel (May–June 2026)
```
seed = sum(charCode[s] * (s+1)) & 0xFFFFFFFF
8 rounds: seed = imul(seed, 1103515245) + 12345 & 0xFFFFFFFF; shift = seed & 7
Feistel encrypt: 6-bit split hi/lo, 8 rounds H$(), reassemble
```
