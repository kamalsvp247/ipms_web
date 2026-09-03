# In-House Turnstile Captcha Solver (Jul 2026)

Self-hosted Cloudflare Turnstile solving, so tokens can be minted locally instead of
paying CapMonster / 2Captcha / CaptchaAI. Added 2026-07-28.

## Architecture

Mirrors the encrypt-sidecar pattern (`captcha_encrypt_server.cjs` + `LiveBundleClient`
+ systemd), for the same reason: launching Chrome per solve is slow and cannot run
concurrently, but a warm browser solves in ~3s and incognito contexts parallelise.

- `app/Scripts/in_house_captcha_solver.cjs` — persistent Node sidecar, binds `127.0.0.1:8788`
- `app/Services/Captcha/InHouseCaptchaClient.php` — PHP HTTP client (throws on failure,
  unlike `LiveBundleClient` which returns null — a missing token has no useful fallback)
- `app/Http/Controllers/Api/InHouseCaptchaController.php` — `generate` / `health` / `restart`
- `resources/js/pages/InHouseCaptcha/Index.vue` — `/in-house-captcha` (super_admin)
- `deploy/ipms-in-house-captcha.service` + `deploy/README-in-house-captcha.md`
- `config/captcha.php` → `in_house.url` / `in_house.timeout`
- Tests: `tests/Feature/Captcha/InHouseCaptchaTest.php`

## How a token is minted

Chrome navigates to the REAL `settings.captcha_page_url`, but the top-level document
response is fulfilled from memory with a synthetic page holding only the widget +
Cloudflare's own `api.js`. The URL is never changed — only the body — so
`location.origin`, `document.domain` and the widget referrer all still report the real
hostname. A Turnstile token is bound only to **(site key, hostname)**, which is exactly
what `siteverify` checks, so the token is genuine. No request ever reaches IVAC, so IVAC
downtime and its time-gated notice page are irrelevant to solving.

Site key + page URL always come from `Setting::instance()` — never hardcode either, or a
settings rotation silently yields tokens IVAC rejects.

## Two load-bearing settings — dropping either gives ZERO tokens

1. **Chrome needs a writable `HOME`.** It derives the crashpad database path from `HOME`;
   when that resolution fails it spawns `chrome_crashpad_handler` with no `--database`
   and dies on a CHECK (SIGTRAP) before the DevTools port opens. Puppeteer surfaces this
   only as a generic `Failed to launch the browser process`, with the misleading
   `chrome_crashpad_handler: --database is required` on stderr. **`www-data`'s home
   `/var/www` is root-owned**, so every launch from PHP-FPM or a queue worker fails. The
   systemd unit sets `HOME`; the script also repoints it defensively when unset/unwritable.
2. **Automation markers must be off.** The headless UA advertises `HeadlessChrome` and
   Blink sets `navigator.webdriver`. Cloudflare answers the widget's
   `/cdn-cgi/challenge-platform/.../rch/.../new/normal` request with **403** on either
   signal, the widget never renders, and no callback ever fires. UA override +
   `--disable-blink-features=AutomationControlled` took this from 0% to ~87-96%.

Same family as [[kb_cloudflare_403_okhttp_ua]] — CF blocking on client fingerprint, not
on anything about the payload.

## Reliability model

One attempt succeeds ~87-96%. The failure mode is a **silent client-side stall**: the
network trace is identical and entirely 2xx, the Turnstile callback just never fires. A
fresh incognito context always recovers it, so the solver caps each attempt at 10s (~3x
the observed worst-case success latency), retries up to 3x, inside a 45s budget.

Measured on the portal host: 20/20 solves at concurrency 4, all first-attempt, median
3.1s, ~1.2 solves/s, tokens 752 chars and all distinct, ~700 MB Chrome RSS.

Also handles: single-flight launch (no N-Chrome stampede on cold start), SIGKILL
recovery via the `disconnected` event, bounded queue that sheds with 503, Chrome recycle
after 400 solves while idle, graceful SIGTERM.

## Gotchas

- **Normalize the page URL.** Chrome normalizes a bare origin (`https://host`) to
  `https://host/`; matching the interception against the raw setting would miss the
  navigation, fall through to the REAL site, and quietly time out on a page with no
  widget. Both `goto()` and the match use `new URL(pageUrl).href`.
- **Never use a shared `userDataDir`.** Chrome locks the profile, so concurrent solves
  become impossible. Puppeteer's per-launch temp profile + per-solve incognito context
  is correct. (`storage/app/puppeteer/chrome-profile/` is a leftover from the original
  one-shot script and is unused.)
- Resolve Chrome via `PUPPETEER_CACHE_DIR` + `puppeteer.executablePath()`, never a
  hardcoded `linux-<version>` path — that breaks on the next browser install.
- The site key is interpolated into the synthetic page, so it is validated against
  `^[A-Za-z0-9_-]{8,64}$` and the page URL must be absolute https.

## Wired into the booking pipeline as the `in_house` provider (Jul 28 2026)

Add an **In-House (self-hosted)** row on `/captcha-providers` to route booking captchas
through it; remove the row to fall back to the paid providers.

**The integration hinges on it being synchronous.** Vendor providers are two-phase
(`createTask` → `PollCaptchaTasksCommand` → token); `POST /solve` returns the token
itself. So `SolveCaptchaJob::solveInHouse()` completes the request inline
(Pending → Ready, `vendor_task_id` stays null) and the poller never sees it. Therefore:

- **The job owns the Redis slot accounting** for in-house rows — `:active` decrement in
  a `finally`, `:count` increment on success. For vendor rows the poller does that, so
  forgetting it here would leak a slot per solve.
- Solve budget is capped at 30s (`IN_HOUSE_SOLVE_BUDGET_MS`), below the job's own 45s
  `$timeout`, and `InHouseCaptchaClient::solve()` derives its HTTP timeout from the
  budget rather than the 60s config default. Otherwise a hung sidecar outlives the job.
- `CaptchaSolverService::createTask/getTaskResult/getBalance` throw for this type, and
  `PollCaptchaTasksCommand` filters it out. Both are guards against the same failure:
  an in-house row is not JSON-API, so it would fall through to the 2captcha-compatible
  branch and POST its empty API key to captchaai.
- The stored token is the raw Turnstile token, so login/reserve encryption in
  `CaptchaRequestController::show` is unchanged — verified: an in-house token encrypts
  to both forms correctly.

**Admin-only, on all four layers.** `/in-house-captcha` (web `super_admin` middleware),
its three API routes (`can:bot.manage`), the sidebar entry (`permissions['bot.manage']`),
and — added with the provider work — `CaptchaProviderController`'s refusal of the
`in_house` type to non-admins. That last one exists because `/captcha-providers` is open
to managers and agents, who could otherwise create an in-house row and drive the solver
around the gate.

Verified end to end on the live host: real 752-char token in 2.6s, status `ready`,
`vendor_task_id` null, slot released to 0, count incremented; sidecar-down path records
`failed` and still releases the slot.
