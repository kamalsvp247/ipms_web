---
name: project_auto_payment
description: Per-account auto payment — headless dg-epay checkout driven by the portal, closing the last manual step in the booking flow
metadata:
  node_type: memory
  type: project
  originSessionId: 906da968-5390-46f0-8089-d8c00e610658
---

Added Jul 29 2026. Closes the only remaining manual step in the booking pipeline. Previously: the bot fired `POST /payment/{cfgId}/dg-epay/initiate`, stored the checkout URL as `payment_links.gateway_page_url`, then blocked in `PaymentCallbackService.awaitAndFire()` polling `GET /api/payment-callback` until a **human** opened the link, picked a wallet, and typed number + OTP + PIN — the Chrome extension (`ipms_payment_helper/`) then caught the `api.ivacbd.com/.../dg-epay/callback?tran_id=…` redirect. Now an account flagged **Auto Payment** on `/accounts` has that middle step driven by headless Chrome.

**No Java changes.** `blitz_v_7.9` untouched — the bot already waits on exactly the field this feature fills. Flow reverse-engineering (Nagad + Rocket HARs in `tests/har-data/`) is in [[project_dgepay_payment_flow]].

## Chain

```
bot POST /api/payment-links  →  PaymentLinkController::store
  →  AutoPaymentDispatcher::dispatchFor()      (eligibility + create-once)
  →  AutoPaymentJob            redis queue `payments`, tries=1, timeout=300
  →  PaymentAutomationService  (credentials, concurrency slot, OTP ticket)
  →  app/Scripts/dgepay_payment_driver.cjs     (puppeteer)
  →  PaymentCallbackRouter::route()            writes callback_url + status=pending
  →  the bot's existing poll fires the callback GET → callback_status=success
```

Also swept every minute: `payments:sweep-auto` (`SweepAutoPayments`, registered in `routes/console.php` with `withoutOverlapping`) re-dispatches links from the last 30 min with no `callback_url` and no running/succeeded attempt. Inline dispatch is wrapped in try/catch + `report()` so a queue hiccup can never fail the bot's ingest — the sweep is the backstop.

## Schema

- `accounts`: `auto_payment` bool, `auto_payment_method` (bkash|nagad|rocket), `auto_payment_wallet`, `auto_payment_pin` (**encrypted** via an `Attribute` copied from `Account::password()` — Crypt + try/catch → null on a foreign `APP_KEY`, see [[kb_encrypted_cast_decrypt_exception]]). PIN is in `$hidden` so it never rides the account list; `AccountController::show()` merges it back like `pdfs`.
- `payment_automation_attempts`: **unique** `payment_link_id`, plus `account_id`, `method`, `status` (pending|running|succeeded|failed), `attempts`, `stage`, `callback_url`, `last_error`, `started_at`, `finished_at`.
- `otp_codes.is_mfs` bool + index `(phone, is_mfs)`.

## Double-charge guards — all tested, do not weaken

1. **Unique index** on `payment_automation_attempts.payment_link_id` — a second automation for one link is not insertable. `AutoPaymentDispatcher` catches the `QueryException` and treats losing the race as correct.
2. **Conditional status claim** in `AutoPaymentJob::claim()` — only `pending|failed → running` via a `DB::table()->whereIn(status)->update()`; a duplicate dispatch finds 0 rows updated and returns. This is the mutex.
3. `MAX_ATTEMPTS = 3` on the attempt row (`tries=1` on the job on purpose — retry policy belongs to the row, never the queue, because this spends money).
4. Skipped outright: `is_fake` links, non-dgepay URLs (legacy SSLCommerz), links that already have a `callback_url`, accounts failing `isAutoPaymentReady()`.
5. **Named-lock browser cap** `payment:automation:slot:{1..N}` (N from `payment:automation_concurrency`, default 3). Replaced the old `payment:automation:active` INCR counter Aug 4 2026 when the worker went multi-instance: racing workers could overshoot the cap, and a SIGKILLed worker left the counter permanently high, throttling everything until its TTL wiped the key. Over the cap the attempt is returned to `pending` with its try **decremented** — contention must not burn a retry.
6. `AutoPaymentJob::failed()` releases a `running` row back to `failed`, or a crashed worker would strand it forever (the sweep skips `running`).
7. **Per-wallet serialization** `App\Support\PaymentWalletLock` — see below.
8. **Paid guard** — an account with a completed payment is never auto-paid again until re-armed; see below.

## Per-wallet lock + paid guard (Aug 4 2026)

**Why**: an MFS OTP carries nothing identifying its checkout, and `otp_codes` rows are matched by wallet number alone — so two accounts sharing a wallet (live: 288 and 986 both on `018651441477`) could consume each other's codes. `lockForUpdate()` guarantees one consumer, not the *right* one.

- **Invariant**: while a run holds wallet W, every unconsumed `is_mfs` row for W belongs to it.
- `PaymentWalletLock::normalize()` keys on the **handset, not the stored string** — a Rocket account is the 11-digit mobile + a check digit, so `…1471` and `…1472` are one SIM. Over-locking is the safe direction.
- Taken **before Chrome launches**, not at the wallet step: killing a run that already opened the checkout leaves dg-epay serving `SESSION ACTIVE ELSEWHERE`, which `isSessionLocked()` treats as terminal — a collision would permanently poison a payable link.
- **Released early** at `<<<STAGE>>>otp`, which the driver emits only after its own code is consumed. PIN/confirm/callback run unlocked, so the twin account starts ~15s later instead of waiting out the whole run against a 5-minute link expiry.
- Contended runs `deferOrFail()`: back to `pending`, retry refunded, fresh `AutoPaymentJob::dispatch()->delay(8-15s)` (**never `release()`** — `tries=1` would kill it as MaxAttemptsExceeded). Loop is bounded by link expiry, not a counter: under 45s left it fails honestly.
- Release **drains** leftover `is_mfs` codes for the wallet before dropping the lock, so the next holder cannot inherit an unattributable code.
- **Paid signal** = `PaymentLink::scopeCompleted()` — non-fake AND (`callback_url` present OR `callback_status='success'`). Enforced in the dispatcher, the sweep (`scopeAutoPaymentArmed`) and again in the service just before spending, because a deferred run executes minutes after dispatch.
- **Re-arm** = `accounts.auto_payment_rearmed_at` watermark (not `$fillable`; own endpoints only). It compares **`callback_submitted_at` as well as `created_at`** — an age-only watermark lets a payment already in flight complete, look older than the re-arm, and be ignored, so the next link double-charges.
- Workers are now **`ipms-payment-worker@{1,2,3}`** (templated). A single worker made every payment wait for the one before it regardless of wallet.

## MFS OTP path

- `App\Support\MfsOtpParser` — `isMfs()` requires **both** a provider fingerprint (bkash/nagad/rocket/DBBL) **and** OTP wording, so a balance/promo SMS is never stored as a spendable code. `extractOtp()` has two regexes: keyword-then-digits (`your OTP … is 445566`) and digits-then-keyword (`123456 is your bKash verification code`).
- **`extractOtp()` was returning null for EVERY live message until Aug 4 2026** — every `is_mfs` row in prod had `otp_code = NULL`, which is why runs timed out waiting for an SMS that had actually arrived. Live bKash wording defeats the obvious pattern twice: `"Do NOT share your OTP or PIN with anyone. Your bKash OTP for PAYMENT of Tk.5,780.00 to bKash_ACS is 894251."` — the **first** keyword is in the safety preamble nowhere near the code, and an **amount sits between** the real keyword and the digits, which a `\D` window cannot span. Fix: try every keyword and take the **last** match, and let the window skip a `Tk.`/currency amount. Keep the live samples in `MfsOtpIngestTest`.
- `OtpIngestController` evaluates **IVAC first** and only falls through to MFS — a booking OTP must never be reclassified or the bot's OTP poll goes empty. Regression-tested.
- `OtpCode::consumeMfsForPhone()` mirrors `consumeForPhone()` but filters `is_mfs=true`, keeping the two streams apart on a SIM that receives both seconds apart. Takes a **list** of phones and defaults `toleranceMs` to **0** (`consumeForPhone` keeps 2000 — its `since` comes from the Java bot on another machine; every MFS `fetched_at` is written by this host, so a backward window is pure exposure).
- **The lookup could never match a real SMS until Aug 4 2026**: the account stores the 12-digit Rocket number, the forwarder posts the 11-digit SIM. Now resolved through `PaymentWalletLock::candidatePhones()`. `AutoPaymentManualOtpTest` passed throughout because `submitOtp()` writes the 12-digit wallet itself.
- Driver reads via `GET /api/payment-otp/{phone}` (open route, outside auth) authed by `App\Support\PaymentOtpTicket` — a **per-run, per-wallet** Redis token with the run's TTL, not a long-lived shared secret. A ticket for wallet A is 403 on wallet B; consumption is single-use. Note the ticket is keyed by **token**, only *bound to* a wallet, so it does not serialize anything — that is `PaymentWalletLock`'s job.

## Driver

`app/Scripts/dgepay_payment_driver.cjs` (puppeteer). Job in on **stdin** (credentials never in argv/`/proc`), result framed between `<<<JSON>>>` sentinels on stdout — the driver's own `log()` shares stdout, the same lesson as [[project_in_house_captcha_solver]]'s harness. `--selftest` launches real Chrome and validates the contract offline without contacting dgepay.

Chrome args copied from `in_house_captcha_solver.cjs` (UA override + `--disable-blink-features=AutomationControlled`). Ends by watching `request`/`framenavigated`/`response` for the callback URL — the SPA has used both a real navigation and a same-page assignment.

**Two env vars are load-bearing** (set in both `deploy/ipms-payment-worker@.service` and the `Symfony\Process` env — the Process env array **overrides** the unit's, so all instances share one crashpad dir; isolate per `%i` if crashpad errors ever show up under load):
- `HOME=storage/app/payment-automation` — Chrome's crashpad aborts on a read-only HOME (`www-data`'s `/var/www` is root-owned).
- `PUPPETEER_CACHE_DIR=storage/app/puppeteer` — **reuses the captcha solver's Chrome 148** instead of downloading a second copy. Without it the driver dies with "Could not find Chrome".

## Method support

`App\Support\AutoPaymentMethods` is the single list the `Rule::in` validation and the Vue picker both read.

- **Nagad** — the only method fully specified by a HAR. Build this one's confidence first.
- **Rocket** — implemented as phone+PIN **against the HAR evidence**, on the user's explicit instruction. The captured Rocket flow is DBBL Nexus **card** rails (`ecom1.dutchbanglabank.com`, `checkRSA` → `RsaAuthMob` → `ClientHandler?do=SUBMIT`), not a wallet page. Jul 29 decision: **leave enabled and let it fail live** rather than pre-emptively rebuild. Expected signature: `stage='wallet'`, `last_error='Rocket wallet number field not found…'`. Selecting the method does fire dgepay's `wallet_transfer_encrypted` (creates a `txn_status:1` pending txn) but **no funds move** — the charge is several steps later, after the PIN confirm. When rebuilding: `cardnr = "9999990" + rocket account number` is derivable (`USER_NAME 018651441477` → `cardnr 9999990018651441477`); unknowns are `validMONTH`/`validYEAR` (HAR self-contradicts: `checkRSA` sent 01/22, final `ClientHandler` sent 07/26) and `cvc2`/`sec_val` (=1995, possibly the 4-digit PIN).
- **bKash** — deliberately **not implemented**: no HAR captured. `supported: false`, greyed in the picker, driver throws `BKASH_NOT_IMPLEMENTED`.

## API / UI

- Validation added to **both** `store()` and `update()` via `AccountController::autoPaymentRules(requirePin:)`. `required_if:auto_payment,true` conflicts with "empty PIN means keep existing" on update, so update passes `requirePin: false` and `assertAutoPaymentPinPresent()` then rejects arming an account that has neither a stored nor a supplied PIN.
- `Accounts/Index.vue`: an **Auto Payment** section after *Booking Setup* — a `Switch`, and `v-if="form.auto_payment"` reveals method select + wallet + password-type PIN, so credentials are captured at the moment the toggle is switched on. Client-side guard mirrors the server rule. An `AUTO PAY` badge shows in the table's city cell.

## Side fixes landed here

- `SeedFakePaymentLinks` now mints fake URLs matching the **source link's gateway** — real dg-epay blob shape (`checkout.dgepay.net/payment/payment-methods?data=` + shared prefix `ngtso1MSQhZi2D6q4pBZz`, 428 chars, one `=` pad) instead of always SSLCommerz. Fakes were previously distinguishable by host alone.
- `PaymentCallbackRouter` **normalizes a scheme-less callback URL**. A hand-pasted `api.ivacbd.com/…` (no `https://`) was stored verbatim; OkHttp can't parse it, so that account polled forever — one such row is stranded in `ipms_db_backup_2026-06-18.sql` (id 35, `callback_status='pending'`).
- `extractTranId` + `routeCallbackUrl` moved out of `PaymentLinkController` into `PaymentCallbackRouter` so the extension ingest, the manual paste box and the driver all write identical state.

## Ops

**`ipms-payment-worker` is a NEW systemd unit and must be installed + started** — `queue:work redis --queue=payments`. An FPM reload never reaches queue workers ([[feedback_php_opcache_fpm_reload]]).

## Tests

45 passed / 118 assertions across `AutoPaymentValidationTest` (7), `AutoPaymentDispatchTest` (8), `AutoPaymentSweepTest` (6), `AutoPaymentJobClaimTest` (5), `MfsOtpIngestTest` (12), `PaymentCallbackRouterTest` (7). Regression: `Api/PaymentLinkControllerTest` 21 green, `SeedFakePaymentLinksTest` + `OtpIngestTest` green.

**Pre-existing failures, NOT from this work** — verified by stashing the whole change and re-running: `AccountStatusTest` fails the same 4 (`can update account status`, `can filter accounts by status`, `can update single sign in flag`, `public config single sign in defaults to false`) on a clean tree.

Gotcha for future test doubles: PHP forbids by-reference promoted constructor properties (`private &$counter`) — use a class with a static counter.

## Corrections (Jul 29 2026, same day as build)

**Nagad driver rebuilt against the real DOM.** Three live runs stalled at `await_otp`. Cause: the original selectors typed the wallet number into a hidden RSA-ciphertext field, so the page's merge produced `""` and `verify-account` posted an empty account number — Nagad never sent an SMS, yet still rendered its OTP page (which has `id="otp"`), so the driver's own did-we-advance probe passed. See `kb_bank_checkout_box_inputs.md`. **The SMS forwarder was never at fault**, despite `otp_codes` genuinely holding zero MFS rows in three weeks.

**Dry-run brake removed.** `payment:automation_stop_at` (`otp`/`pin`), `stopMode()`, the `stop_at_*` job keys, the driver's `haltIfStoppingAt*` and the `recordResult()` halt branch are all gone. It had been left set to `pin` in Redis, silently preventing every payment from completing. Replaced by per-step assertions (`waitForNagadForm`) that fail fast with the gateway's own `.messages` text. `AutoPaymentDryRunTest` → `AutoPaymentCompletionTest`.

**Rocket cross-checked against the HAR.** `driveRocket` matches nothing on the real DBBL page. The operator does only supply account + PIN (the page derives `USER_NAME`, `sec_val` and the `9999990`-prefixed 19-digit card number itself), but the **12-digit account is not derivable from the 11-digit mobile** — the page's Luhn rule only guards the `0121` prefix and yields check digit 1 where the HAR has 7. Expiry selects still ambiguous. No captcha on the path (the HAR's Turnstile hits all carry an `appointment.ivacbd.com` referer).
