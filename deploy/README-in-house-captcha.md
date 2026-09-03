# In-house Turnstile captcha solver

A persistent Node process that keeps headless Chrome warm and mints real Cloudflare
Turnstile tokens locally, so solving does not have to go through a paid provider
(CapMonster / 2Captcha / CaptchaAI).

- Script: `app/Scripts/in_house_captcha_solver.cjs`
- Chrome: `storage/app/puppeteer/chrome/<build>/` (installed by `npx puppeteer browsers install chrome`)
- Default bind: `127.0.0.1:8788` (set `CAPTCHA_SOLVER_URL` in `.env` to point the portal at it)
- UI: `/in-house-captcha` (super admin)

## How it works

Chrome navigates to the **real** page URL from `settings.captcha_page_url`, but the
top-level document response is fulfilled from memory with a synthetic page containing
only the Turnstile widget and Cloudflare's own `api.js`. The URL is never changed —
only the response body — so `location.origin`, `document.domain` and the widget's
referrer all still report the real hostname.

That matters because a Turnstile token is only bound to the pair **(site key,
hostname)**, which is exactly what `siteverify` checks. No request ever reaches IVAC,
so IVAC downtime or its time-gated notice page is irrelevant to solving.

That last claim depends on the synthetic page declaring `<link rel="icon" href="data:,">`.
Without it Chrome falls back to `/favicon.ico` on the page's own origin, and because only
the document is intercepted that request is the one call per solve that genuinely leaves
for IVAC — measured as 2 requests to `ivacbd.com` without the inline icon against 1 (the
locally fulfilled document) with it.

## Install

```bash
npx puppeteer browsers install chrome          # once, and after a puppeteer major bump
sudo cp deploy/ipms-in-house-captcha.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ipms-in-house-captcha
sudo systemctl status ipms-in-house-captcha
curl -s http://127.0.0.1:8788/health | jq
```

## Two settings that are load-bearing

Both were the difference between "never produces a token" and a working solver, so do
not drop them when editing `CHROME_ARGS`:

1. **A writable `HOME`.** Chrome derives its crashpad database path from `HOME`. When
   that fails it spawns `chrome_crashpad_handler` with no `--database` and dies on a
   CHECK (SIGTRAP) before the DevTools port opens. `www-data`'s home is `/var/www`,
   which is root-owned — so every launch from PHP-FPM or a queue worker fails with a
   misleading `Failed to launch the browser process` error. The systemd unit sets
   `HOME`, and the script also repoints it defensively if it is unset or unwritable.
2. **The automation markers must be off.** Chrome's headless UA advertises
   `HeadlessChrome` and Blink sets `navigator.webdriver`. Cloudflare answers the
   widget's challenge request with **403** on either signal, so the widget never
   renders and no callback ever fires. The UA override plus
   `--disable-blink-features=AutomationControlled` is what takes the solver from 0% to
   ~87% per attempt.

A third marker is just as load-bearing but easier to reintroduce by accident:
**`page.exposeFunction()` is detected.** Having the Turnstile callback push the token
straight out through an injected binding is the obvious way to delete the polling loop,
but Cloudflare answers it with a widget that never renders an iframe and never fires
either callback — 0/2 solves in a bisect where the inline favicon, the narrowed
interception and the viewport change each scored 2/2. The page must keep publishing the
token on `window` for `waitForFunction` to read.

## Reliability

A single attempt succeeds ~94% of the time. The failure is a silent client-side stall in
the challenge JS — the network trace is identical and all-2xx, the callback just never
fires — and a fresh incognito context recovers it. The solver therefore caps each attempt
at 10s (about 2x the observed worst-case success latency) and retries up to 3 times
inside a 45s budget. Measured over 238 consecutive solves at concurrency 16: 0 outright
failures, 94% first-attempt, all tokens distinct.

## Where the time goes

Profiling a solve puts ~170ms in local setup (browser context, page, document swap) and
~2.4s waiting on Cloudflare's challenge sequence, whose final request lands at a
near-constant wall-clock offset no matter how fast the earlier ones complete. Roughly
93% of a solve is therefore dwell time imposed by Cloudflare, and **latency is not
meaningfully reducible — throughput comes from concurrency alone.**

Measured on the 16-vCPU portal host:

| Concurrency | Browsers | Success | Throughput | p50 | Cores |
|---|---|---|---|---|---|
| 4 | 1 | 96% | 1.12/s | 2.8s | ~3.5 |
| **9** | **4** | **94%** | **2.08/s** | **4.4s** | **7.9 (capped)** |
| 16 | 4 | 95% | 3.00/s | 4.4s | ~13.5 |
| 24 | 8 | 95% | 2.32/s | 6.4s | ~10 (quota-insensitive) |

Splitting the same concurrency over several browsers keeps p50 down, because one Chrome
process serialises CDP and IPC dispatch for every context it owns. But more browsers is not
monotonic — each one carries a full browser-main/gpu/zygote/utility process set, and 24 over
8 browsers throughputs *worse* than 16 over 4 while burning more overhead. **16 over 4 is the
efficient shape**; treat it as the ceiling of useful concurrency.

**A solve costs ~4 CPU-seconds**, so throughput is bounded by roughly one solve per 4
CPU-seconds of budget. The default is **9 concurrent under a hard `CPUQuota=800%`**, not the
throughput-optimal 16: this host also runs the Java bot, and 16 in flight took ~13.5 of 16
vCPU. Giving up a third of throughput (3.00 → 2.08/s) to halve the CPU footprint is the
intended trade — see the service unit.

### There is no Cloudflare per-IP throttle (corrected Jul 28 2026)

Earlier revisions of this file claimed the success rate collapsed past ~16 in flight because
Cloudflare throttles the egress IP (a "61% at concurrency 24" row, now removed). **That was
wrong, and it mattered — it implied capacity needed more egress IPs.** Two experiments
falsified it:

- **Matched A/B on source IP.** 24 concurrent, once from the single default egress address
  and once spread over 8 distinct source IPv6 (one bound CONNECT proxy per browser, verified
  against `cdn-cgi/trace` so Cloudflare really did see 8 different /128s). Result: **90%
  success and ~2.3 solves/s in both arms.** Spreading source IPs changed nothing.
- **Attempt-cap discriminator.** At concurrency 24 from a single IP, raising
  `CAPTCHA_SOLVER_ATTEMPT_MS` from 10s to 25s took success from 90% to **94%**, and every
  failure in both runs was an attempt-cap timeout, never a 403 or an error-callback.

So per-attempt success is a flat **~94-95% at every concurrency and every source IP** — it is
the same ~6% silent challenge-JS stall documented above, nothing else. What actually happens
past the efficient pool shape is that latency inflates from local queueing until attempts
brush the 10s cap and get abandoned. That reads as "success collapse" but is really the cap
doing its job on a saturated pool.

Consequences for tuning: the ceiling is **local CPU and pool shape, not Cloudflare**. Scale
by adding cores/hosts — and those hosts need no IP diversity. Do not spend effort on IP
rotation for this workload. (This host's 740 addresses are all inside one /64 anyway, which
would have been the next thing to doubt had the A/B come out differently.)

### Sizing against account count

Captcha demand is **not** one token per request. Both hot phases share a single token per
account and refresh it only when it ages past `captcha_shelf_life_ms` (20s) or the server
rejects it — `SigninServiceImpl`'s shared `CaptchaEntry` for sign-in, and
`SharedSlotCaptchaService` for slot reserve. Tick shots do not multiply demand.

Steady-state demand is therefore `accounts / shelf_life_seconds` per active phase:

| Accounts | Slot-phase demand @20s shelf | Solving cores needed (~4.4 CPU-s/solve) |
|---|---|---|
| 40 | 2.0/s | ~9 — fits the 8-core quota |
| 100 | 5.0/s | **~22** — more than this whole host has |

So the 8-core quota supports **~40 accounts** in the slot phase. 100 accounts needs roughly
**22 cores of solving**, which this 16-vCPU box cannot provide while also reserving 8 for the
Java bot. Options, cheapest first:

1. **Raise `captcha_shelf_life_ms`.** Demand falls linearly, so this is by far the biggest
   lever — 100 accounts inside 8 cores needs ~48s. `Constants.TURNSTILE_SHELF_LIFE_MS = 20_000`
   is a conservative local choice, not a protocol limit (Turnstile tokens live ~300s), but
   whether IVAC accepts an older token has to be tested, not assumed.
2. **Keep paid providers enabled** to cover the peak. Vendor solves cost ~0 local CPU.
3. **Add a solver host.** ~22 cores total. It needs **no IP diversity** — see the section
   above; one egress IP per host is fine.

## Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/health` | GET | Chrome status, pool utilisation, lifetime counters |
| `/solve` | POST | `{siteKey, pageUrl, timeoutMs?}` → `{token, ms, attempts}` |
| `/restart` | POST | Relaunch Chrome and re-warm, without restarting the sidecar |

## Tuning

| Env | Default | Notes |
|-----|---------|-------|
| `CAPTCHA_SOLVER_CONCURRENCY` | 9 | Parallel solves. ~4 CPU-s each, so 9 ≈ the 8-core `CPUQuota`. 2.0 GB peak RSS. Raise `CPUQuota` first; above ~16 Cloudflare throttles the egress IP anyway. |
| `CAPTCHA_SOLVER_BROWSERS` | 4 | Chrome processes the concurrency is spread over, least-loaded first. |
| `CAPTCHA_SOLVER_MAX_QUEUE` | 32 | Requests queued beyond concurrency before shedding with 503. |
| `CAPTCHA_SOLVER_ATTEMPT_MS` | 10000 | Per-attempt cap. Successful solves land in ~2.5-6s depending on load. |
| `CAPTCHA_SOLVER_MAX_ATTEMPTS` | 3 | Retries per solve. |
| `CAPTCHA_SOLVER_TIMEOUT_MS` | 45000 | Overall budget per solve request. |
| `CAPTCHA_SOLVER_RECYCLE_AFTER` | 400 | Drain and relaunch a browser after N solves to reclaim memory. |

Recycling is per-browser and drain-based: a slot past the threshold stops accepting new
leases and closes when its own in-flight solves finish. It used to wait for the entire
pool to fall idle, which under sustained load never happens — so a long-lived renderer's
memory was never actually reclaimed.

`captcha.in_house.timeout` (PHP-side HTTP timeout, default 60s) must stay above
`CAPTCHA_SOLVER_TIMEOUT_MS`, or PHP abandons solves the sidecar is still working on.

## Use in the booking pipeline

Registered as the `in_house` `CaptchaProviderType`. Add an **In-House (self-hosted)**
row on `/captcha-providers` to make the booking pipeline use it; delete or disable that
row to fall back to the paid providers. The row takes no API key and reports no balance.

It differs from a vendor provider in that **it is solved by our own machines**, so the
request is queued to the fleet rather than submitted to an API:

- `SolveCaptchaJob` marks the request **Processing** and pushes its id onto
  `captcha:fleet:queue`, then returns in milliseconds. A node leases it, solves it, and
  POSTs the raw token back. `vendor_task_id` stays null, so `PollCaptchaTasksCommand`'s
  vendor path still ignores it.
- **In-house is tried before the paid providers.** `acquireSlot()` partitions providers so
  the fleet goes first and vendors are the overflow, and `FillCaptchaPoolCommand` fills the
  fleet's remaining budget before round-robining the vendors. Fleet capacity is already paid
  for in hardware; vendor credit should only cover what the nodes cannot absorb.
- The fleet's ceiling is `CaptchaNodeFleet::queueLimit()` — the **aggregate** concurrency
  its available nodes report, times a small queueing factor (`captcha:fleet_queue_factor`,
  default 1.5) so nodes never run dry between polls. This replaced the fixed
  `captcha:in_house_slots`, which described one local sidecar and cannot describe a fleet
  that grows and shrinks. With zero nodes online, capacity is 0, in-house is skipped
  entirely, and everything falls through to the vendors.
- **A queue worker is no longer blocked for the duration of a solve.** That was the only
  reason sixteen `ipms-captcha-worker@N` units existed; the count can be reduced now.
- `PollCaptchaTasksCommand::syncProviderSlots()` **no longer skips in-house rows.** It used
  to, because a synchronous local solve went Pending → Ready and was never Processing, so
  reconciling zeroed the counter underneath live solves and their release drove it to −13.
  A fleet solve *is* Processing for the whole lease, so the row count is now the truth and
  skipping it would let drift accumulate instead.
- `CaptchaSolverService::createTask/getTaskResult/getBalance` throw for this type —
  reaching them means something routed an in-house row down the vendor path, where its
  empty API key would be posted to a vendor endpoint.

## The solver fleet

Capacity is bought in cores — a solve costs ~4 CPU-seconds and its latency is ~93%
Cloudflare dwell time — so the only way to scale is more machines. Nodes therefore follow
the Java bot's model: they **pull** work from the portal and expose nothing inbound.

```
SolveCaptchaJob ─LPUSH─► captcha:fleet:queue
                                  │
   node ──POST /api/captcha-nodes/lease (Bearer node key)──► RPOP + claim
   node ── solves locally on its own 127.0.0.1:8788 ──
   node ──POST /api/captcha-nodes/result {request_id, token}──► Ready
```

- **Registry**: `captcha_nodes` (`CaptchaNode`), shaped like `AgentSlot` — 64-char API key,
  90s heartbeat staleness, `pending_command` consumed on read.
- **Install**: `curl -fsSL https://ipms.senda.fit/captcha-install.sh | sudo bash -s -- <KEY>`,
  plus `--profile shared` for a box that also runs `ipms-bot`. The installer adds Chrome's
  shared libraries, Node 22 LTS, puppeteer + Chrome into `/opt/ipms-captcha`, downloads the
  solver from the portal, and sizes concurrency/CPUQuota from the core count
  (dedicated: cores @ 90%; shared: cores/2 @ 40% with `CPUWeight=50`, so `ipms-bot`'s 200
  always wins contention).
- **The portal host is a node too**, running from the repo checkout with its key in
  `/etc/systemd/system/ipms-in-house-captcha.service.d/node.conf`. It does **not** set
  `CAPTCHA_NODE_SELF_UPDATE` — that checkout is the source of truth for the script and must
  never be overwritten by a download.
- **Versioning** is the sha256 prefix of the solver file, computed by the portal and by each
  node self-hashing `__filename`. No constant to bump; drift shows as `update_available`.
- **Commands** off the heartbeat: `update` (re-download + `systemctl restart`), `pause` /
  `resume` (stop leasing but stay online), `set_concurrency:<n>` (live resize, no restart),
  `restart_browsers`.
- **Recovery**: a lease lives 40s, deliberately inside `timeoutStale()`'s 60s so the reaper
  — which can requeue onto a healthy node — wins over the blanket timeout, which can only
  fail the request. An expired lease is retried once, then failed. Work marked Processing
  that never reached the queue is re-pushed after 15s and abandoned after 90s.
- Nodes only ever handle the **raw** Turnstile token. Login/reserve encryption stays on the
  portal, which is the only host with the live IVAC bundle and `encrypt_meta.json`.

Sizing: aggregate `capacity / 2.5` is roughly solves/s, and demand is
`accounts / captcha_shelf_life_ms`. The 16-vCPU portal host contributes 9; a 4-core shared
worker contributes 2.

The stored token is the raw Turnstile token, exactly as a vendor returns; the login and
reserve encryption still happens in `CaptchaRequestController::show` at poll time.

**Admin-only.** `/in-house-captcha` and its API are gated on `bot.manage` (super admin).
Because `/captcha-providers` is open to managers and agents, `CaptchaProviderController`
also refuses the `in_house` type to non-admins, so the provider list cannot be used to
reach the solver around that gate.
