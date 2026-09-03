---
name: kb_click_by_text_wrapper_trap
description: "Clicking by visible text picks the page wrapper, not the tile — querySelectorAll returns ancestors first, so the click reports success while no handler fires"
metadata:
  node_type: memory
  type: reference
---

**The trap:** a "click the element whose visible text matches X" helper that returns the **first** match in document order will click the page **wrapper**. `document.querySelectorAll('button, a, div, li, img, span, label')` yields ancestors before descendants, and the wrapper's `innerText` contains every method name, so `label.includes('nagad')` matches it first. `node.closest('button, a, li, [role="button"]')` then finds nothing above the wrapper and the helper clicks the wrapper itself. Events bubble **up**, never down, so the tile's own handler never runs — and the helper returns `true`.

**How it presents:** a silent step timeout. The caller waits for the navigation that a successful click would cause, times out, and reports "clicked but nothing happened" — indistinguishable from a gateway that ignored the click. Cost one live auto-payment run on Jul 29 2026 (payment link 379, attempt stuck 45s at `select_method` with the dg-epay method list still on screen). The three runs before it had passed the same step because the helper was rewritten earlier that morning; nothing about the gateway had changed.

**Proof it is structural, not situational:** in a real browser against a three-tile page, the selection logic picks `div.page-wrap` with text `"Select your payment method bKash Nagad Rocket"` and `window.__fired` stays empty. It cannot select a tile on any normally-nested page.

**The fix** (`selectMethod()` in `app/Scripts/dgepay_payment_driver.cjs`):
- Rank matches by **label length ascending** — an ancestor's text always contains its descendants', so the shortest label is the tile and the longest is the wrapper.
- Queue the match, its clickable ancestor and its two parents; a click on `<p>Nagad</p>` or `<img alt="Nagad">` bubbles up into the card that owns the Angular `(click)` binding.
- **Verify the effect.** Each candidate gets ~2.5s to satisfy an `expect` predicate (`location.hostname.includes('mynagad.com')`) before the next is tried. A click that changes nothing is not a click.
- **Mouse-click only precise matches.** `handle.click()` hit-tests a coordinate, so a real click on a container lands on whatever tile sits at its centre — a different wallet. Containers get `el.click()`, which dispatches on exactly that element.

Same lesson as [[kb_bank_checkout_box_inputs]]: **assert on something only the success path can produce.** There, a rendered next page was not proof of acceptance; here, a returned `true` was not proof of a click.

`app/Scripts/dgepay_payment_driver.cjs --selftest` reproduces the tile list offline and asserts Nagad was reached **and that no other wallet's handler fired**; `tests/Feature/PaymentDriverSelfTest.php` runs it under `php artisan test`. See [[project_auto_payment]] and [[project_dgepay_payment_flow]].
