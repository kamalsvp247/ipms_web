#!/usr/bin/env node
/**
 * Headless dg-epay checkout driver.
 *
 * Completes the one step of the IVAC booking flow that was still manual: opening the
 * checkout.dgepay.net link the bot stored, choosing a wallet, and entering the wallet number,
 * OTP and PIN. The checkout SPA then performs its own AES/HMAC signing and redirects to
 * api.ivacbd.com/.../dg-epay/callback — we simply capture that navigation and hand the URL back,
 * exactly what the browser extension does for a human. Nothing about dgepay's crypto is
 * reimplemented here, so a `main.<hash>.js` rotation cannot break this driver.
 *
 * Contract: a JSON job arrives on stdin; a single result object is printed between <<<JSON>>>
 * sentinels on stdout. Human-readable logging shares stdout, hence the framing.
 *
 *   in : { checkout_url, method, wallet, pin, otp_url, otp_token, started_at_ms, timeout_ms }
 *   out: { ok, callback_url?, stage, error?, timings }
 */

'use strict';

const { EventEmitter } = require('node:events');
const puppeteer = require('puppeteer');

// Chrome's headless UA advertises "HeadlessChrome" and Blink sets navigator.webdriver under
// automation. These are the same settings the captcha solver needs; the bank pages are plainer
// than Cloudflare, but presenting as a real browser costs nothing and avoids a class of blocks.
const USER_AGENT = process.env.PAYMENT_DRIVER_UA
    || 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

const CHROME_ARGS = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-blink-features=AutomationControlled',
    '--disable-background-networking',
    '--disable-backgrounding-occluded-windows',
    '--disable-renderer-backgrounding',
    '--disable-background-timer-throttling',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    '--disable-component-update',
    '--disable-default-apps',
    '--disable-sync',
    '--disable-breakpad',
    '--metrics-recording-only',
    '--window-size=1280,900',
    `--user-agent=${USER_AGENT}`,
];

/** The navigation that ends every successful checkout, whatever the wallet. */
const CALLBACK_URL_PATTERN = /https:\/\/api\.ivacbd\.com\/iams\/api\/v1\/payment\/dg-epay\/callback\?[^\s"']*tran_id=[^\s"']+/i;

const OTP_POLL_INTERVAL_MS = 2000;
const OTP_WAIT_MS = 120_000;
const STEP_TIMEOUT_MS = 45_000;

const log = (msg) => process.stdout.write(`[payment-driver] ${msg}\n`);

/** Wallet identifiers as stored on the account. */
const METHODS = { BKASH: 'bkash', NAGAD: 'nagad', ROCKET: 'rocket' };

/**
 * The category the wallets are filed under on dg-epay's current method list, which shows
 * "Net Banking / Card / Mobile Banking (bKash, Nagad, Upay etc.)" and renders the wallet tiles
 * only after one is opened. Deliberately limited to the mobile-banking wording: opening an
 * unrelated category could navigate the checkout somewhere there is no way back from.
 */
const MOBILE_BANKING_GROUPS = ['mobile banking', 'mobile financial'];

/**
 * Wallet logo assets on dg-epay's CDN, as seen in the captured checkouts.
 *
 * The wallet name is part of the artwork, so a tile can carry no text for a selector to match. The
 * upload-timestamp prefix identifies each asset exactly, which beats guessing at text — and if
 * dgepay re-uploads a logo the prefix simply stops matching and text matching carries the step.
 */
const WALLET_LOGO_ASSETS = {
    // …/dipon-bucket/1733125978Group 22618 (1).png — purple "ROCKET", Dutch-Bangla's wallet.
    [METHODS.ROCKET]: ['1733125978Group'],
    // …/dipon-bucket/1734617762Nagad New logo Sep'24 Bangla Main Vertical.jpg
    [METHODS.NAGAD]: ['1734617762Nagad'],
    // …/dipon-bucket/1733128110Group 22609 (2).png — the pink origami bird.
    [METHODS.BKASH]: ['1733128110Group'],
};

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * A sleep that can be called off, for use as the losing side of a Promise.race.
 *
 * Node keeps its event loop alive until every armed timer fires, so racing a plain `sleep(budget)`
 * against real work means the process cannot exit until that whole budget has elapsed — even after
 * the work finished and the result was printed. That is not theoretical: every auto-payment run
 * sat idle until its deadline, so a checkout that completed in ~90s still reported at exactly 240s
 * (or exactly 127s, when the budget was 127). The delay pushed one run past the bot's JWT window
 * and orphaned a captured callback, and it is why the log page showed "Capture IVAC callback"
 * running for minutes after the payment was already done.
 *
 * @return {{promise: Promise<null>, cancel: () => void}}
 */
function cancellableTimeout(ms) {
    let timer = null;
    const promise = new Promise((resolve) => {
        timer = setTimeout(() => resolve(null), ms);
    });

    return {
        promise,
        cancel: () => {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        },
    };
}

/**
 * Click the first selector that exists, or return false. dgepay's method tiles have shifted
 * markup between builds, so each step carries a few candidate selectors rather than one brittle
 * path.
 */
async function clickFirst(page, selectors, { timeout = STEP_TIMEOUT_MS } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        for (const selector of selectors) {
            const handle = await page.$(selector);
            if (handle) {
                await handle.click();
                await handle.dispose();
                return selector;
            }
        }
        await sleep(250);
    }
    return null;
}

/** Marks the elements a selection pass wants to try, so each can be re-found by selector. */
const PICK_ATTR = 'data-driver-pick';

/** How long one candidate gets to prove its click was the one that mattered. */
const CLICK_EFFECT_MS = 2500;

/**
 * Rank the elements carrying one of `texts`, innermost first, and tag them for clicking.
 *
 * Text matching has to start from the innermost element: querySelectorAll returns ancestors before
 * descendants, and the page wrapper's innerText contains every method name, so taking the first
 * match selects the wrapper rather than the tile. An ancestor's text always contains its
 * descendants', which makes the shortest label the tile itself and the longest the wrapper.
 *
 * Around each match the clickable ancestor and the immediate parents are queued too, since the
 * label ("Nagad", or an <img alt>) usually sits inside the element that actually owns the handler.
 *
 * @return {Promise<Array<{index: number, precise: boolean, label: string}>>}
 */
async function markTextCandidates(page, wanted, assets = []) {
    return page.evaluate((needles, attr, assetNeedles) => {
        document.querySelectorAll(`[${attr}]`).forEach((el) => el.removeAttribute(attr));

        const labelOf = (el) => (
            el.innerText || el.getAttribute('alt') || el.getAttribute('aria-label') || ''
        ).replace(/\s+/g, ' ').trim().toLowerCase();

        const isVisible = (el) => {
            const rect = el.getBoundingClientRect();
            return rect.width > 2 && rect.height > 2;
        };

        // A tile's label is the wallet's name, give or take a few words. Prose that merely mentions
        // the wallet is not a tile and clicking it does nothing — dg-epay's Bangla QR blurb ("You
        // can use Rocket, Upay, bKash, or any other supported banking and financial app…") is
        // exactly that, and seeding from it is how a run spent its whole step budget clicking a
        // paragraph and its ancestors 17 times while the real wallets sat one level down.
        const isTileLike = (label) => label.split(' ').length <= 8 && label.length <= 60;

        const matches = Array.from(document.querySelectorAll('button, a, div, li, img, span, label, p'))
            .filter((el) => {
                const label = labelOf(el);
                return label !== ''
                    && needles.some((n) => label === n || (label.includes(n) && isTileLike(label)))
                    && isVisible(el);
            })
            .sort((a, b) => labelOf(a).length - labelOf(b).length);

        // The wallet names are burned into the tile logos, so a tile can carry no text at all — and
        // then nothing above matches. The logo's own asset URL identifies it unambiguously
        // (Rocket is `…/1733125978Group 22618 (1).png`), so an <img> whose src carries a known
        // asset counts as a match too, with its clickable ancestor as the thing to press.
        const byAsset = assetNeedles.length === 0 ? [] : Array.from(document.querySelectorAll('img[src]'))
            .filter((el) => isVisible(el)
                && assetNeedles.some((n) => decodeURIComponent(el.getAttribute('src') || '').includes(n)));

        const ordered = [];
        const push = (el, precise) => {
            if (el && el.nodeType === 1 && !ordered.some((c) => c.el === el) && ordered.length < 12) {
                ordered.push({ el, precise });
            }
        };

        // Logo matches lead: an asset URL is an exact identity, where a text match is a guess.
        // Only the image and the clickable ancestor wrapping it may take a real mouse click — an
        // image-only tile has no label to judge by, so anything further out is DOM-clicked rather
        // than risking a coordinate click landing on a neighbouring wallet.
        for (const el of byAsset) {
            push(el.closest('button, a, li, label, [role="button"], [onclick]'), true);
            push(el, true);
            push(el.parentElement, false);
            push(el.parentElement && el.parentElement.parentElement, false);
        }

        for (const el of matches.slice(0, 4)) {
            // A tile whose own label is essentially just the wanted word. Anything longer is a
            // container, and a container must never take a real mouse click: the click would land
            // on whatever tile happens to sit at its centre.
            const isTile = needles.some((n) => labelOf(el).length <= n.length + 12);
            push(el.closest('button, a, li, label, [role="button"], [onclick]'), isTile);
            push(el, isTile);
            push(el.parentElement, false);
            push(el.parentElement && el.parentElement.parentElement, false);
        }

        return ordered.map(({ el, precise }, i) => {
            el.setAttribute(attr, String(i));
            const label = labelOf(el);

            return {
                index: i,
                precise,
                label: label.slice(0, 40) || `<logo ${(el.tagName || '').toLowerCase()}>`,
            };
        });
    }, wanted, PICK_ATTR, assets);
}

/**
 * Click one marked candidate.
 *
 * Tiles get a real mouse click so pages that only trust genuine pointer events still respond;
 * containers get a DOM click, which dispatches on exactly that element instead of hit-testing a
 * coordinate that could belong to a different wallet.
 */
async function clickCandidate(page, candidate) {
    const handle = await page.$(`[${PICK_ATTR}="${candidate.index}"]`).catch(() => null);
    if (!handle) {
        return false;
    }

    try {
        if (candidate.precise) {
            await handle.click();

            return true;
        }

        return await handle.evaluate((el) => {
            el.click();

            return true;
        });
    } catch (err) {
        // Covered, off-screen or mid-navigation: the page's own handler still runs on a DOM click.
        return handle.evaluate((el) => {
            el.click();

            return true;
        }).catch(() => false);
    } finally {
        await handle.dispose().catch(() => {});
    }
}

/**
 * dg-epay's refusal pages, which render in place of the method list.
 *
 * The gateway locks a checkout to one browser: reopening a link another window — or an earlier
 * driver run — already started serves "SESSION ACTIVE ELSEWHERE" at /payment/session-active, and
 * no amount of waiting will produce a wallet tile. Naming the refusal turns a full step timeout
 * into an instant, accurate error.
 */
async function checkoutBlockReason(page) {
    const state = await page.evaluate(() => ({
        path: location.pathname,
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim(),
    })).catch(() => null);

    if (state === null) {
        return null;
    }

    const haystack = `${state.path} ${state.text}`.toLowerCase();
    const refusals = [
        ['session-active', 'dg-epay refused the checkout: the session is already open in another window or device.'],
        ['already in progress', 'dg-epay refused the checkout: the session is already open in another window or device.'],
        ['session expired', 'dg-epay refused the checkout: the session has expired.'],
        ['transaction not found', 'dg-epay does not recognise this checkout link.'],
    ];

    for (const [needle, reason] of refusals) {
        if (haystack.includes(needle)) {
            return `${reason} Page: ${state.text.slice(0, 300)}`;
        }
    }

    return null;
}

/**
 * Labels of the "skip this" control on dg-epay's own interstitials.
 *
 * The checkout can open behind a full-screen "Let's get you connected! Enter your phone number to
 * begin" panel, which covers the method list entirely: the tiles are not merely hidden from the
 * scan, they are unreachable, because a tile is taken with a real mouse click and the overlay is
 * what sits under the cursor. Only the opt-out is ever pressed — "Continue" next to it submits an
 * empty phone number, and volunteering a number to the gateway is not this driver's business.
 */
const INTERSTITIAL_OPT_OUTS = ['continue without phone number', 'continue without phone'];

/**
 * Close any interstitial standing between the driver and the method list.
 *
 * Matched on the opt-out's own words rather than position, and never on a bare "continue", so the
 * neighbouring submit can never be pressed by mistake.
 *
 * @return {Promise<string|null>} The label pressed, or null when there was nothing to close.
 */
async function dismissInterstitials(page) {
    return page.evaluate((needles) => {
        const candidates = Array.from(
            document.querySelectorAll('button, a, [role="button"], input[type="button"], span, p'),
        ).filter((el) => {
            const label = (el.innerText || el.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
            const rect = el.getBoundingClientRect();

            return rect.width > 2 && rect.height > 2
                && label.length <= 60
                && needles.some((n) => label.includes(n));
        }).sort((a, b) => (a.innerText || '').length - (b.innerText || '').length);

        if (candidates.length === 0) {
            return null;
        }

        candidates[0].click();

        return (candidates[0].innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    }, INTERSTITIAL_OPT_OUTS).catch(() => null);
}

/**
 * Open the category a wallet lives under, when the method list does not show the wallet itself.
 *
 * dg-epay's list groups its options: the first screen offers "Net Banking / Card / Mobile Banking
 * (bKash, Nagad, Upay etc.)" and only renders the wallet tiles once a category is opened. A wallet
 * that is nowhere on the page is therefore not a missing tile — it is a tile one level down.
 *
 * One category is opened per pass so the caller can re-scan for wallets in between; labels already
 * tried are skipped, and the set is cleared once exhausted in case a click collapsed an accordion
 * that reopening will fix.
 */
async function openMethodGroup(page, groups, tried) {
    const candidates = await markTextCandidates(page, groups).catch(() => []);
    const fresh = candidates.filter((c) => !tried.has(c.label));

    if (candidates.length > 0 && fresh.length === 0) {
        tried.clear();
    }

    for (const candidate of (fresh.length > 0 ? fresh : candidates)) {
        tried.add(candidate.label);
        if (await clickCandidate(page, candidate)) {
            log(`opened method group via "${candidate.label}"`);

            return true;
        }
    }

    return false;
}

/**
 * The short, clickable labels currently on screen.
 *
 * A "did not open its checkout" error that names only the wanted wallet cannot distinguish "the
 * tile is behind a category" from "the layout changed" — and by the time anyone reads it the
 * checkout has expired and cannot be reopened. Listing what was actually offered makes one failed
 * attempt enough to write the next selector.
 */
async function describeChoices(page) {
    return page.evaluate(() => {
        const labels = new Set();

        Array.from(document.querySelectorAll('button, a, li, label, [role="button"], p, h1, h2, h3, h4, h5'))
            .forEach((el) => {
                const rect = el.getBoundingClientRect();
                const label = (el.innerText || el.getAttribute('alt') || '').replace(/\s+/g, ' ').trim();
                if (label !== '' && label.length <= 60 && rect.width > 2 && rect.height > 2) {
                    labels.add(label);
                }
            });

        return Array.from(labels).slice(0, 25).join(' / ');
    }).catch(() => '');
}

/**
 * Pick a wallet on the dg-epay method list and prove the gateway acted on it.
 *
 * `expect` is what makes this trustworthy. A click that reports success without moving the page is
 * indistinguishable from no click at all, which is exactly how a run once sat at select_method for
 * the whole step timeout with the method list still on screen. Each candidate is therefore clicked
 * and then given a moment to satisfy `expect`, and only the candidate that does counts.
 *
 * `groups` names the categories the wallet may be filed under; they are opened only when no wallet
 * tile is on screen, so a layout that lists the wallets directly behaves exactly as before.
 */
async function selectMethod(page, texts, { expect, expectArg = null, rebaseline = null, what, groups = [], assets = [], timeout = STEP_TIMEOUT_MS } = {}) {
    const deadline = Date.now() + timeout;
    const wanted = texts.map((t) => t.toLowerCase());
    const wantedGroups = groups.map((t) => t.toLowerCase());
    const satisfied = () => page.evaluate(expect, expectArg).then((v) => v === true).catch(() => false);
    const triedGroups = new Set();

    let clicked = 0;
    let seen = 0;
    let groupsOpened = 0;

    while (Date.now() < deadline) {
        const dismissed = await dismissInterstitials(page);
        if (dismissed !== null) {
            log(`dismissed an interstitial via "${dismissed}"`);
            // The method list underneath has to paint before it can be scanned.
            await sleep(1000);
        }

        // Re-accepted here, not only once before the step: the terms box can paint late, and while
        // it is unticked Pay stays disabled — which would keep the "Pay became pressable" signal
        // below from ever firing no matter how correctly the tile was clicked.
        await acceptCheckoutTerms(page);

        // A before/after signal means nothing until something has been clicked. The checkout paints
        // its summary asynchronously, so a Pay control that merely arrived late is indistinguishable
        // from one revealed by taking a tile — and reading it as success returns from here having
        // selected nothing, leaving the run to press a Pay button that stays disabled for the whole
        // step (link 1293, logged as "satisfied without a click"). While no click has happened, a
        // rise can only be late rendering, so it is folded into the baseline instead of believed.
        if (clicked === 0 && rebaseline !== null) {
            expectArg = await rebaseline(page);
        }

        if (await satisfied()) {
            // Reached without clicking anything: the checkout had already moved on by itself. Said
            // out loud because the log is otherwise identical to a real selection, and a run that
            // took this branch (link 1289) looked like it had chosen Rocket when the gateway had
            // in fact redirected it somewhere else entirely.
            log(clicked === 0
                ? `${what} step satisfied without a click — already at ${page.url()}`
                : `selected ${what} after ${clicked} click(s)`);

            return;
        }

        const blocked = await checkoutBlockReason(page);
        if (blocked !== null) {
            throw new Error(blocked);
        }

        const candidates = await markTextCandidates(page, wanted, assets).catch(() => []);
        seen = Math.max(seen, candidates.length);

        if (candidates.length === 0 && wantedGroups.length > 0) {
            if (await openMethodGroup(page, wantedGroups, triedGroups)) {
                groupsOpened++;
                // Give the SPA a moment to render the wallets before scanning for them.
                await sleep(1000);

                continue;
            }
        }

        for (const candidate of candidates) {
            if (Date.now() >= deadline) {
                break;
            }
            if (!await clickCandidate(page, candidate)) {
                continue;
            }

            clicked++;
            const budget = Math.min(CLICK_EFFECT_MS, deadline - Date.now());
            const settleBy = Date.now() + budget;
            while (Date.now() < settleBy) {
                if (await satisfied()) {
                    log(`selected ${what} via "${candidate.label}" after ${clicked} click(s)`);

                    return;
                }
                await sleep(150);
            }

            // The gateway locks a checkout to one session, and every extra click on an already
            // taken tile looks like a second one opening. Once it refuses, more clicking cannot
            // recover the run — stop on the spot rather than working through the remaining
            // candidates and reporting a generic "did not open" at the end.
            const blockedAfterClick = await checkoutBlockReason(page);
            if (blockedAfterClick !== null) {
                throw new Error(blockedAfterClick);
            }
        }

        await sleep(250);
    }

    const blocked = await checkoutBlockReason(page);
    if (blocked !== null) {
        throw new Error(blocked);
    }

    const offered = await describeChoices(page);
    const groupNote = wantedGroups.length === 0 ? '' : `, ${groupsOpened} category click(s)`;

    if (seen === 0) {
        throw new Error(`Could not find the ${what} option on the checkout page${groupNote} (at ${page.url()}). Offered: ${offered}`);
    }

    throw new Error(`Selecting ${what} did not open its checkout — ${clicked} of ${seen} candidate element(s) clicked${groupNote}, still at ${page.url()}. Offered: ${offered}`);
}

/**
 * Survey the checkout's own "Pay ৳X" submit controls, split by whether they can be pressed.
 *
 * Two numbers rather than one because dg-epay has both shapes: the confirm panel can arrive as a
 * *new* Pay button, or the Pay button can already be sitting on the method list from the start and
 * merely become enabled once a wallet is picked. Anchored to labels that *start* with "pay" so the
 * unrelated "Click to Pay" option can never be mistaken for it.
 *
 * Disabled is read three ways: the page may express it as the property, as aria-disabled, or as a
 * class, and treating a styled-disabled button as pressable is how a Pay click silently does nothing.
 */
const SURVEY_PAY_CONTROLS = () => {
    const found = Array.from(
        document.querySelectorAll('button, a, input[type="submit"], [role="button"]'),
    ).filter((el) => {
        const label = (el.innerText || el.value || '').replace(/\s+/g, ' ').trim();
        const rect = el.getBoundingClientRect();

        return /^pay\b/i.test(label) && rect.width > 2 && rect.height > 2;
    });

    const enabled = found.filter((el) => el.disabled !== true
        && el.getAttribute('aria-disabled') !== 'true'
        && !/(^|\s)disabled(\s|$)/.test(String(el.className || '')));

    return { total: found.length, enabled: enabled.length };
};

function surveyPayControls(page) {
    return page.evaluate(SURVEY_PAY_CONTROLS).catch(() => ({ total: 0, enabled: 0 }));
}

/**
 * Proof that a wallet tile was taken: the checkout left dg-epay outright, a Pay control appeared
 * that was not there before, or the Pay control that was already there became pressable.
 *
 * Runs in the page, so it takes the entry survey as its only argument.
 */
const PAY_PANEL_APPEARED = (baseline) => {
    if (!location.hostname.includes('dgepay') && location.hostname !== '') {
        return true;
    }

    const found = Array.from(
        document.querySelectorAll('button, a, input[type="submit"], [role="button"]'),
    ).filter((el) => {
        const label = (el.innerText || el.value || '').replace(/\s+/g, ' ').trim();
        const rect = el.getBoundingClientRect();

        return /^pay\b/i.test(label) && rect.width > 2 && rect.height > 2;
    });

    const enabled = found.filter((el) => el.disabled !== true
        && el.getAttribute('aria-disabled') !== 'true'
        && !/(^|\s)disabled(\s|$)/.test(String(el.className || ''))).length;

    return found.length > baseline.total || enabled > baseline.enabled;
};

/**
 * Tick dg-epay's Terms & Conditions box.
 *
 * Done before the wallet is picked, not after. The checkbox belongs to the checkout rather than to
 * any one wallet, and Pay stays disabled until both it and a method are settled — so accepting the
 * terms first leaves the method as the only thing still gating the button, which is what makes
 * "Pay became pressable" trustworthy proof that the tile was taken.
 *
 * The change events are dispatched by hand because the page's framework binds the button's disabled
 * state to them, and a click that only flips `.checked` leaves the button disabled forever.
 *
 * @return {Promise<number>} How many boxes are ticked now that were not before.
 */
async function acceptCheckoutTerms(page) {
    const ticked = await page.evaluate(() => {
        let count = 0;

        document.querySelectorAll('input[type="checkbox"]').forEach((el) => {
            const rect = el.getBoundingClientRect();
            const reachable = rect.width > 0 || rect.height > 0 || el.offsetParent !== null;
            if (el.checked || !reachable) {
                return;
            }

            el.click();
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));

            if (el.checked) {
                count++;
            }
        });

        return count;
    }).catch(() => 0);

    if (ticked > 0) {
        log(`accepted the checkout terms (${ticked} checkbox(es))`);
    }

    return ticked;
}

/**
 * Press Pay, the step that actually hands the checkout to the bank.
 *
 * Selecting a wallet used to be a cross-origin navigation on its own; dg-epay now answers it with a
 * confirm panel — a Terms & Conditions checkbox and a "Pay (৳ 1,500.00)" button — and stays put
 * until both are dealt with. Without this the run clicked the wallet tile 16 times waiting for a
 * host change that only comes after Pay (link 1292).
 *
 * The terms are re-accepted and interstitials re-closed on every pass: both can appear late, and
 * either one leaves Pay unpressable while the loop reports nothing but a step timeout.
 */
async function authorizeDgepayCheckout(page, {
    timeout = STEP_TIMEOUT_MS,
    // Overridable so the offline self-test can drive this against a synthetic page, where there is
    // no hostname to change. Production always uses the real check.
    stillOnGateway = () => location.hostname.includes('dgepay'),
} = {}) {
    const deadline = Date.now() + timeout;
    let pressed = 0;
    let refused = 0;

    while (Date.now() < deadline) {
        if (!await page.evaluate(stillOnGateway).then((v) => v === true).catch(() => false)) {
            log(`checkout handed over to ${page.url().split('/')[2] || 'the bank'} after ${pressed} Pay click(s)`);

            return;
        }

        const blocked = await checkoutBlockReason(page);
        if (blocked !== null) {
            throw new Error(blocked);
        }

        await dismissInterstitials(page);
        await acceptCheckoutTerms(page);

        const clicked = await page.evaluate(() => {
            const button = Array.from(
                document.querySelectorAll('button, a, input[type="submit"], [role="button"]'),
            ).find((el) => {
                const label = (el.innerText || el.value || '').replace(/\s+/g, ' ').trim();
                const rect = el.getBoundingClientRect();

                return /^pay\b/i.test(label)
                    && el.disabled !== true
                    && el.getAttribute('aria-disabled') !== 'true'
                    && !/(^|\s)disabled(\s|$)/.test(String(el.className || ''))
                    && rect.width > 2 && rect.height > 2;
            });

            if (!button) {
                return false;
            }

            button.click();

            return true;
        }).catch(() => false);

        clicked ? pressed++ : refused++;

        await sleep(1000);
    }

    // "0 Pay click(s)" and "20 Pay click(s)" are different failures — a button that was never
    // pressable means the checkout is not ready to be paid (no wallet taken, terms not registered),
    // while one pressed repeatedly means the gateway ignored it. Naming which happened is the
    // difference between reading link 1293's log and having to guess at it.
    const note = pressed === 0
        ? `Pay was never pressable (${refused} pass(es) found it disabled or absent)`
        : `${pressed} Pay click(s) did not hand it over`;

    throw new Error(`The checkout stayed on dg-epay — ${note} — at ${page.url()}. Offered: ${await describeChoices(page)}`);
}

/**
 * Inventory of whatever page the driver is actually looking at.
 *
 * A "field not found" error that names only the missing selector cannot distinguish "wrong
 * selectors for the right page" from "a page we have never seen" — and the run is over by the
 * time anyone reads it, with the checkout expired and no way to look. Naming the URL, the form
 * fields and the visible text makes a single failed attempt enough to write the next selector.
 */
async function describePage(page) {
    const snapshot = await page.evaluate(() => ({
        url: location.href,
        fields: Array.from(document.querySelectorAll('input, select, textarea')).map((el) => ({
            tag: el.tagName.toLowerCase(),
            type: el.getAttribute('type') || '',
            name: el.getAttribute('name') || '',
            id: el.id || '',
            placeholder: el.getAttribute('placeholder') || '',
            maxlength: el.getAttribute('maxlength') || '',
            hidden: el.type === 'hidden' || el.offsetParent === null,
        })),
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 400),
    })).catch(() => null);

    if (snapshot === null) {
        return 'page state unavailable';
    }

    const fields = snapshot.fields
        .map((f) => `${f.tag}[type=${f.type}${f.name ? ` name=${f.name}` : ''}`
            + `${f.id ? ` id=${f.id}` : ''}${f.placeholder ? ` ph="${f.placeholder}"` : ''}`
            + `${f.maxlength ? ` maxlength=${f.maxlength}` : ''}${f.hidden ? ' HIDDEN' : ''}]`)
        .join(' ');

    return `at ${snapshot.url} | fields: ${fields || '(none)'} | text: ${snapshot.text}`;
}

async function typeInto(page, selectors, value, { timeout = STEP_TIMEOUT_MS } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        for (const selector of selectors) {
            const handle = await page.$(selector);
            if (handle) {
                await handle.click({ clickCount: 3 });
                await handle.type(String(value), { delay: 40 });
                await handle.dispose();
                return selector;
            }
        }
        await sleep(250);
    }
    return null;
}

/**
 * Fill one of Nagad's split digit fields (11 boxes for the wallet, 4 for the PIN).
 *
 * The page has no single input for these values: `.box-inputs input` is a row of maxlength=1
 * boxes, and the form's own submit handler merges them, RSA-encrypts the result and writes it into
 * a hidden field. Typing into that hidden field instead leaves the boxes empty, the merge yields
 * an empty string, and the request is submitted with no account number — which is exactly how
 * three runs reached Nagad's OTP screen without Nagad ever sending a code.
 *
 * Typed with the keyboard so the page's keyup handler advances focus the way it does for a human,
 * then verified by reading the boxes back; a mismatch falls back to setting each box directly,
 * since the merge reads the DOM value either way.
 */
async function fillBoxInputs(page, formSelector, digits) {
    const value = String(digits);
    const boxSelector = `${formSelector} .box-inputs input`;
    const boxes = await page.$$(boxSelector);

    if (boxes.length === 0) {
        throw new Error(`No digit boxes found under ${formSelector} — the gateway page is not the one this driver expects.`);
    }
    if (boxes.length < value.length) {
        throw new Error(`${formSelector} has ${boxes.length} digit boxes but ${value.length} digits were supplied.`);
    }

    await boxes[0].click();
    await page.keyboard.type(value, { delay: 60 });

    let merged = await page.$$eval(boxSelector, (els) => els.map((el) => el.value).join(''));
    if (merged !== value) {
        await page.$$eval(boxSelector, (els, chars) => {
            els.forEach((el, i) => {
                el.value = chars[i] ?? '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }, value.split(''));
        merged = await page.$$eval(boxSelector, (els) => els.map((el) => el.value).join(''));
    }

    if (merged !== value) {
        throw new Error(`Could not fill ${formSelector}: boxes hold "${merged}" instead of the ${value.length} digits supplied.`);
    }

    return merged;
}

/**
 * Whatever Nagad is currently showing in its `.messages` area — the only place it reports a bad
 * account number, an expired session or a wrong OTP.
 */
async function nagadMessage(page) {
    return page.evaluate(() => {
        const el = document.querySelector('.messages');
        return el ? (el.innerText || '').replace(/\s+/g, ' ').trim() : '';
    }).catch(() => '');
}

/**
 * Wait for one of Nagad's checkout forms to be on screen, surfacing its error text if the step
 * was rejected instead of advancing.
 */
async function waitForNagadForm(page, formSelector, { timeout = STEP_TIMEOUT_MS, what } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (await page.$(formSelector)) {
            return true;
        }
        await sleep(250);
    }

    const message = await nagadMessage(page);
    const url = page.url();
    throw new Error(`${what} did not appear${message ? ` — Nagad said: "${message}"` : ''} (at ${url})`);
}

/**
 * Poll the portal for the wallet OTP.
 *
 * `sinceMs` is captured before the submit that triggers the SMS, so a stale code from an earlier
 * attempt can never be consumed as this run's.
 */
async function waitForOtp(job, sinceMs, budgetMs) {
    const deadline = Date.now() + budgetMs;
    const url = `${job.otp_url}?since=${sinceMs}`;

    while (Date.now() < deadline) {
        try {
            const response = await fetch(url, {
                headers: { Authorization: `Bearer ${job.otp_token}`, Accept: 'application/json' },
            });
            if (response.ok) {
                const body = await response.json();
                if (body && body.otp_code) {
                    log(`OTP received after ${Math.round((budgetMs - (deadline - Date.now())) / 1000)}s`);
                    return String(body.otp_code);
                }
            } else if (response.status === 401 || response.status === 403) {
                throw new Error(`OTP ticket rejected (HTTP ${response.status})`);
            }
        } catch (err) {
            if (String(err.message || '').includes('ticket rejected')) throw err;
            // Transient fetch failure — keep polling until the budget runs out.
        }
        await sleep(OTP_POLL_INTERVAL_MS);
    }

    throw new Error('Timed out waiting for the wallet OTP.');
}

/**
 * Resolve the final IVAC callback URL.
 *
 * Watched two ways because the SPA has used both: a real navigation, and a same-page assignment
 * that only shows up as a request. Whichever fires first wins.
 *
 * Watched across every tab, not just the one the checkout opened in: a popup is a separate Page,
 * invisible to listeners bound to the checkout's own page, so a callback handed over there would
 * be indistinguishable from one that never arrived at all.
 */
function watchForCallback(page, browser) {
    return new Promise((resolve) => {
        let settled = false;
        const finish = (url) => {
            if (settled) return;
            settled = true;
            resolve(url);
        };

        const inspect = (candidate) => {
            if (!candidate) return;
            const match = String(candidate).match(CALLBACK_URL_PATTERN);
            if (match) finish(match[0]);
        };

        const watch = (target) => {
            target.on('request', (req) => inspect(req.url()));
            target.on('framenavigated', (frame) => inspect(frame.url()));
            target.on('response', (res) => {
                inspect(res.url());

                // The bank hands the callback over as the Location of a 3xx, so the URL exists in
                // that header before the browser has gone anywhere. Watching only for the follow-up
                // navigation means waiting out a round trip that has already told us the answer —
                // and if the browser never follows the redirect at all, the header is the only place
                // the URL is ever seen. A relative Location cannot match the absolute api.ivacbd.com
                // pattern, so it is ignored rather than mis-captured.
                const headers = res.headers();
                if (headers) {
                    inspect(headers.location || headers.Location);
                }
            });
        };

        watch(page);

        if (!browser) {
            return;
        }

        browser.on('targetcreated', async (target) => {
            if (target.type() !== 'page') {
                return;
            }

            // Read the target's own URL first and again after attaching: a tab opened straight at
            // the callback can finish navigating in the time it takes to get a Page handle for it,
            // and listeners attached afterwards would have missed the only event it ever fires.
            inspect(target.url());

            const opened = await target.page().catch(() => null);
            if (!opened) {
                return;
            }

            // Also the plainest possible signal that the checkout left the tab the driver is
            // probing — which would otherwise be reported as a page that simply stopped moving.
            log(`adopted a new browser tab: ${opened.url()}`);
            watch(opened);
            inspect(opened.url());
        });
    });
}

// ---------------------------------------------------------------------------
// Per-wallet step sequences
// ---------------------------------------------------------------------------

/**
 * Nagad — driven against the DOM captured in the HAR, on payment.mynagad.com:30000:
 *   check-out/{ref} -> verify-account (wallet) -> verify-otp (otp) -> confirm-payment (pin)
 *
 * Each page is a plain jQuery form whose submit handler merges a row of single-digit boxes,
 * RSA-encrypts the result with jsencrypt and posts it in a hidden field. The values are therefore
 * typed into the visible boxes, never into the hidden field — see fillBoxInputs.
 *
 * Selecting Nagad on dgepay is a cross-origin navigation, so the driver waits for the Nagad host
 * rather than assuming a fixed delay is enough.
 */
async function driveNagad(page, job, ctx) {
    ctx.stage = 'select_method';
    await selectMethod(page, ['nagad'], {
        what: 'Nagad',
        groups: MOBILE_BANKING_GROUPS,
        assets: WALLET_LOGO_ASSETS[METHODS.NAGAD],
        expect: () => location.hostname.includes('mynagad.com'),
    });
    await waitForNagadForm(page, '#account-form', { what: "Nagad's wallet-number form" });

    ctx.stage = 'wallet';
    await fillBoxInputs(page, '#account-form', job.wallet);

    // Capture the clock before the submit that makes Nagad send the SMS.
    const otpSince = Date.now();
    ctx.stage = 'submit_wallet';
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: STEP_TIMEOUT_MS }).catch(() => null),
        clickFirst(page, ['#account-form button[type="submit"]'], { timeout: 5000 }),
    ]);

    // The OTP form only renders once Nagad has accepted the number and sent a code, so this is
    // also the proof that an SMS is actually on its way.
    await waitForNagadForm(page, '#otp-form', { what: "Nagad's OTP form (wallet number rejected?)" });

    ctx.stage = 'await_otp';
    const otp = await waitForOtp(job, otpSince, Math.min(OTP_WAIT_MS, ctx.remainingMs()));

    ctx.stage = 'otp';
    await page.click('#otp', { clickCount: 3 });
    await page.type('#otp', String(otp), { delay: 40 });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: STEP_TIMEOUT_MS }).catch(() => null),
        clickFirst(page, ['#otp-form button[type="submit"]'], { timeout: 5000 }),
    ]);

    await waitForNagadForm(page, '#pin-form', { what: "Nagad's PIN form (OTP rejected or expired?)" });

    ctx.stage = 'pin';
    await fillBoxInputs(page, '#pin-form', job.pin);

    ctx.stage = 'confirm';
    await clickFirst(page, ['#confirmButton', '#pin-form button[type="submit"]'], { timeout: 5000 });
}

/**
 * Rocket — driven against the DOM captured in the HAR, on DBBL Nexus (ecom1.dutchbanglabank.com):
 *   ClientHandler?card_type=6 (account + PIN) -> rsaotp_re/checkRSA (OTP) -> ClientHandler?do=SUBMIT
 *
 * Rocket is Dutch-Bangla's wallet, and dg-epay routes it onto DBBL's *card* handler — but
 * `crverify_taka_pay_12.js` reconfigures that form into "Mobile Account" mode for card_type 6: it
 * relabels `#cardnr` to "Mobile Account" at maxlength 12, relabels `#cvc2` to "PIN" at maxlength 4,
 * and hides the expiry entirely. So no card number, expiry or CVC is needed — the account's stored
 * 12-digit wallet and 4-digit PIN are exactly the two fields, and the older note claiming otherwise
 * was wrong.
 *
 * Two traps live here. `USER_NAME` and `sec_val` are hidden fields the page fills *itself* from
 * `#cardnr`/`#cvc2` on submit, so typing into them submits nothing — the same trap as Nagad's box
 * inputs. And unlike Nagad, DBBL takes the PIN up front with the account number and asks for the
 * OTP afterwards, so the step order here is account+PIN -> OTP, not account -> OTP -> PIN.
 */
async function driveRocket(page, job, ctx) {
    ctx.stage = 'select_method';
    // The checkout can open behind a phone-number interstitial that covers the method list; close it
    // before anything else, or the tiles are not merely unscanned but unclickable.
    const dismissed = await dismissInterstitials(page);
    if (dismissed !== null) {
        log(`dismissed an interstitial via "${dismissed}"`);
        await sleep(1000);
    }

    // Terms first, wallet second. Both gate Pay, and settling the terms up front leaves the wallet
    // as the only thing still holding the button disabled — which is what lets the step below read
    // "Pay became pressable" as proof the tile was taken.
    await acceptCheckoutTerms(page);

    // Picking a wallet no longer leaves dg-epay: it reveals a confirm panel, and only Pay hands the
    // checkout to the bank. Either outcome counts as "the tile was taken" — but the survey is
    // re-taken until the first click lands, so a Pay control that merely painted late can never be
    // mistaken for proof of a selection that has not happened yet (link 1293).
    const payBaseline = await surveyPayControls(page);
    await selectMethod(page, ['rocket', 'dbbl'], {
        what: 'Rocket',
        groups: MOBILE_BANKING_GROUPS,
        assets: WALLET_LOGO_ASSETS[METHODS.ROCKET],
        expectArg: payBaseline,
        rebaseline: surveyPayControls,
        expect: PAY_PANEL_APPEARED,
    });

    ctx.stage = 'authorize';
    await authorizeDgepayCheckout(page);
    await sleep(1500);

    ctx.stage = 'wallet';
    if (!await typeInto(page, ['#cardnr', 'input[name="cardnr"]'], job.wallet)) {
        throw new Error('Rocket mobile-account field not found — ' + await describePage(page));
    }

    // Read back rather than trust the type: this form blocks paste and derives its submitted values
    // from these boxes, so a value that did not land is submitted as an empty account number.
    const typedWallet = await page.$eval('#cardnr', (el) => el.value).catch(() => '');
    if (typedWallet !== String(job.wallet)) {
        throw new Error(`The mobile-account field holds "${typedWallet}" instead of the ${String(job.wallet).length}-digit wallet.`);
    }

    ctx.stage = 'wallet_pin';
    if (!await typeInto(page, ['#cvc2', 'input[name="cvc2"]'], job.pin)) {
        throw new Error('Rocket PIN field not found — ' + await describePage(page));
    }

    // Capture the clock before the submit that makes DBBL send the SMS.
    const otpSince = Date.now();
    ctx.stage = 'submit_wallet';
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: STEP_TIMEOUT_MS }).catch(() => null),
        clickFirst(page, ['#pay', '#cardentry input[type="submit"]'], { timeout: 5000 }),
    ]);
    await sleep(2000);

    // DBBL's 2FA page is the proof the account and PIN were accepted and a code is on its way.
    await assertOtpPage(page);

    ctx.stage = 'await_otp';
    const otp = await waitForOtp(job, otpSince, Math.min(OTP_WAIT_MS, ctx.remainingMs()));

    ctx.stage = 'otp';
    const otpSelector = await typeInto(page, ['#passCode', 'input[name="passCode"]'], otp);
    if (!otpSelector) {
        throw new Error('Rocket OTP field not found — ' + await describePage(page));
    }

    ctx.stage = 'confirm';
    // The 2FA form submits through an <input type="image">, which no button selector matches — and
    // a press it reports as delivered does not always post the form, so the outcome is verified.
    log('OTP submit: ' + await submitOtpAndVerify(page, otpSelector, otp));
}

/**
 * bKash — not implemented. No bKash HAR has been captured, so its selectors and step order are
 * unknown; guessing them would risk a real charge landing in an unrecoverable state.
 */
async function driveBkash() {
    throw new Error('BKASH_NOT_IMPLEMENTED: capture a bKash checkout HAR before enabling this method.');
}

const DRIVERS = {
    [METHODS.NAGAD]: driveNagad,
    [METHODS.ROCKET]: driveRocket,
    [METHODS.BKASH]: driveBkash,
};

/**
 * Snapshot of where the gateway landed after the wallet submit.
 *
 * An OTP field on screen means the wallet was accepted and the gateway sent a code. Also captures
 * the visible text so a rejection ("invalid account", "session expired") is readable without a
 * screenshot.
 */
async function describeOtpPage(page) {
    const OTP_SELECTORS = [
        'input[name="otp"]',
        'input#otpNumber',
        'input[name="passCode"]',
        'input#otp',
        'input[autocomplete="one-time-code"]',
    ];

    let otpFieldFound = null;
    for (const selector of OTP_SELECTORS) {
        if (await page.$(selector)) {
            otpFieldFound = selector;
            break;
        }
    }

    const details = await page.evaluate(() => ({
        title: document.title,
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 600),
        inputs: Array.from(document.querySelectorAll('input')).map((i) => ({
            name: i.name || null,
            id: i.id || null,
            type: i.type || null,
        })),
    }));

    return {
        otpFieldFound: otpFieldFound !== null,
        otpSelector: otpFieldFound,
        url: page.url(),
        title: details.title,
        visible_text: details.text,
        inputs: details.inputs,
    };
}

/**
 * Snapshot of where the gateway landed after the OTP submit.
 *
 * A PIN field on screen means the wallet number and the OTP were both accepted, which is everything
 * short of the charge itself.
 */
async function describePinPage(page) {
    const PIN_SELECTORS = [
        'input[name="encryptedPin"]',
        'input[name="pin"]',
        'input#pin',
        'input[type="password"]',
    ];

    let pinFieldFound = null;
    for (const selector of PIN_SELECTORS) {
        if (await page.$(selector)) {
            pinFieldFound = selector;
            break;
        }
    }

    const details = await page.evaluate(() => ({
        title: document.title,
        text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 600),
        inputs: Array.from(document.querySelectorAll('input')).map((i) => ({
            name: i.name || null,
            id: i.id || null,
            type: i.type || null,
        })),
    }));

    return {
        pinFieldFound: pinFieldFound !== null,
        pinSelector: pinFieldFound,
        url: page.url(),
        title: details.title,
        visible_text: details.text,
        inputs: details.inputs,
    };
}

/**
 * Fail fast unless the wallet submit actually moved the gateway on to its OTP step.
 *
 * Without this the run cannot tell a rejected wallet from a slow SMS: it would advance to
 * await_otp regardless and sit there until the whole budget expired, reporting a timeout that
 * blames the forwarder for a gateway refusal. The page state is folded into the error so the
 * reason is visible on the log page.
 */
async function assertOtpPage(page) {
    const probe = await describeOtpPage(page);
    if (probe.otpFieldFound) {
        return probe;
    }

    throw new Error(`Wallet submitted but no OTP field appeared — the gateway did not send a code. Page: ${probe.title || probe.url} | ${probe.visible_text}`);
}

/**
 * Fail fast unless the OTP submit was accepted and the PIN step is on screen.
 *
 * Guards the one irreversible step: without it a wrong or expired OTP leaves the driver typing the
 * PIN into whatever page is showing and submitting blind.
 */
async function assertPinPage(page) {
    const probe = await describePinPage(page);
    if (probe.pinFieldFound) {
        return probe;
    }

    throw new Error(`OTP submitted but no PIN field appeared — the code was likely rejected or expired. Page: ${probe.title || probe.url} | ${probe.visible_text}`);
}

/**
 * The OTP form as it stands right now — enough to tell a submit that landed from one that did not.
 *
 * Returns null when the page is mid-navigation and the execution context is gone, which is itself
 * proof that something moved.
 */
async function otpFormState(page, selector) {
    return page.evaluate((sel) => {
        const field = document.querySelector(sel);

        return {
            url: location.href,
            present: field !== null,
            value: field === null ? null : field.value,
            text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 600),
        };
    }, selector).catch(() => null);
}

/**
 * Did the page move at all between two OTP-form snapshots?
 *
 * A bank that answered navigates, drops the field, or clears it — a re-rendered challenge comes
 * back with an empty box. Only an unchanged URL still holding the typed code means nothing happened.
 */
function otpFormMoved(before, after, typed) {
    return after === null
        || !after.present
        || after.url !== before.url
        || after.value !== typed
        || after.text !== before.text;
}

/**
 * Type the OTP, prove it landed, submit, and prove the submit landed.
 *
 * Link 1303 typed a fresh code, clicked DBBL's <input type="image">, and sat on the same checkRSA
 * challenge for 170s with no error text and no callback. Both halves work against a synthetic copy
 * of that form, so the failure is something only the live page shows — and the run captured nothing
 * to say which half broke. This closes both gaps.
 *
 * The read-back mirrors the wallet field: this form derives what it posts from the box, so a value
 * that did not land submits an empty code. Checking before the click costs nothing and fails safely,
 * because no money can move until the form is submitted.
 *
 * The re-submit is deliberately narrow. It fires only when the field still holds the typed code at
 * the same URL with the same text — the state that proves the form was never posted — so it
 * completes a submit that never happened rather than repeating one that did. Anything else is
 * treated as the bank having answered and is never re-sent, whatever the answer was.
 */
async function submitOtpAndVerify(page, selector, otp, { navTimeoutMs = STEP_TIMEOUT_MS } = {}) {
    const landed = await page.$eval(selector, (el) => el.value).catch(() => '');
    if (landed !== String(otp)) {
        await page.$eval(selector, (el, code) => {
            el.value = code;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, String(otp)).catch(() => null);

        const retyped = await page.$eval(selector, (el) => el.value).catch(() => '');
        if (retyped !== String(otp)) {
            throw new Error(`The OTP did not land in ${selector}: it holds "${retyped}" instead of the ${String(otp).length}-digit code. Not submitting.`);
        }
    }

    const SUBMIT_SELECTORS = [
        'form[name="authForm"] input[type="image"]',
        'input[type="image"].submit',
        'input[type="image"]',
    ];

    const before = await otpFormState(page, selector);
    if (before === null) {
        return 'page navigated away before the OTP could be submitted';
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: navTimeoutMs }).catch(() => null),
        clickFirst(page, SUBMIT_SELECTORS, { timeout: 5000 })
            .then((hit) => (hit === null ? submitCurrentForm(page) : hit)),
    ]);

    let after = await otpFormState(page, selector);
    if (otpFormMoved(before, after, String(otp))) {
        return `submitted — the bank moved on to ${after === null ? '(navigating)' : after.url}`;
    }

    // Nothing moved. The click was reported as delivered but the form was never posted, so the one
    // safe recovery is to press it again the way a human does: a real mouse click on the control's
    // current box, which also carries the image coordinates a programmatic submit would drop.
    log('OTP submit had no effect — retrying the press on an unposted form');

    for (const selectorToPress of SUBMIT_SELECTORS) {
        const box = await page.$(selectorToPress).then((h) => (h === null ? null : h.boundingBox().finally(() => h.dispose())));
        if (box === null) {
            continue;
        }

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: navTimeoutMs }).catch(() => null),
            page.mouse.click(box.x + (box.width / 2), box.y + (box.height / 2)).catch(() => null),
        ]);

        after = await otpFormState(page, selector);
        if (otpFormMoved(before, after, String(otp))) {
            return `submitted on a second press of ${selectorToPress}`;
        }
    }

    // The control cannot be pressed at all. Submitting the form itself runs its own onsubmit
    // handler, so an RSA form still encrypts what it posts.
    const requested = await page.evaluate((sel) => {
        const field = document.querySelector(sel);
        const form = field === null ? null : field.form;
        if (form === null) {
            return false;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }

        return true;
    }, selector).catch(() => false);

    if (requested) {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: navTimeoutMs }).catch(() => null),
            sleep(CLICK_EFFECT_MS),
        ]);

        after = await otpFormState(page, selector);
        if (otpFormMoved(before, after, String(otp))) {
            return 'submitted through the form after the image button could not be pressed';
        }
    }

    throw new Error(`The OTP form could not be submitted: it still holds the code at ${before.url} after a click, a second press and a form submit. Page ${await describePage(page)}`);
}

/**
 * What the bank did with the final submit.
 *
 * The last thing a run can still see. Once the callback wait starts, a submit that never landed
 * and a submit the bank refused are the same bare timeout four minutes later, with the checkout
 * expired and the page gone — which is exactly how link 1301 ended with nothing to read after
 * "OTP received". An OTP field still on screen says the click never submitted; any other page
 * means the bank answered, and its text is the answer.
 *
 * Never throws and never fails a run: the submit may have moved real money, so this is a
 * diagnostic only. A probe that lands mid-navigation loses its execution context, so it looks
 * once more after letting the navigation settle.
 */
async function describeSubmitOutcome(page, { settleMs = 2000 } = {}) {
    await sleep(settleMs);

    for (let pass = 0; pass < 2; pass++) {
        const probe = await describeOtpPage(page).catch(() => null);

        if (probe !== null) {
            return probe.otpFieldFound
                ? `still on the OTP form (${probe.otpSelector}) at ${probe.url} — the submit did not land`
                    + `${probe.visible_text ? ` | ${probe.visible_text}` : ''}`
                : `${probe.title || '(untitled)'} at ${probe.url}`
                    + `${probe.visible_text ? ` | ${probe.visible_text}` : ''}`;
        }

        await sleep(1000);
    }

    return 'page state unavailable — still navigating';
}

/**
 * Submit whatever form is in focus — a submit button if there is one, Enter otherwise. The bank
 * pages are plain jQuery forms with inconsistent button markup.
 */
async function submitCurrentForm(page) {
    const clicked = await clickFirst(page, [
        'button[type="submit"]',
        'input[type="submit"]',
        // DBBL's 2FA form submits through an image button, which the type=submit selectors miss.
        'input[type="image"]',
        'button#btnSubmit',
        'button.btn-primary',
        '#submitBtn',
    ], { timeout: 5000 });

    if (!clicked) {
        await page.keyboard.press('Enter');
    }
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

async function run(job) {
    const startedAt = Date.now();
    const deadline = startedAt + (job.timeout_ms || 240_000);
    const ctx = {
        // Backing field for the `stage` accessor below.
        _stage: 'launch',
        remainingMs: () => Math.max(0, deadline - Date.now()),
    };

    // Every `ctx.stage = x` also emits a framed marker on stdout. The portal reads the process
    // output incrementally, so the attempt row tracks the run live instead of only learning the
    // stage once the driver exits — without this, a running attempt shows no progress at all.
    Object.defineProperty(ctx, 'stage', {
        get() {
            return ctx._stage;
        },
        set(value) {
            ctx._stage = value;
            process.stdout.write(`<<<STAGE>>>${value}<<</STAGE>>>\n`);
        },
    });
    ctx.stage = 'launch';

    const driver = DRIVERS[String(job.method || '').toLowerCase()];
    if (!driver) {
        return { ok: false, stage: 'method', error: `Unsupported payment method: ${job.method}` };
    }

    let browser;
    try {
        browser = await puppeteer.launch({ headless: true, args: CHROME_ARGS });
        const page = await browser.newPage();
        await page.setUserAgent(USER_AGENT);
        await page.setViewport({ width: 1280, height: 900 });

        // Recorded as it resolves, so the probe below can be skipped entirely when the callback has
        // already been seen — a run that worked must not pay a settle delay for a diagnostic that
        // has nothing left to explain.
        let callbackSeen = null;
        const callbackPromise = watchForCallback(page, browser).then((url) => {
            callbackSeen = url;

            return url;
        });

        ctx.stage = 'navigate';
        log(`opening checkout for method=${job.method}`);
        await page.goto(job.checkout_url, { waitUntil: 'domcontentloaded', timeout: STEP_TIMEOUT_MS });

        await driver(page, job, ctx);

        ctx.stage = 'await_callback';

        // Probed here rather than after the wait: by then the bank's answer has often been replaced
        // by a session-expired page, and a failed run's only account of itself would be the timeout.
        if (callbackSeen === null) {
            log(`checkout submitted — ${await describeSubmitOutcome(page)}`);
        }

        const waitStartedAt = Date.now();
        const budget = cancellableTimeout(ctx.remainingMs());
        const callbackUrl = await Promise.race([callbackPromise, budget.promise]);
        // Disarm it the moment the callback wins, or the process stays alive until the deadline
        // it was given — reporting a finished payment minutes after it actually finished.
        budget.cancel();

        if (!callbackUrl) {
            // The settled page after the full wait, on top of the landing state logged above: between
            // the two, a bank that answered late is separable from one that never answered at all.
            return {
                ok: false,
                stage: ctx.stage,
                error: `Checkout finished without an IVAC callback URL. Waited ${((Date.now() - waitStartedAt) / 1000).toFixed(0)}s; `
                    + `last page ${await describePage(page)}`,
            };
        }

        // Both numbers go in the console log, not just the result payload: the payload's timings
        // were computed all along and then dropped on the floor by the service, so every run's real
        // duration was unknowable and an idle timer masqueraded as a slow bank for four runs.
        const waitMs = Date.now() - waitStartedAt;
        const totalMs = Date.now() - startedAt;
        log(`callback URL captured after ${(waitMs / 1000).toFixed(1)}s waiting `
            + `(${(totalMs / 1000).toFixed(1)}s for the whole checkout)`);

        return {
            ok: true,
            stage: 'callback',
            callback_url: callbackUrl,
            timings: { total_ms: totalMs, await_callback_ms: waitMs },
        };
    } catch (err) {
        return {
            ok: false,
            stage: ctx.stage,
            error: String((err && err.message) || err),
            timings: { total_ms: Date.now() - startedAt },
        };
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
    }
}

function emit(result) {
    process.stdout.write(`<<<JSON>>>${JSON.stringify(result)}<<</JSON>>>\n`);
}

async function readStdin() {
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);
    return Buffer.concat(chunks).toString('utf8');
}

/**
 * Offline check that the wallet number lands in the boxes the page actually reads.
 *
 * A stand-in for Nagad's wallet form, reproducing the structure that broke the driver: the real
 * value lives in a row of maxlength=1 boxes and the named field is a hidden one the page fills
 * itself on submit. Typing into the hidden field must not count as success.
 */
async function selfTestBoxInputs(page) {
    const boxes = Array.from({ length: 11 }, () => '<input maxlength="1">').join('');
    await page.setContent(`<html><body><form id="account-form">
        <div class="box-inputs">${boxes}</div>
        <input type="hidden" id="encryptedPhoneNum" name="encryptedPayeeAccountNumber" value="">
    </form></body></html>`);

    const merged = await fillBoxInputs(page, '#account-form', '01865144147');
    const hidden = await page.$eval('#encryptedPhoneNum', (el) => el.value);

    return { merged, hidden_untouched: hidden === '' };
}

/**
 * Offline check that method selection clicks the wallet tile and nothing else.
 *
 * The page is shaped like dg-epay's: three tiles inside a wrapper whose own text contains every
 * wallet name. Selecting the first element that merely contains "nagad" picks that wrapper, whose
 * click does nothing — a failure that reports success and only surfaces as a step timeout. The
 * assertions are therefore that the run reaches the Nagad destination, and that Nagad is the only
 * tile whose handler ever fired.
 */
async function selfTestTileSelection(page) {
    await page.setContent(`<html><body>
        <div class="page-wrap"><div class="container">
            <h5>Select your payment method</h5>
            <div class="row methods">
                <div class="col card" data-m="bkash"><img alt="bKash"><p>bKash</p></div>
                <div class="col card" data-m="nagad"><img alt="Nagad"><p>Nagad</p></div>
                <div class="col card" data-m="rocket"><img alt="Rocket"><p>Rocket</p></div>
            </div>
        </div></div>
        <script>
            window.__fired = [];
            document.querySelectorAll('.card').forEach((el) => {
                el.addEventListener('click', () => {
                    window.__fired.push(el.dataset.m);
                    location.hash = '#' + el.dataset.m;
                });
            });
        </script>
    </body></html>`);

    let selectError = null;
    try {
        await selectMethod(page, ['nagad'], {
            what: 'Nagad',
            expect: () => location.hash === '#nagad',
            timeout: 10_000,
        });
    } catch (err) {
        selectError = String((err && err.message) || err);
    }

    const fired = await page.evaluate(() => window.__fired);

    return {
        tile_selected: selectError === null,
        tiles_fired: fired,
        // Clicking any other wallet would be a real charge against the wrong account.
        only_nagad_fired: fired.length > 0 && fired.every((m) => m === 'nagad'),
        select_error: selectError,
    };
}

/**
 * Offline check that a wallet filed under a category is still reached — and that prose is not
 * mistaken for a tile.
 *
 * Shaped like the method list that failed link 1291: the wallets are one level down behind
 * "Mobile Banking (bKash, Nagad, Upay etc.)", and the only text naming Rocket on the first screen
 * is the Bangla QR paragraph. Clicking that paragraph is what the driver used to do 17 times.
 */
async function selfTestGroupedSelection(page) {
    await page.setContent(`<html><body>
        <div class="page-wrap">
            <h5>Please select payment method</h5>
            <div class="qr"><p>You can use Rocket, Upay, bKash, or any other supported banking and
                financial app to scan the Bangla QR</p></div>
            <h6>Other methods</h6>
            <ul class="groups">
                <li data-g="net">Net Banking</li>
                <li data-g="card">Card</li>
                <li data-g="mfs">Mobile Banking (bKash, Nagad, Upay etc.)</li>
            </ul>
            <div class="row wallets" style="display:none">
                <div class="col card" data-m="bkash"><p>bKash</p></div>
                <div class="col card" data-m="nagad"><p>Nagad</p></div>
                <div class="col card" data-m="rocket"><p>Rocket</p></div>
            </div>
        </div>
        <script>
            window.__fired = [];
            window.__prose = 0;
            document.querySelector('.qr').addEventListener('click', () => { window.__prose++; });
            document.querySelectorAll('.groups li').forEach((el) => {
                el.addEventListener('click', () => {
                    window.__fired.push('group:' + el.dataset.g);
                    if (el.dataset.g === 'mfs') {
                        document.querySelector('.wallets').style.display = 'flex';
                    }
                });
            });
            document.querySelectorAll('.wallets .card').forEach((el) => {
                el.addEventListener('click', () => {
                    window.__fired.push(el.dataset.m);
                    location.hash = '#' + el.dataset.m;
                });
            });
        </script>
    </body></html>`);

    let selectError = null;
    try {
        await selectMethod(page, ['rocket', 'dbbl'], {
            what: 'Rocket',
            groups: MOBILE_BANKING_GROUPS,
            expect: () => location.hash === '#rocket',
            timeout: 15_000,
        });
    } catch (err) {
        selectError = String((err && err.message) || err);
    }

    const fired = await page.evaluate(() => window.__fired);
    const prose = await page.evaluate(() => window.__prose);

    return {
        grouped_selected: selectError === null,
        grouped_fired: fired,
        // The QR blurb is not a tile; clicking it is pure wasted step budget.
        prose_untouched: prose === 0,
        // Any wallet other than the one asked for would be a real charge on the wrong account.
        only_rocket_fired: fired.filter((m) => !m.startsWith('group:')).every((m) => m === 'rocket'),
        grouped_error: selectError,
    };
}

/**
 * Offline check against the method list as links 1292 and 1293 actually served it, plus DBBL's form
 * whose submitted values are derived from the visible boxes.
 *
 * Three traps are built into this page, each one having cost a real link:
 *
 *   - The Terms box and "Pay (৳ 1,500.00)" belong to the checkout, not to the confirm panel, so
 *     they are on screen before any wallet is chosen — and Pay paints a beat late. A driver that
 *     reads "a Pay control exists that did not a moment ago" as proof of a selection returns having
 *     chosen nothing, then waits out the step at a button that never enables (link 1293).
 *   - Pay enables only once BOTH the terms are accepted and a wallet is taken, which is what makes
 *     the enabled transition mean "the tile was taken" and nothing else.
 *   - A phone-number panel covers the list on arrival. It is a real overlay, so the tile underneath
 *     is not merely unscanned but unclickable, and its "Continue" submits an empty phone number —
 *     only "Or continue without phone number" may be pressed.
 */
async function selfTestConfirmPanelAndBankForm(page) {
    await page.setContent(`<html><body>
        <div id="tiles">
            <h6>Other methods</h6>
            <ul class="groups"><li data-g="mfs">Mobile Banking (bKash, Nagad, Upay etc.)</li></ul>
            <div class="wallets" style="display:none">
                <div class="col" data-m="rocket"><p>Rocket</p></div>
            </div>
        </div>
        <div id="summary">
            <label><input type="checkbox" id="terms"> By checking this box you agree to the
                Terms &amp; Conditions</label>
        </div>
        <a id="decoy" href="#">Click to Pay</a>
        <div id="overlay" style="position:fixed;inset:0;z-index:9999;background:#fff">
            <h3>Let's get you connected!</h3>
            <p>Enter your phone number to begin</p>
            <input id="phone" placeholder="Phone Number *">
            <button id="phone-continue">Continue</button>
            <a id="phone-skip" href="#">Or continue without phone number</a>
        </div>
        <form id="bank" style="display:none">
            <input id="cardnr" name="cardnr" maxlength="12">
            <input id="cvc2" name="cvc2" type="password" maxlength="4">
            <input type="hidden" id="USER_NAME" name="USER_NAME">
            <input type="hidden" id="sec_val" name="sec_val">
        </form>
        <script>
            window.__handedOver = false;
            window.__selected = null;
            window.__phoneSubmitted = false;
            window.__skipped = false;

            // The checkout's own Pay button, painted a beat after the rest of the page.
            setTimeout(() => {
                const pay = document.createElement('button');
                pay.id = 'paybtn';
                pay.textContent = 'Pay (৳ 1,500.00)';
                pay.disabled = true;
                pay.addEventListener('click', () => {
                    window.__handedOver = true;
                    document.getElementById('tiles').style.display = 'none';
                    document.getElementById('summary').style.display = 'none';
                    pay.style.display = 'none';
                    document.getElementById('bank').style.display = 'block';
                });
                document.getElementById('summary').appendChild(pay);
            }, 600);

            const refreshPay = () => {
                const pay = document.getElementById('paybtn');
                if (pay) {
                    pay.disabled = !(document.getElementById('terms').checked && window.__selected);
                }
            };

            document.getElementById('phone-continue').addEventListener('click', () => {
                window.__phoneSubmitted = true;
            });
            document.getElementById('phone-skip').addEventListener('click', () => {
                window.__skipped = true;
                document.getElementById('overlay').remove();
            });
            document.querySelector('.groups li').addEventListener('click', () => {
                document.querySelector('.wallets').style.display = 'flex';
            });
            document.querySelector('[data-m="rocket"]').addEventListener('click', () => {
                window.__selected = 'rocket';
                refreshPay();
            });
            document.getElementById('terms').addEventListener('change', refreshPay);
        </script>
    </body></html>`);

    let error = null;
    let typedWallet = '';
    let derivedUntouched = false;
    let baseline = { total: -1, enabled: -1 };

    try {
        const dismissed = await dismissInterstitials(page);
        if (dismissed !== null) {
            await sleep(500);
        }

        await acceptCheckoutTerms(page);

        baseline = await surveyPayControls(page);
        await selectMethod(page, ['rocket', 'dbbl'], {
            what: 'Rocket',
            groups: MOBILE_BANKING_GROUPS,
            expectArg: baseline,
            rebaseline: surveyPayControls,
            expect: PAY_PANEL_APPEARED,
            timeout: 10_000,
        });

        await authorizeDgepayCheckout(page, {
            timeout: 10_000,
            stillOnGateway: () => window.__handedOver !== true,
        });

        await typeInto(page, ['#cardnr', 'input[name="cardnr"]'], '018651441477', { timeout: 5000 });
        await typeInto(page, ['#cvc2', 'input[name="cvc2"]'], '1995', { timeout: 5000 });

        typedWallet = await page.$eval('#cardnr', (el) => el.value);
        derivedUntouched = await page.evaluate(
            () => document.getElementById('USER_NAME').value === ''
                && document.getElementById('sec_val').value === '',
        );
    } catch (err) {
        error = String((err && err.message) || err);
    }

    const state = await page.evaluate(() => ({
        handed: window.__handedOver === true,
        selected: window.__selected,
        phoneSubmitted: window.__phoneSubmitted === true,
        skipped: window.__skipped === true,
    })).catch(() => ({ handed: false, selected: null, phoneSubmitted: false, skipped: false }));

    return {
        // "Click to Pay" is a different payment option. Counting it would put the baseline at 1,
        // the panel check could never rise above it, and the tile would be clicked forever.
        pay_baseline: baseline,
        panel_baseline_ignored_decoy: baseline.total === 0 || baseline.enabled === 0,
        interstitial_skipped: state.skipped,
        // Handing the gateway a phone number was never asked for, and "Continue" would submit an
        // empty one — pressing it is a wrong click, not a harmless one.
        phone_not_submitted: !state.phoneSubmitted,
        // The whole point: Pay may only be reached through a tile that was genuinely taken.
        wallet_actually_selected: state.selected === 'rocket',
        handed_over: state.handed,
        wallet_in_visible_box: typedWallet === '018651441477',
        derived_fields_untouched: derivedUntouched,
        confirm_error: error,
    };
}

/**
 * Offline check that a text-free tile is still found by its logo — and found correctly.
 *
 * dg-epay's wallet names are part of the artwork, so the tiles can carry no text at all. Matching
 * the logo's asset URL fixes that, but it reintroduces the wrapper hazard in a worse form: with no
 * labels anywhere, nothing distinguishes a tile from the row that holds every tile, and a real
 * mouse click on that row lands on whichever wallet sits at its centre. Here the centre tile is
 * bKash, so a wrapper click charges the wrong wallet — the assertion is that only Rocket fires.
 */
async function selfTestLogoSelection(page) {
    const asset = (name) => `https://media.dgepay.net/dipon-bucket/${name}`;

    await page.setContent(`<html><body>
        <div class="row" style="display:flex;width:600px">
            <div class="col" data-m="upay"><img width="150" height="90" src="${asset('1733128195Group%2022613%20(1).png')}"></div>
            <div class="col" data-m="bkash"><img width="150" height="90" src="${asset('1733128110Group%2022609%20(2).png')}"></div>
            <div class="col" data-m="rocket"><img width="150" height="90" src="${asset('1733125978Group%2022618%20(1).png')}"></div>
        </div>
        <script>
            // Deliberately not location.hash: the hash survives setContent, so an earlier check's
            // "#rocket" would satisfy this one before a single click happened.
            window.__fired = [];
            window.__selected = null;
            document.querySelectorAll('.col').forEach((el) => {
                el.addEventListener('click', () => {
                    window.__fired.push(el.dataset.m);
                    window.__selected = el.dataset.m;
                });
            });
            document.querySelector('.row').addEventListener('click', (e) => {
                if (e.target === document.querySelector('.row')) window.__fired.push('row');
            });
        </script>
    </body></html>`);

    let error = null;
    try {
        await selectMethod(page, ['rocket', 'dbbl'], {
            what: 'Rocket',
            assets: WALLET_LOGO_ASSETS[METHODS.ROCKET],
            expect: () => window.__selected === 'rocket',
            timeout: 10_000,
        });
    } catch (err) {
        error = String((err && err.message) || err);
    }

    const fired = await page.evaluate(() => window.__fired);

    return {
        logo_selected: error === null,
        logo_fired: fired,
        only_rocket_logo_fired: fired.length > 0 && fired.every((m) => m === 'rocket'),
        logo_error: error,
    };
}

/**
 * Offline check that the post-submit probe tells the two failures apart.
 *
 * The distinction it has to draw is the whole reason it exists: a 2FA form still on screen means
 * the click never submitted, while a bank page carrying a rejection means it did and the bank said
 * no. Reporting either as the other sends the next fix to the wrong place.
 */
async function selfTestSubmitOutcome(page) {
    await page.setContent(`<html><head><title>DBBL 2FA Authentication</title></head><body>
        <form name="authForm">
            <input name="passCode" maxlength="6">
            <input type="image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
        </form>
    </body></html>`);
    const stuck = await describeSubmitOutcome(page, { settleMs: 50 });

    await page.setContent(`<html><head><title>Payment Failed</title></head><body>
        <h3>Transaction Declined</h3>
        <p>Invalid PIN. Please contact to DBBL Help line: 16216.</p>
    </body></html>`);
    const answered = await describeSubmitOutcome(page, { settleMs: 50 });

    return {
        submit_stuck_report: stuck,
        submit_answered_report: answered,
        stuck_named_as_unsubmitted: stuck.includes('the submit did not land')
            && stuck.includes('passCode'),
        // A bank that answered must never be reported as a click that never landed, and its own
        // words have to survive into the report — they are the only account of why it refused.
        answered_named_as_bank_reply: !answered.includes('the submit did not land')
            && answered.includes('Payment Failed')
            && answered.includes('Invalid PIN'),
    };
}

/**
 * Offline check that the OTP submit lands exactly once, however the form behaves.
 *
 * Three shapes of DBBL 2FA form, each counting its own posts. The count is the point: a press that
 * works must not be repeated, and a press that silently does nothing must still end in one post.
 * Link 1303 was the third case with no recovery and no evidence, and a driver that recovered by
 * posting twice would be far worse than one that gave up.
 */
async function selfTestOtpSubmit(page) {
    /** A challenge whose image button posts normally and re-renders, the way the live page does. */
    const WORKING = `<html><body><form name="authForm" onsubmit="window.__posts=(window.__posts||0)+1;document.body.innerHTML='<h3>Payment Successful</h3>';return false;">
        <input type="password" name="passCode" id="passCode" maxlength="6">
        <input type="image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
    </form></body></html>`;

    /** The 1303 shape: the press is delivered and swallowed, so the form is never posted. */
    const DEAD_BUTTON = `<html><body><form name="authForm" onsubmit="window.__posts=(window.__posts||0)+1;document.body.innerHTML='<h3>Payment Successful</h3>';return false;">
        <input type="password" name="passCode" id="passCode" maxlength="6">
        <input type="image" onclick="return false;" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
    </form></body></html>`;

    /** A rejected code: the bank answers by re-rendering an empty challenge with its reason. */
    const REJECTED = `<html><body><form name="authForm" onsubmit="window.__posts=(window.__posts||0)+1;document.getElementById('passCode').value='';document.getElementById('msg').textContent='Invalid OTP. Please try again.';return false;">
        <input type="password" name="passCode" id="passCode" maxlength="6">
        <input type="image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
        <div id="msg"></div>
    </form></body></html>`;

    const drive = async (html) => {
        await page.setContent(html);
        await page.evaluate(() => { window.__posts = 0; });
        await typeInto(page, ['#passCode'], '123456', { timeout: 5000 });

        let report = null;
        let error = null;
        try {
            // A synthetic form re-renders in place instead of navigating, so every press would
            // otherwise pay the full live navigation timeout.
            report = await submitOtpAndVerify(page, '#passCode', '123456', { navTimeoutMs: 500 });
        } catch (err) {
            error = String((err && err.message) || err);
        }

        return { report, error, posts: await page.evaluate(() => window.__posts) };
    };

    const working = await drive(WORKING);
    const dead = await drive(DEAD_BUTTON);
    const rejected = await drive(REJECTED);

    // A field the code cannot be got into must be caught before anything is sent, not after. The
    // value is made to refuse writes outright — `readonly` would not do it, since that blocks the
    // keyboard but still accepts the scripted assignment the retype falls back to.
    await page.setContent(`<html><body><form name="authForm" onsubmit="window.__posts=(window.__posts||0)+1;return false;">
        <input type="password" name="passCode" id="passCode" maxlength="6">
        <input type="image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
    </form></body></html>`);
    await page.evaluate(() => {
        window.__posts = 0;
        Object.defineProperty(document.getElementById('passCode'), 'value', {
            get: () => '',
            set: () => {},
        });
    });
    let unlandedError = null;
    try {
        await submitOtpAndVerify(page, '#passCode', '123456', { navTimeoutMs: 500 });
    } catch (err) {
        unlandedError = String((err && err.message) || err);
    }

    return {
        working_posts: working.posts,
        working_report: working.report,
        dead_posts: dead.posts,
        dead_report: dead.report,
        dead_error: dead.error,
        rejected_posts: rejected.posts,
        rejected_report: rejected.report,
        // Exactly one post in every case: the working button is not pressed twice, and the dead one
        // still gets the code through.
        posts_exactly_once: working.posts === 1 && dead.posts === 1 && rejected.posts === 1,
        recovered_dead_button: dead.error === null && dead.posts === 1,
        // A refused code is the bank's answer and must never be re-sent.
        rejection_not_resubmitted: rejected.posts === 1 && rejected.error === null,
        unlanded_otp_error: unlandedError,
        unlanded_otp_posts: await page.evaluate(() => window.__posts),
        unlanded_otp_blocked: unlandedError !== null
            && unlandedError.includes('Not submitting')
            && await page.evaluate(() => window.__posts) === 0,
    };
}

/**
 * Offline check that a callback handed to a second tab is still captured.
 *
 * Driven through emitter stand-ins rather than a real popup, because the URL under test is a live
 * IVAC callback: navigating a browser to it would fire a real one. The watcher only ever calls
 * `.on()`, `.url()` and `.type()` on what it is given, so emitters exercise the real code path.
 */
async function selfTestPopupCallback() {
    const callbackUrl = 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback'
        + '?tran_id=8f86b4f7-a329-425f-8de7-48b1bdf593e1&data=selftest';

    const page = new EventEmitter();
    const browser = new EventEmitter();
    const popup = new EventEmitter();
    popup.url = () => 'about:blank';

    const captured = watchForCallback(page, browser);

    browser.emit('targetcreated', {
        type: () => 'page',
        url: () => 'about:blank',
        page: async () => popup,
    });

    // The handle is awaited inside the listener, so the adoption has to land before the popup can
    // be made to fire anything.
    await sleep(100);
    popup.emit('request', { url: () => callbackUrl });

    const seen = await Promise.race([captured, sleep(2000).then(() => null)]);

    return {
        popup_callback_seen: seen,
        popup_callback_captured: seen === callbackUrl,
    };
}

/**
 * Offline check that Chrome launches under this service's HOME and the result framing works,
 * without contacting dgepay or spending anything.
 */
async function selfTest() {
    let browser;
    try {
        browser = await puppeteer.launch({ headless: true, args: CHROME_ARGS });
        const page = await browser.newPage();

        const boxCheck = await selfTestBoxInputs(page);
        const tileCheck = await selfTestTileSelection(page);
        const groupCheck = await selfTestGroupedSelection(page);
        const confirmCheck = await selfTestConfirmPanelAndBankForm(page);
        const logoCheck = await selfTestLogoSelection(page);
        const submitCheck = await selfTestSubmitOutcome(page);
        const otpSubmitCheck = await selfTestOtpSubmit(page);
        const popupCheck = await selfTestPopupCallback();

        const failures = [];
        if (boxCheck.merged !== '01865144147' || !boxCheck.hidden_untouched) {
            failures.push(`box-input fill produced "${boxCheck.merged}"`);
        }
        if (!tileCheck.tile_selected) {
            failures.push(`tile selection failed: ${tileCheck.select_error}`);
        }
        if (!tileCheck.only_nagad_fired) {
            failures.push(`wrong tiles clicked: [${tileCheck.tiles_fired.join(', ')}]`);
        }
        if (!groupCheck.grouped_selected) {
            failures.push(`grouped selection failed: ${groupCheck.grouped_error}`);
        }
        if (!groupCheck.only_rocket_fired) {
            failures.push(`wrong grouped tiles clicked: [${groupCheck.grouped_fired.join(', ')}]`);
        }
        if (!groupCheck.prose_untouched) {
            failures.push('the QR paragraph was clicked as though it were a tile');
        }
        if (confirmCheck.confirm_error !== null) {
            failures.push(`confirm panel / bank form failed: ${confirmCheck.confirm_error}`);
        }
        if (!confirmCheck.handed_over) {
            failures.push('the terms were not accepted and Pay never handed the checkout over');
        }
        if (!confirmCheck.wallet_in_visible_box || !confirmCheck.derived_fields_untouched) {
            failures.push('the wallet did not land in the visible box the bank form reads');
        }
        if (!logoCheck.logo_selected) {
            failures.push(`logo selection failed: ${logoCheck.logo_error}`);
        }
        if (!logoCheck.only_rocket_logo_fired) {
            failures.push(`wrong tile clicked by logo: [${logoCheck.logo_fired.join(', ')}]`);
        }
        if (!confirmCheck.panel_baseline_ignored_decoy) {
            failures.push(`"Click to Pay" was counted as a pressable Pay control (baseline ${JSON.stringify(confirmCheck.pay_baseline)})`);
        }
        if (!confirmCheck.interstitial_skipped) {
            failures.push('the phone-number interstitial was never dismissed');
        }
        if (!confirmCheck.phone_not_submitted) {
            failures.push('the interstitial\'s "Continue" was pressed, submitting an empty phone number');
        }
        if (!confirmCheck.wallet_actually_selected) {
            failures.push('Pay was reached without the Rocket tile ever being taken');
        }
        if (!submitCheck.stuck_named_as_unsubmitted) {
            failures.push(`a 2FA form still on screen was not reported as an unsubmitted click: ${submitCheck.submit_stuck_report}`);
        }
        if (!submitCheck.answered_named_as_bank_reply) {
            failures.push(`a bank rejection was not reported with its own words: ${submitCheck.submit_answered_report}`);
        }
        if (!popupCheck.popup_callback_captured) {
            failures.push(`a callback handed to a second tab was not captured: ${popupCheck.popup_callback_seen}`);
        }
        if (!otpSubmitCheck.posts_exactly_once) {
            failures.push(`the OTP form was not posted exactly once (working=${otpSubmitCheck.working_posts}, dead=${otpSubmitCheck.dead_posts}, rejected=${otpSubmitCheck.rejected_posts})`);
        }
        if (!otpSubmitCheck.recovered_dead_button) {
            failures.push(`a swallowed OTP press was not recovered: ${otpSubmitCheck.dead_error ?? otpSubmitCheck.dead_report}`);
        }
        if (!otpSubmitCheck.rejection_not_resubmitted) {
            failures.push(`a refused OTP was re-sent instead of reported: ${otpSubmitCheck.rejected_report}`);
        }
        if (!otpSubmitCheck.unlanded_otp_blocked) {
            failures.push(`an OTP that never landed in the field was still submitted: ${otpSubmitCheck.unlanded_otp_error}`);
        }

        emit({
            ok: failures.length === 0,
            stage: 'selftest',
            checks: {
                ...boxCheck, ...tileCheck, ...groupCheck, ...confirmCheck, ...logoCheck,
                ...submitCheck, ...otpSubmitCheck, ...popupCheck,
            },
            error: failures.length === 0 ? undefined : failures.join('; '),
        });
    } catch (err) {
        emit({ ok: false, stage: 'selftest', error: String((err && err.message) || err) });
    } finally {
        if (browser) await browser.close().catch(() => {});
    }
}

(async () => {
    if (process.argv.includes('--selftest')) {
        await selfTest();
        return;
    }

    let job;
    try {
        job = JSON.parse(await readStdin());
    } catch (err) {
        emit({ ok: false, stage: 'input', error: 'Malformed job JSON on stdin.' });
        return;
    }

    emit(await run(job));
})();
