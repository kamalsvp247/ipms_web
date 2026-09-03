---
name: kb_bank_checkout_box_inputs
description: "Nagad/DBBL checkout pages hide the real value behind split single-digit boxes; the NAMED field is an RSA ciphertext holder, so typing into it submits an empty value"
metadata:
  node_type: memory
  type: reference
  originSessionId: 906da968-5390-46f0-8089-d8c00e610658
  modified: 2026-07-29T09:19:30.943Z
---

**The trap:** on Nagad's checkout the wallet number and PIN have **no single named input**. The value lives in a row of `maxlength="1"` boxes under `.box-inputs`, and the form's own submit handler merges them, RSA-encrypts with `jsencrypt.min.js`, and writes the result into a **hidden** field. The named field a selector-writer reaches for first — `input[name="encryptedPayeeAccountNumber"]` — is that hidden ciphertext holder (`id="encryptedPhoneNum"`, initially empty).

Typing into the named field therefore leaves the visible boxes empty, the merge yields `""`, and the request is submitted **with no account number at all**. Verified Jul 29 2026 from `tests/har-data/pay--appointment.ivacbd.com-5-07-4..har`.

**Why it cost three runs and a wrong diagnosis:** Nagad answers an empty `verify-account` with its OTP page anyway, and that page's input is `id="otp"` — matching the driver's OTP probe. So the driver "passed" its own did-we-advance check and sat waiting for an SMS Nagad had no reason to send. It read exactly like a broken SMS forwarder; I blamed the forwarder for `01865144147` on the strength of "no MFS row ever in `otp_codes`", which was true but not the cause. **A gateway that renders the next page is not proof it accepted the previous one** — assert on something the rejection path cannot produce, and surface the page's own error text (Nagad's is `.messages`).

**Verified DOM contract** (all confirmed against the HAR, not inferred):

| Page | Form | Real input | Hidden target | Submit |
|---|---|---|---|---|
| wallet | `#account-form` | `.box-inputs input` ×11 | `#encryptedPhoneNum` (name `encryptedPayeeAccountNumber`) | `button[type=submit]` |
| OTP | `#otp-form` | `#otp` (single, `pattern="\d{6}"`) | — | `button[type=submit]` |
| PIN | `#pin-form` | `.box-inputs input` ×4 (`type=password`) | `#encryptedPin` | `#confirmButton` |

`js/script.js` auto-advances focus on keyup, so focusing box 0 and typing the whole string works like a human; read the boxes back to verify, and fall back to setting each `.value` directly (the merge reads the DOM property either way). The consent checkbox `#merchantAuthorization1` is **optional** — the successful HAR posted only `_merchantAuthorization=on` with the box unchecked.

**DBBL/Rocket has the same shape in card clothing** (`ecom1.dutchbanglabank.com/ecomm2/ClientHandler`, form `#cardentry`): the page copies `#cardnr`→`USER_NAME` and `#cvc2`→`sec_val` itself and prefixes `9999990` to build the 19-digit card number. Operator still supplies only account + PIN. See [[project_dgepay_payment_flow]] and [[project_auto_payment]].

`app/Scripts/dgepay_payment_driver.cjs --selftest` reproduces the broken structure offline (11 boxes + hidden named field) and asserts the boxes get filled while the hidden field stays empty — run it after any selector change.
