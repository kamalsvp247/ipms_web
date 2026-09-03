---
name: project_dgepay_payment_flow
description: dg-epay checkout flow reverse-engineered from Chrome HARs (Nagad + Rocket) — the step sequence auto payment automates
metadata:
  node_type: memory
  type: project
  originSessionId: 906da968-5390-46f0-8089-d8c00e610658
---

Traced Jul 29 2026 from two Chrome HARs the user exported into `tests/har-data/` (`pay--appointment.ivacbd.com-5-07-3` = Rocket, `…-5-07-4` = Nagad). This is the flow [[project_auto_payment]] automates.

## Entry and exit are already automated

Bot fires `POST /payment/{paymentConfigId}/dg-epay/initiate` → IVAC returns `data.webview_url` = `https://checkout.dgepay.net/payment/payment-methods?data=<blob>` → `PaymentServiceImpl.postPaymentToIpmsWeb()` POSTs the whole response to `/api/payment-links` → stored as `payment_links.gateway_page_url`.

Flow **ends** when the checkout SPA navigates to `https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=<uuid>&data=<blob>` → **302** → `appointment.ivacbd.com/appointment/confirm-payment`. The browser extension catches that nav → `POST /api/payment-links/redirect-url`; `PaymentCallbackService` (Java) is already polling `/api/payment-callback` and fires the callback GET. **Only the middle was ever manual.**

**Key linkage:** the `uniquetxnid` request header == IVAC `tran_id` == `payment_links.reservation_id` == dgepay `transaction_by.account_id` (e.g. `8f86b4f7-a329-425f-8de7-48b1bdf593e1`). One id threads the whole flow and the portal already has it.

## Middle steps by wallet

**Nagad** (`payment_type=2`), host `payment.mynagad.com:30000` — clean wallet page:
1. `POST check-out/verify-account/{ref}` — `encryptedPayeeAccountNumber` (payer number, RSA-encrypted client-side by `jsencrypt.min.js`) → **Nagad sends the OTP SMS here**
2. `POST check-out/verify-otp/{ref}` — `otp`
3. `POST check-out/confirm-payment/{ref}` — `encryptedPin` → **302** back to dgepay `payment-status`

The CSRF field in the captured form was the literal unfilled template `${_csrf.parameterName}`/`${_csrf.token}` — i.e. disabled server-side.

**Rocket** (`payment_type=3`), host `ecom1.dutchbanglabank.com` — **DBBL Nexus CARD rails, NOT a wallet page**:
1. `POST rsaotp_re/checkRSA` — `cardnr` `9999990018651441477`, `USER_NAME` `018651441477`, `validMONTH` 01, `validYEAR` 22, `cvc2` 1995, `sec_val` 1995, `card_type` 6, `merchant` `000599992900000` → OTP sent
2. `POST rsaotp_re/RsaAuthMob` — `passCode` = OTP, plus RSA-encrypted card fields
3. `POST ecomm2/ClientHandler?do=SUBMIT` (here `validMONTH` 07 / `validYEAR` 26 — **differs from step 1**)
4. `POST apiv2.dgepay.net/…/dbbl_bank_redirect` → HTML that `window.location`s to dgepay `payment-status`

`cardnr = "9999990" + the 12-digit Rocket account` (11-digit mobile + check digit). `transaction/detail` confirms `cardNumber: "999999*********1477"`, `non_dgepay_info: {bank_name: "Rocket"}`.

**bKash** — no HAR captured. Unknown.

## Converge (already automated, no work needed)

`payment-status` SPA → `GET user_payment_info?requested_data=<blob>` → (`wallet_card/...` for Nagad) → `analytics/transactions/{txnId}` polled a few times → `transaction/detail` → `DELETE payment_processing/user_payment_info/{id}` → **`POST payment_gateway/get_encrypted_url`** returns an encrypted blob the SPA decrypts into the IVAC callback URL, then navigates.

## apiv2.dgepay.net guards

Matters only for a pure-HTTP replay, which we deliberately are **not** doing:
- Static `apikey: dg4g5mf034335f136fasda3wfdasd7ad84` + `companyid: b4dab74b566e4e19919be44c43a1a5e9`
- Per-request `requestid` + `signature` (base64 HMAC-SHA256; `main.f4e84f89….js` contains `HmacSHA256`×5, `encrypt`×280, `signature`×204)
- Bodies are AES blobs sent as `Content-Type: text/plain`

**No Cloudflare Turnstile anywhere on the dgepay or bank path.** The 300+ `challenges.cloudflare.com` hits in the Nagad HAR are `appointment.ivacbd.com` JSD firing ~89s in, *after* payment. Bank pages are plain jQuery forms.

## Why headless browser, not HTTP replay

Driving the real SPA means its own AES/HMAC signing and redirect logic run untouched, so a `main.<hash>.js` rotation cannot break the automation, and neither dgepay's crypto nor each bank's client-side RSA has to be reimplemented. The alternative doubles the reverse-engineering surface (dgepay **and** every bank) against a hashed bundle that rotates.

## Historical note

In `ipms_db_backup_2026-06-18.sql`: 27 of 35 payment links were dg-epay, 8 SSLCommerz (legacy). dg-epay puts `webview_url` in `gateway_page_url` and leaves `redirect_gateway_url` NULL; SSLCommerz populates both. There is **no gateway discriminator column** — tell them apart by URL host. Only 4 rows ever had a `reservation_id`, and exactly those 4 are the ones that reached the callback stage (3 succeeded HTTP 302, 1 stuck `pending` because its pasted URL had no scheme — fixed in [[project_auto_payment]]).

## Checkout sessions are locked to one browser (Jul 29 2026)

`verify_session` (`apiv2.dgepay.net/dipon/v3/payment_processing/verify_session`) is called on load, and a checkout already opened elsewhere is redirected to **`/payment/session-active`** — "SESSION ACTIVE ELSEWHERE / This payment is already in progress … To avoid charging you twice, this action can't be performed here." Observed on link 379 after its driver run had opened and closed the page: the lock **outlives the browser that took it**, so a retry on the same link cannot work and a human opening the link to watch will lock the driver out. The driver names this refusal (`checkoutBlockReason()`) instead of burning a step timeout on it.

Checkout is an **Angular SPA** (`<base href="/">`, `main.<hash>.js`, ngx-bootstrap, Datadog RUM): the method tiles are rendered client-side from an API response — the wallet names appear **nowhere** in the bundle — so tile markup is generic and must be matched by text, with the care described in [[kb_click_by_text_wrapper_trap]].
