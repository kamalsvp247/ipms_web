# Captcha Provider Racing & Fastest Delivery (Jul 30 2026)

An on-demand captcha is now raced across several providers at once, and a pooled token is
handed to the caller inside the POST instead of on a follow-up GET. Added 2026-07-30.

## Why

A single provider decided the latency of a request the booking window cannot wait for.
Solve times are heavy-tailed — a vendor that normally answers in four seconds occasionally
takes twenty, and an in-house node can complete the whole Cloudflare challenge and still
return nothing (`kb_captcha_node_silent_cf_decline.md`) — so whichever account drew the
outlier paid for it in full and then paid again for the retry.

Measuring the whole path first turned up two stalls **larger than the solve itself**, both
of which were pure waiting:

| Step | Before | After |
|------|--------|-------|
| Bot sleeps before its *first* poll | **500ms**, every request | 0 — POST returns the token |
| Queue worker picks up an on-demand job | avg **500ms** (`--sleep=1`) | avg 50ms (`--sleep=0.1`) |
| Solve itself (pool miss) | one provider, 2–20s | min of N providers |

## Racing — `App\Services\Captcha\CaptchaRaceCoordinator`

- New column `captcha_requests.race_parent_id` (nullable, no FK, indexed with `status`).
- Width lives in Redis `captcha:race_width` (default 3, clamped 1–8) because the web tier,
  the queue workers and the poller are separate processes that must agree, and widening it
  mid-window must not need a redeploy.
- Provider order: in-house fleet first (fastest and free), then vendors through the shared
  `CaptchaVendorRotation`. Leftover width goes **back to the fleet only** — a node solve
  costs hardware already bought, and a second one covers a silently-declining node.
- Losers are not discarded: a solved attempt that arrives second rewrites itself as a
  `source='pool'` row, so the extra spend is pulled-forward inventory. The bill only really
  grows while demand outruns the pool, which is exactly when latency is worth paying for.

### Row roles in `captcha_requests` (told apart by `source`)

| source | role |
|--------|------|
| `on_demand` | the row the bot polls — a **pure delivery slot** |
| `race` | one solve attempt, linked to its slot via `race_parent_id` |
| `pool` | solved inventory, and where losing attempts land |

**Load-bearing invariant: the `on_demand` row is NEVER dispatched to a provider.** An earlier
design had it race itself to save a row. That makes "is this slot Pending because it is still
queued, or because its own attempt just died?" undecidable, which strands requests either way
you resolve it. Keeping it a pure delivery slot makes the state machine total:
Pending → Ready (promotion) | Failed (last attempt gone).

### Settling

Every terminal transition funnels through the coordinator so both the vendor path
(`PollCaptchaTasksCommand::processResponse`) and the fleet path (`CaptchaNodeFleet::complete`)
behave identically:

- `settleSolved` — not racing → plain Ready. Racing → `promote()` takes a `lockForUpdate` on
  the slot and transitions it out of Pending exactly once; the winner row is then **deleted**
  (a Turnstile token is single-use, so a second copy in the pool would be handed out and
  rejected by IVAC). A loser rewrites itself as a pool row.
- `settleFailed` — marks the attempt Failed, then fails the slot **only** once no sibling is
  still Pending/Processing. Nothing in the vendor or fleet path can fail a slot otherwise, so
  without this the bot polls a dead race for its full 65s budget.
- `PollCaptchaTasksCommand::timeoutExhaustedRaces()` is the backstop: the bulk 60s timeout
  writes straight to the table and tells the coordinator nothing, so a slot older than 60s
  with no live attempt is failed there.

### Fail-fast for shadows

`SolveCaptchaJob` re-queues after 1s when every provider slot is busy. A racing attempt must
**not** do that — it would deliver a token after its siblings already answered — so an attempt
with a `race_parent_id` fails instead, and its siblings carry the request.

### Attempts carry no phone

`captcha_daily_limit_per_account` counts `captcha_requests` rows by phone. Attempt rows are
created without one, or a width-3 race would burn 3× each account's daily quota per request.

## Inline delivery — `POST /api/captcha/request`

Accepts an optional `type` (`turnstile` | `turnstile_encrypted` | `raw`). On a pool hit it
consumes, encrypts through the sidecar and returns
`{request_id, status:"ready", token, solved_at_ms, config_version}` in that response.

**Backward compatible by construction**: an older JAR posts no `type`, gets `{request_id}` and
uses the old claim-then-GET exchange. Inline delivery had to be opt-in for exactly this reason
— returning a token unconditionally would delete the row before the old bot's GET arrived.

The "all providers disabled" gate still applies: login/reserve tokens are withheld inline just
as they are on GET, so disabling every provider still parks the bot.

## Java — `blitz_v_8.0`

- `PortalCaptchaClient` sends `type` on the POST and returns immediately if a token comes back.
- The poll loop asks **first and sleeps after** (it slept 500ms before its first request), on a
  100ms / 300ms / 750ms ladder keyed to elapsed time — fast while a race can still land, cheap
  on the long tail. Pool hits never reach the loop at all now, so the fast interval is affordable.
- A 404 poll is fatal instead of being retried for 65s against a row that is gone.

## Queue pickup — `--sleep=0.1`

`deploy/ipms-captcha-worker@.service`. `Worker::sleep()` special-cases `< 1` to `usleep`, so
sub-second values work. Ten idle LPOPs per worker per second is nothing for Redis.

**Do NOT "fix" this with `block_for`.** The workers run `--queue=captcha_priority,captcha`; a
blocking pop waits out the full interval on the empty priority queue on every pass, stalling the
pool filler's `captcha` queue behind it.

## Shelf life is the binding constraint, not Turnstile's 270s (Jul 30 2026)

`settings.captcha_shelf_life_ms` (currently **20s**) is what every bot phase measures a token
against — sign-in (`SigninServiceImpl:115`), the shared slot token
(`SharedSlotCaptchaService`), and PDF setup all discard anything older. Turnstile's own ~270s
life is irrelevant; 20s is what decides whether a delivered token is usable.

**Config wiring** — `settings.captcha_shelf_life_ms` → `/api/config` `captchaShelfLifeMs` →
`AppConfig.getCaptchaShelfLifeMs()` (fallback 20s). Three defects found and fixed in
`blitz_v_8.1`:

- `Constants.TURNSTILE_SHELF_LIFE_MS = 20_000L` was a **second, hardcoded copy** feeding
  `CaptchaToken.isExpired()`. That method was unreferenced, so it did no damage — but the first
  caller would have silently ignored the portal setting. Constant and method both deleted;
  staleness now only exists as `isOlderThan(ms)`, which forces the caller to pass config.
- `ConfigExportService` (the `/api/config/export` mirror) **never emitted the key**, so anything
  reading the mirror saw the 20s fallback rather than the operator's value. Added, with
  `tests/Feature/CaptchaShelfLifeConfigTest.php` asserting the two endpoints agree.
- `ipms_java/CLAUDE.md` claimed the constant was `270,000` when it was `20,000`. That stale row
  is what made a capacity analysis reason against a 270s ceiling — corrected.

### Open: shelf life vs pool expiry are inconsistent

`captcha_shelf_life_ms = 20s` against `captcha:pool_expiry_seconds = 250` and
`captcha:pool_limit = 250`. The pool serves **oldest-first**, so at ~2 tok/s consumption a token
sits ~130s before delivery and the bot discards it on its next check. Most of the pool is dead
inventory and the slot phase refetches per probe instead of per 20s.

The two numbers must agree. Either drop pool expiry to ~25s (and pool limit to ~150), or raise
`captcha_shelf_life_ms` to 120-180s if IVAC actually accepts older tokens — **unverified which,
do not guess**. Once shelf life is comfortably above the pool residence time, oldest-first is
fine again.

## Deliberate non-change: pool claim stays oldest-first

Freshest-first (LIFO) looks like a latency win but is wrong here. Pool expiry is 120s
(`CaptchaPoolExpiry::DEFAULT_SECONDS`) against a ~270s Turnstile life, so a pool token is never
handed out near death. FIFO keeps the hit rate up, and a pool hit is what actually avoids a solve.

## Files

- `app/Services/Captcha/CaptchaRaceCoordinator.php` (new)
- `app/Http/Controllers/Api/CaptchaRequestController.php` — inline delivery, shared
  `consume()` / `encryptFor()` / `providerGateShut()` helpers
- `app/Jobs/SolveCaptchaJob.php` — coordinator funnel, shadow fail-fast
- `app/Console/Commands/PollCaptchaTasksCommand.php` — funnel + exhausted-race sweep
- `app/Services/Captcha/CaptchaNodeFleet.php` — funnel (resolves the coordinator via `app()`;
  constructor injection both ways would be a container cycle)
- `database/migrations/2026_07_30_100000_add_race_parent_id_to_captcha_requests_table.php`
- `deploy/ipms-captcha-worker@.service`
- `ipms_java/.../service/captcha/PortalCaptchaClient.java`, `BotVersion.java`
- Tests: `tests/Feature/Captcha/CaptchaRaceTest.php` (15)

## Verified

Full captcha suite 263 passed. The 2 failures in `ActiveProvidersOnlyTest` were confirmed
identical at HEAD by stashing only the touched files — pre-existing, not from this work.
Live over HTTPS: inline delivery returned the token in the POST (223ms, no GET) and consumed
the row; a pool miss with zero enabled providers failed the slot immediately with
`No captcha providers are enabled.` and created zero attempts, so nothing is spent while the
gate is shut.
