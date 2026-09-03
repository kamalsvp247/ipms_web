// Extracts rotation-prone IVAC request constants from the live bundle by EXECUTING the bundle's
// own obfuscated code (these values are RC4-obfuscated in the bundle, not literals):
//   - paymentConfigId    : the UUID in POST /payment/{id}/dg-epay/initiate
//   - reserveRequestMeta : the x-v-request-meta header value on POST .../reserve-slot
//   - reserveSlotId      : the UUID in POST /slots/{id}/reserve-slot (cross-check; also a literal)
//
// Rotation-robust: every anchor is a STABLE IVAC contract literal ("reserve-slot", "appointmentId",
// "x-token", "baseURL") or a structural JS pattern, never a per-redeploy obfuscated identifier
// (Jh / zQ / PQ etc. change every build). Two strategies run in one bundle eval:
//
//   A. Module-scope API builders (`NAME=ARGS=>WRAP(...,function*(...){...})`, a `const A=..,B=..`
//      comma sequence in v23) are wrapped with a comma-safe self-register, the shared axios instance
//      is stubbed to RECORD (url, body, config), then every builder is invoked. This captures
//      reserve-slot (+ x-v-request-meta) and any payment/slot call that lives at module scope.
//
//   B. In v23 the payment-initiate call moved INSIDE a React-Query mutation in a component that never
//      runs headless, so its URL can't be recorded by (A). Instead we locate the call structurally
//      (`<axios>.post(<var>,{appointmentId:...},{headers:{"x-token":...}})`), read the ternary that
//      builds <var>, export the RC4 decoders its wrapper fns reference, and evaluate the URL concat
//      directly in the live bundle context.
//
// Also extracts the rotating IVAC ENDPOINT PATHS + the sign-in nav-state header so the Java bot
// can pick up a redeploy's path/header rotation from /api/config with no source edit or JAR rebuild:
//   - endpoints.{signin,verifyOtp,uploadFile,bookingConfig,getBookingConfig,reserveSlot,payment,sendOtp?}
//   - endpoints.signinNavState  (x-sec-navigation-state, recorded on the sign-in call)
// Sources: Strategy A's recorded axios URLs (signin/bookingConfig/getBookingConfig/reserveSlot),
// plain literals (verifyOtp/uploadFile), the fixed IVAC template for payment (only its UUID rotates,
// and that is synced separately). sendOtp and x-sec-runtime-state are obfuscated component-local
// concats that are NOT headless-evaluable — they are intentionally omitted so the bot keeps its
// compiled-in default / a portal override. Every path is well-formedness-gated before it is emitted.
//
// Prints one JSON object on stdout: {ok, paymentConfigId, reserveRequestMeta, reserveSlotId, endpoints}.
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const DOM_STUB = require(path.join(__dirname, 'captcha_dom_stub.cjs'));

function emit(obj) { process.stdout.write(JSON.stringify(obj)); process.exit(0); }
function fail(reason) { emit({ ok: false, reason }); }

const bundlePath = process.argv[2];
if (!bundlePath || !fs.existsSync(bundlePath)) { fail('bundle not found: ' + bundlePath); }
const orig = fs.readFileSync(bundlePath, 'utf8');
let src = orig;

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const PAY_RE = new RegExp('payment/(' + UUID + ')/dg-epay/initiate');
const RES_RE = new RegExp('slots/(' + UUID + ')/reserve-slot');

// Minified identifiers legitimately contain `$` (this build's shared axios instance is `$h`),
// and `$` is a regex anchor — interpolating a name raw silently produces a pattern that can
// never match. Every identifier that goes into a RegExp must pass through here.
function reEscape(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// --- string-aware matchers -------------------------------------------------
function matchDelim(s, open, openCh, closeCh) {
    let depth = 0;
    for (let i = open; i < s.length; i++) {
        const c = s[i];
        if (c === openCh) { depth++; }
        else if (c === closeCh) { depth--; if (depth === 0) { return i; } }
        else if (c === '"' || c === "'" || c === '`') { const q = c; i++; while (i < s.length && s[i] !== q) { if (s[i] === '\\') { i++; } i++; } }
    }
    return -1;
}
const matchBrace = (s, i) => matchDelim(s, i, '{', '}');
const matchParen = (s, i) => matchDelim(s, i, '(', ')');

// --- locate the shared axios instance name -----------------------------------
// The factory property is `create`, but whether it is obfuscated is a per-build choice:
// every bundle up to Jul 28 2026 assembled it as a computed member (`xh[Hh(200)+"e"]`),
// while the Jul 30 build emitted it plainly (`xh.create`). Accepting only the computed
// form made this a silent blindness — see FACTORY_ACCESS.
const FACTORY_ACCESS = '(?:\\[[^\\]]*\\]|\\.[A-Za-z_$][\\w$]*)';
function findAxiosName(text) {
    const re = new RegExp('const ([A-Za-z_$][\\w$]*)=[A-Za-z_$][\\w$]*' + FACTORY_ACCESS + '\\(\\{baseURL:');
    const m = re.exec(text);
    return m ? m[1] : null;
}
const axiosName = findAxiosName(orig);
// axiosName may be null on unusual builds. Rather than hard-fail, we skip Strategy A (recorded
// axios calls) and still recover the endpoint paths from their plain-literal fallbacks below.

// ===========================================================================
// PART A injections: wrap every generator builder + stub the axios instance.
// ===========================================================================

// Find NAME=ARGS=>WRAP(...,function*(<params>){BODY}) builders (any WRAP token / arg form).
function findBuilders(text) {
    const startRe = /([A-Za-z_$][\w$]*)=(\([^)]*\)|[A-Za-z_$][\w$]*)=>([A-Za-z_$][\w$]*)\(/g;
    let m;
    const spots = [];
    while ((m = startRe.exec(text)) !== null) {
        const wrapOpen = m.index + m[0].length - 1; // '(' after WRAP
        const fnIdx = text.indexOf('function*', wrapOpen);
        if (fnIdx === -1 || fnIdx - wrapOpen > 40) { continue; }
        const pOpen = text.indexOf('(', fnIdx);
        const pClose = matchParen(text, pOpen);
        if (pClose === -1) { continue; }
        let b = pClose + 1;
        while (b < text.length && text[b] !== '{') { b++; }
        const bodyEnd = matchBrace(text, b);
        if (bodyEnd === -1) { continue; }
        const wrapClose = matchParen(text, wrapOpen);
        if (wrapClose === -1 || wrapClose < bodyEnd) { continue; }
        spots.push({ name: m[1], valStart: m.index + m[1].length + 1, valEnd: wrapClose + 1 });
    }
    // keep top-level only (drop builders nested inside another builder's value)
    spots.sort((a, b) => a.valStart - b.valStart);
    const top = [];
    let lastEnd = -1;
    for (const s of spots) { if (s.valStart < lastEnd) { continue; } top.push(s); lastEnd = s.valEnd; }
    return top;
}

// Comma-safe self-register: NAME=VAL  ->  NAME=globalThis.__cap("NAME",VAL) (returns VAL).
function wrapBuilders() {
    const spots = findBuilders(src).sort((a, b) => b.valStart - a.valStart);
    for (const s of spots) {
        src = src.slice(0, s.valEnd) + ')' + src.slice(s.valEnd);
        src = src.slice(0, s.valStart) + `globalThis.__cap(${JSON.stringify(s.name)},` + src.slice(s.valStart);
    }
    return spots.length;
}

function injectAxiosRecorder(name) {
    const re = new RegExp('const (' + reEscape(name) + ')=[A-Za-z_$][\\w$]*' + FACTORY_ACCESS + '\\(\\{baseURL:');
    const m = re.exec(src);
    if (!m) { return false; }
    const parenOpen = src.indexOf('({baseURL:', m.index);
    const end = matchParen(src, parenOpen);
    if (end === -1) { return false; }
    const rec = `;(function(){globalThis.__CALLS=globalThis.__CALLS||[];['get','post','put','delete','patch'].forEach(function(v){${name}[v]=function(url,a,b){globalThis.__CALLS.push({v:v,url:url,body:a,cfg:b});return Promise.resolve({data:{},status:200,headers:{}});};});})();`;
    src = src.slice(0, end + 1) + rec + src.slice(end + 1);
    return true;
}

// ===========================================================================
// PART B analysis (computed from pristine `orig`): payment URL ternary concat.
// ===========================================================================
function analyzePayment() {
    // Locate the payment call: <axios>.post(<var>,{appointmentId:  OR  <axios>[..](<var>,{appointmentId:
    const ax = reEscape(axiosName);
    let callRe = new RegExp(ax + '\\.(?:post|put)\\(([A-Za-z_$][\\w$]*),\\{appointmentId:');
    let cm = callRe.exec(orig);
    if (!cm) {
        callRe = new RegExp(ax + '\\[[^\\]]*\\]\\(([A-Za-z_$][\\w$]*),\\{appointmentId:');
        cm = callRe.exec(orig);
    }
    if (!cm) { return { ok: false, reason: 'payment call not found' }; }
    const urlVar = cm[1];

    // Enclosing generator body: nearest `function*(` before the call, then its body braces.
    const genIdx = orig.lastIndexOf('function*(', cm.index);
    if (genIdx === -1) { return { ok: false, reason: 'payment generator not found' }; }
    let b = orig.indexOf('{', genIdx);
    const bodyEnd = matchBrace(orig, b);
    if (bodyEnd === -1) { return { ok: false, reason: 'payment body unterminated' }; }
    const body = orig.slice(b + 1, bodyEnd);

    // Ternary that assigns urlVar:  ...,<urlVar>=<cond>?<TRUE>:<FALSE>
    // The assignment appears either inside a comma/statement sequence or, as of this build,
    // as its own `const <urlVar>=` declaration.
    const asnRe = new RegExp('(?:[,;{(]|\\b(?:const|let|var)\\s+)' + reEscape(urlVar) + '=');
    const am = asnRe.exec(body);
    if (!am) { return { ok: false, reason: 'url-var assignment not found' }; }
    // find first '?' at depth 0 after the assignment
    let i = am.index + am[0].length, depth = 0, qIdx = -1;
    for (; i < body.length; i++) {
        const c = body[i];
        if (c === '(' || c === '[' || c === '{') { depth++; }
        else if (c === ')' || c === ']' || c === '}') { depth--; }
        else if (c === '"' || c === "'" || c === '`') { const q = c; i++; while (i < body.length && body[i] !== q) { if (body[i] === '\\') { i++; } i++; } }
        else if (c === '?' && depth === 0) { qIdx = i; break; }
    }
    if (qIdx === -1) { return { ok: false, reason: 'payment ternary not found' }; }
    // TRUE branch: from after '?' to the matching ':' at depth 0
    let j = qIdx + 1; depth = 0;
    let colonIdx = -1;
    for (; j < body.length; j++) {
        const c = body[j];
        if (c === '(' || c === '[' || c === '{') { depth++; }
        else if (c === ')' || c === ']' || c === '}') { depth--; }
        else if (c === '"' || c === "'" || c === '`') { const q = c; j++; while (j < body.length && body[j] !== q) { if (body[j] === '\\') { j++; } j++; } }
        else if (c === ':' && depth === 0) { colonIdx = j; break; }
    }
    if (colonIdx === -1) { return { ok: false, reason: 'payment ternary colon not found' }; }
    const trueExpr = body.slice(qIdx + 1, colonIdx);
    // FALSE branch runs to the end of the assignment statement; used only as a fallback when
    // the true branch turns out to be the non-dg-epay URL.
    let k = colonIdx + 1; depth = 0;
    for (; k < body.length; k++) {
        const c = body[k];
        if (c === '(' || c === '[' || c === '{') { depth++; }
        else if (c === ')' || c === ']' || c === '}') { if (depth === 0) { break; } depth--; }
        else if (c === '"' || c === "'" || c === '`') { const q = c; k++; while (k < body.length && body[k] !== q) { if (body[k] === '\\') { k++; } k++; } }
        else if ((c === ',' || c === ';' || c === '\n') && depth === 0) { break; }
    }
    const falseExpr = body.slice(colonIdx + 1, k);

    // Local wrapper fns in the body: function NAME(..){return ...}
    const wrapperRe = /function ([A-Za-z_$][\w$]*)\([^)]*\)\{return [^{}]*\}/g;
    const wrappers = [];
    const decoderNames = new Set();
    let wm;
    while ((wm = wrapperRe.exec(body)) !== null) {
        wrappers.push(wm[0]);
        // decoder identifiers this wrapper delegates to: `return DECODER(`
        const inner = /return ([A-Za-z_$][\w$]*)\(/.exec(wm[0]);
        if (inner) { decoderNames.add(inner[1]); }
    }
    // String consts referenced by either branch (e.g. const e="jE#Z")
    const branches = trueExpr + ' ' + falseExpr;
    const constRe = /(?:const |,)([A-Za-z_$][\w$]*)="((?:[^"\\]|\\.)*)"/g;
    const consts = [];
    let sm;
    while ((sm = constRe.exec(body)) !== null) {
        if (new RegExp('\\b' + reEscape(sm[1]) + '\\b').test(branches)) {
            consts.push(`const ${sm[1]}=${JSON.stringify(sm[2])};`);
        }
    }

    // Local object-literal consts referenced by the branches. This build no longer concats the
    // URL inline: it parks both candidate URLs in a local map (`const e={hDRjx:<dg-epay concat>,
    // OnnqA:<ssl concat>}`) and the ternary only picks a key. Without the object the branch
    // expression evaluates to undefined, so it has to come along.
    const objRe = /(?:const |,)([A-Za-z_$][\w$]*)=\{/g;
    const objects = [];
    let om;
    while ((om = objRe.exec(body)) !== null) {
        const open = om.index + om[0].length - 1;
        const close = matchBrace(body, open);
        if (close === -1) { continue; }
        if (new RegExp('\\b' + reEscape(om[1]) + '\\b').test(branches)) {
            objects.push({ name: om[1], literal: body.slice(open, close + 1) });
        }
    }
    return { ok: true, urlVar, trueExpr, falseExpr, wrappers, consts, objects, decoderNames: [...decoderNames] };
}

// ===========================================================================
// Endpoint paths + sign-in nav-state header.
// ===========================================================================
function litPath(text, re) {
    const m = text.match(re);
    return m ? m[1] : null;
}
function wellFormedPath(p, anchor) {
    return typeof p === 'string' && p.charAt(0) === '/' && p.includes(anchor);
}

// Build the endpoints object from Strategy A's recorded axios calls (`calls`), the raw bundle
// (`text`) for component-scope literals, and Strategy B's decoded payment URL (`paymentUrl`).
// Anchors are STABLE IVAC contract substrings; each value is gated before it is kept.
function extractEndpoints(calls, text, paymentUrl) {
    const urls = [];
    let navState = null;
    for (const c of (calls || [])) {
        const url = typeof c.url === 'string' ? c.url : '';
        if (!url) { continue; }
        urls.push(url);
        if (/^\/auth\/[a-z0-9-]*sign-in[a-z0-9-]*$/i.test(url)) {
            const h = (c.cfg && c.cfg.headers) || {};
            for (const k of Object.keys(h)) {
                if (k.toLowerCase() === 'x-sec-navigation-state') { navState = h[k]; }
            }
        }
    }
    const pick = (pred) => urls.find(pred) || null;
    const ep = {};

    // Strategy A recorded URLs (tied to real API calls).
    ep.signin = pick((u) => /^\/auth\/[a-z0-9-]*sign-in[a-z0-9-]*$/i.test(u));
    ep.bookingConfig = pick((u) => u.includes('/appointment/appointment-booking-config'));
    ep.getBookingConfig = pick((u) => u.includes('/appointment/get-booking-config'));
    const reserveUrl = pick((u) => new RegExp('/slots/' + UUID + '/reserve-slot').test(u));
    if (reserveUrl) {
        ep.reserveSlot = reserveUrl.replace(new RegExp('slots/' + UUID + '/reserve-slot'), 'slots/{reserveSlotId}/reserve-slot');
    }

    // Component-scope plain literals (Strategy A does not run these components headless).
    if (!ep.signin) { ep.signin = litPath(text, /["'](\/auth\/[a-z0-9-]*sign-in[a-z0-9-]*)["']/i); }
    ep.verifyOtp = litPath(text, /["'](\/otp\/verifySigninOtp)["']/);
    ep.uploadFile = litPath(text, /["'](\/file\/upload_file[a-z0-9_]*)["']/i);
    ep.sendOtp = litPath(text, /["'](\/forgot-password\/sendOtp)["']/); // obfuscated concat -> usually null -> bot default

    // Literal fallbacks for the Strategy-A URLs so extraction survives an axios-record miss
    // (all three are plain literals in every observed bundle).
    if (!ep.bookingConfig) { ep.bookingConfig = litPath(text, /["'](\/appointment\/appointment-booking-config)["']/); }
    if (!ep.getBookingConfig) { ep.getBookingConfig = litPath(text, /["'](\/appointment\/get-booking-config)["']/); }
    if (!ep.reserveSlot) {
        const rm = text.match(new RegExp('/slots/' + UUID + '/reserve-slot'));
        if (rm) { ep.reserveSlot = rm[0].replace(new RegExp('slots/' + UUID + '/reserve-slot'), 'slots/{reserveSlotId}/reserve-slot'); }
    }

    // Payment: re-template Strategy B's decoded URL, else the fixed IVAC template (suffix is stable).
    if (typeof paymentUrl === 'string' && PAY_RE.test(paymentUrl)) {
        const p = paymentUrl.replace(new RegExp('payment/' + UUID + '/dg-epay/initiate'), 'payment/{paymentConfigId}/dg-epay/initiate');
        ep.payment = p.charAt(0) === '/' ? p : '/' + p;
    } else {
        ep.payment = '/payment/{paymentConfigId}/dg-epay/initiate';
    }

    // Well-formedness gate: keep only values that start "/" and carry their anchor.
    const anchors = {
        signin: 'sign-in', sendOtp: 'sendOtp', verifyOtp: 'verifySigninOtp', uploadFile: 'upload_file',
        bookingConfig: 'appointment-booking-config', getBookingConfig: 'get-booking-config',
        reserveSlot: 'reserve-slot', payment: 'dg-epay/initiate',
    };
    const out = {};
    for (const key of Object.keys(anchors)) {
        if (wellFormedPath(ep[key], anchors[key])) { out[key] = ep[key]; }
    }
    if (typeof navState === 'string' && navState) { out.signinNavState = navState; }
    return out;
}

const pay = axiosName ? analyzePayment() : { ok: false, reason: 'no axios instance' };

// Export the decoders the payment wrappers reference, by injecting a top-of-body capture
// into each `function NAME(e,t){...}` (RC4 decoders run at module eval, so this fires).
function exportDecoders(names) {
    for (const name of names) {
        const re = new RegExp('function ' + reEscape(name) + '\\([^)]*\\)\\{');
        const m = re.exec(src);
        if (!m) { continue; }
        const braceIdx = m.index + m[0].length; // just after '{'
        src = src.slice(0, braceIdx) + `try{globalThis.${name}=${name};}catch(_){}` + src.slice(braceIdx);
    }
}

// --- apply injections (order: builders, then axios, then decoders) ----------
if (axiosName) { wrapBuilders(); injectAxiosRecorder(axiosName); }
if (pay.ok) { exportDecoders(pay.decoderNames); }

// --- run the instrumented bundle -------------------------------------------
const caps = {};
const noop = () => 0;
const ctx = vm.createContext({
    console: { log() {}, warn() {}, error() {} },
    setTimeout: noop, clearTimeout: noop, setInterval: noop, clearInterval: noop, queueMicrotask: (fn) => { try { fn(); } catch (e) {} },
    Event: class { constructor(t) { this.type = t; } },
    EventTarget: class { addEventListener() {} removeEventListener() {} dispatchEvent() { return true; } },
    Promise, URL, URLSearchParams, TextEncoder, TextDecoder,
    __cap: (name, fn) => { caps[name] = fn; return fn; },
});
ctx.globalThis = ctx;
try { vm.runInContext(DOM_STUB, ctx); } catch (e) {}
try { vm.runInContext(src, ctx, { timeout: 20000 }); } catch (e) { /* partial eval still yields decoders/builders */ }

(async () => {
    // axiosName is reported because it is the precondition for Strategy A: when it fails to
    // resolve, the extractor still exits ok and only the recorder-sourced values go missing.
    const out = { ok: true, axiosName, paymentConfigId: null, reserveRequestMeta: null, reserveSlotId: null };
    let paymentUrl = null;

    // PART A: invoke every module-scope builder, scan recorded axios calls.
    // Payment builders gate their URL on the payment method being "dg-epay" (positional first arg
    // in module-scope builders, or a destructured `paymentMethod` key). Any other value picks the
    // fallback `payment/ssl/initiate` (no UUID). Pass "dg-epay" in every shape to hit the real URL.
    const argSets = [[], ['A'], ['A', 'B'], ['A', 'B', 'C'],
        ['dg-epay'], ['dg-epay', 'APPT'], ['dg-epay', 'APPT', 'TOK'],
        [{ c: 'C', appointmentDate: 'D', appointmentId: 'APPT', phone: 'P', otp: 'O', requestId: 'R', code: 'X', turnstileToken: 'T', paymentMethod: 'dg-epay' }]];
    for (const fn of Object.values(caps)) {
        if (typeof fn !== 'function') { continue; }
        for (const args of argSets) { try { await fn.apply(null, args); } catch (e) {} }
    }
    for (let k = 0; k < 8; k++) { await Promise.resolve(); }
    for (const c of (ctx.__CALLS || [])) {
        const url = typeof c.url === 'string' ? c.url : '';
        let mm = url.match(PAY_RE); if (mm) { out.paymentConfigId = mm[1]; paymentUrl = url; }
        mm = url.match(RES_RE); if (mm) {
            out.reserveSlotId = mm[1];
            const h = (c.cfg && c.cfg.headers) || {};
            for (const k of Object.keys(h)) { if (k.toLowerCase() === 'x-v-request-meta') { out.reserveRequestMeta = h[k]; } }
        }
    }

    // PART B: decode the component-embedded payment URL if PART A didn't record it.
    if (!out.paymentConfigId && pay.ok) {
        // Preamble shared by every candidate: local wrapper fns (hoisted), string consts, and
        // the local URL map. Module-scope RC4 decoders were exported onto globalThis above.
        const preamble = pay.wrappers.join('')
            + pay.consts.join('')
            + (pay.objects || []).map((o) => `const ${o.name}=${o.literal};`).join('');
        const evaluate = (expr) => {
            try {
                return vm.runInContext(`(function(){${preamble}return (${expr});})()`, ctx, { timeout: 5000 });
            } catch (e) { return null; }
        };

        // Which ternary branch carries dg-epay depends on how the condition is written, so try
        // both and keep whichever actually yields the payment URL rather than assuming.
        for (const expr of [pay.trueExpr, pay.falseExpr]) {
            if (!expr) { continue; }
            const url = evaluate(expr);
            if (typeof url !== 'string') { continue; }
            const mm = url.match(PAY_RE);
            if (mm) { out.paymentConfigId = mm[1]; paymentUrl = url; break; }
            if (!paymentUrl && url.includes('/payment/')) { paymentUrl = url; }
        }

        // Last resort: evaluate the URL map itself and scan every string value. Survives a
        // ternary shape we failed to parse, as long as the candidates live in that object.
        if (!out.paymentConfigId) {
            for (const o of (pay.objects || [])) {
                const obj = evaluate(o.name);
                if (!obj || typeof obj !== 'object') { continue; }
                for (const v of Object.values(obj)) {
                    if (typeof v !== 'string') { continue; }
                    const mm = v.match(PAY_RE);
                    if (mm) { out.paymentConfigId = mm[1]; paymentUrl = v; break; }
                }
                if (out.paymentConfigId) { break; }
            }
        }
    }

    // reserveSlotId also appears as a plain literal — backfill as a cross-check.
    if (!out.reserveSlotId) {
        const mm = orig.match(RES_RE);
        if (mm) { out.reserveSlotId = mm[1]; }
    }

    // Endpoint paths + sign-in nav-state header (independent of the 3 request constants).
    out.endpoints = extractEndpoints(ctx.__CALLS, orig, paymentUrl);

    const haveEndpoints = out.endpoints && Object.keys(out.endpoints).length > 0;
    if (!out.paymentConfigId && !out.reserveRequestMeta && !out.reserveSlotId && !haveEndpoints) {
        return fail('no request constants recorded' + (pay.ok ? '' : ' (' + pay.reason + ')'));
    }
    emit(out);
})();
