// Live IVAC bundle runtime.
//
// Loads the cached IVAC JS bundle headless (with a DOM stub) and exposes every
// versioned encrypt module's `encryptText` function on a private registry, so we
// can run the SITE'S ACTUAL encryption code instead of a hand-ported copy.
//
// This is the single source of truth for "run the live bundle":
//  - the analyzer uses a one-shot of it for ground-truth verification
//  - the persistent sidecar (captcha_encrypt_server.cjs) uses it for production encryption
//
// The IVAC bundle is version-dispatched: each algorithm version ships as a frozen
// module `X = Object.freeze(Object.defineProperty({__proto__:null, decryptText:..,
// encryptText:..}, Symbol.toStringTag, {value:"Module"}))`. We expose every such
// module by its variable name; the caller picks which one (login vs reserve) via the
// version->module mapping resolved by the analyzer.

const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');

const DOM_STUB = require('./captcha_dom_stub.cjs');

// Matches a frozen ESM module namespace object that has an encryptText member.
// Capture group 1 = the module variable name (e.g. "J0", "A0", "$0").
// The start is anchored with a negative lookbehind rather than \b: a \b word
// boundary never matches before a "$"-prefixed name because both "$" and its
// preceding delimiter (",", ";", "=") are non-word chars, so modules like "$0"
// would silently not be exposed.
const MODULE_RE =
    /(?<![\w$])([A-Za-z_$][\w$]*)=Object\.freeze\(Object\.defineProperty\(\{__proto__:null,decryptText:[\w$]+,encryptText:[\w$]+\},Symbol\.toStringTag,\{value:"Module"\}\)\)/g;

/**
 * Validate that an encrypt output is a well-formed transform of the input token:
 * same overall length, the untouched prefix [0, skip) and suffix [skip+encLen, end)
 * preserved, a non-empty transformed window, and that window differing from the input
 * window (so an identity no-op from a wrong/dead module is rejected). When a charset is
 * supplied, every window char whose INPUT char is in the alphabet must map back into the
 * alphabet; a window char whose input is NOT in the alphabet (e.g. the "." Turnstile
 * separator, which sits inside the window whenever skip < 2) must pass through unchanged.
 * Requiring every window char to be in the charset would wrongly reject that valid "."
 * pass-through and break login the moment IVAC lowers its startAt.
 *
 * Returns null when well-formed, or a short reason string describing the problem. This
 * mirrors the analyzer's _wellformed_output canary so the production path refuses to
 * emit a token IVAC would reject rather than returning it silently.
 *
 * @param {string} out encrypt output
 * @param {string} token input token
 * @param {number} skip prefix chars left untouched
 * @param {number} encLen chars transformed
 * @param {string} [charset] optional alphabet the transformed window must use
 * @returns {string|null}
 */
function wellformedReason(out, token, skip, encLen, charset) {
    if (typeof out !== 'string') {
        return 'output is not a string';
    }
    if (out.length !== token.length) {
        return `length changed (${token.length} -> ${out.length})`;
    }
    const s = Number.isInteger(skip) ? skip : 0;
    const e = Number.isInteger(encLen) ? encLen : 0;
    if (out.slice(0, s) !== token.slice(0, s)) {
        return 'prefix altered';
    }
    if (out.slice(s + e) !== token.slice(s + e)) {
        return 'suffix altered';
    }
    const outWindow = out.slice(s, s + e);
    if (outWindow.length === 0) {
        return 'empty transform window';
    }
    if (outWindow === token.slice(s, s + e)) {
        return 'identity no-op (window unchanged)';
    }
    if (typeof charset === 'string' && charset.length > 0) {
        const inWindow = token.slice(s, s + e);
        for (let i = 0; i < outWindow.length; i++) {
            const oc = outWindow[i];
            const ic = inWindow[i];
            if (charset.includes(ic)) {
                // Alphabet input chars are transformed and must land back in the alphabet.
                if (!charset.includes(oc)) {
                    return `window char '${oc}' outside charset`;
                }
            } else if (oc !== ic) {
                // Non-alphabet input chars (e.g. the "." token separator) pass through
                // unchanged; altering one means a wrong/poisoned module.
                return `non-alphabet char '${ic}' altered to '${oc}'`;
            }
        }
    }
    return null;
}

/**
 * Build the patched bundle source: DOM stub + bundle with an exposure statement
 * injected after every encrypt-module freeze, writing each module's encryptText
 * into globalThis.__IVAC_ENC[moduleVar].
 *
 * @param {string} bundleSource raw bundle JS
 * @returns {{source: string, modules: string[]}}
 */
function patchBundle(bundleSource) {
    const modules = [];
    const patched = bundleSource.replace(MODULE_RE, (match, varName) => {
        modules.push(varName);
        return (
            match +
            `;try{globalThis.__IVAC_ENC[${JSON.stringify(varName)}]=${varName}.encryptText;}catch(__e){}`
        );
    });

    // A few extra DOM constructors the stub omits, so the bundle's React render
    // (which runs deferred, after we've captured the encrypt fns) doesn't spin on
    // `x instanceof window.HTMLIFrameElement` and similar.
    const extraGlobals =
        ';(function(){const C=class{};for(const k of ' +
        "['HTMLIFrameElement','HTMLElement','HTMLInputElement','HTMLFormElement'," +
        "'Element','Node','DocumentFragment','ShadowRoot','Text','Comment']" +
        '){if(typeof globalThis[k]===\'undefined\'){globalThis[k]=C;}}})();\n';

    // The bundle is a React app whose entry schedules an initial render via
    // setImmediate; that render then crashes on the stubbed DOM. We only need the
    // (synchronous, pure) encrypt modules, which are defined at eval time regardless.
    // Neuter setImmediate/MessageChannel so the render never runs — this does not
    // affect the encrypt functions or the sidecar's own http server (libuv-based).
    const neuterScheduler =
        ';globalThis.setImmediate=function(){return{ref(){},unref(){},hasRef(){return false;}};};' +
        'globalThis.clearImmediate=function(){};' +
        'globalThis.MessageChannel=function(){this.port1={postMessage(){},close(){},start(){},onmessage:null};' +
        'this.port2={postMessage(){},close(){},start(){},onmessage:null};};\n';

    const header =
        DOM_STUB +
        extraGlobals +
        neuterScheduler +
        '\n;globalThis.__IVAC_ENC = globalThis.__IVAC_ENC || {};\n';

    return { source: header + patched, modules };
}

/**
 * Load a bundle file and evaluate it once, exposing its encrypt modules.
 *
 * @param {string} bundlePath absolute path to the cached bundle
 * @returns {{ modules: string[], bundleHash: string, encrypt: function, hasModule: function }}
 */
function load(bundlePath) {
    const bundleSource = fs.readFileSync(bundlePath, 'utf8');
    const crypto = require('crypto');
    const bundleHash = crypto.createHash('sha256').update(bundleSource).digest('hex');

    const { source, modules } = patchBundle(bundleSource);

    // Wrap in an IIFE so the DOM stub's top-level `const`/`let` declarations are
    // function-scoped. vm.runInThisContext keeps top-level lexical bindings in the
    // realm's global lexical scope, so without this a second load() (reload) throws
    // "Identifier '__mockEl' has already been declared". The exposure statements
    // write to globalThis.__IVAC_ENC, which survives outside the IIFE.
    const wrapped = ';(function(){\n' + source + '\n})();';

    // Reset the registry, then evaluate the patched bundle in this context so the
    // exposure statements populate globalThis.__IVAC_ENC. The patched header neuters
    // setImmediate/MessageChannel so the React entry's deferred render never runs;
    // we restore the real timers afterward so the host process is unaffected.
    const savedSetImmediate = globalThis.setImmediate;
    const savedClearImmediate = globalThis.clearImmediate;
    const savedMessageChannel = globalThis.MessageChannel;
    // Node 22 exposes a read-only global navigator. The shared browser stub uses
    // Object.assign() to install its deterministic navigator, so remove the
    // configurable host property for the duration of bundle evaluation.
    const savedNavigator = globalThis.navigator;
    const hadNavigator = Object.prototype.hasOwnProperty.call(globalThis, 'navigator');
    try { delete globalThis.navigator; } catch (_) {}
    globalThis.__IVAC_ENC = {};
    try {
        vm.runInThisContext(wrapped, { filename: 'ivac-bundle.patched.js' });
    } finally {
        globalThis.setImmediate = savedSetImmediate;
        globalThis.clearImmediate = savedClearImmediate;
        globalThis.MessageChannel = savedMessageChannel;
        if (hadNavigator) {
            try { Object.defineProperty(globalThis, 'navigator', { value: savedNavigator, configurable: true, writable: true }); } catch (_) {}
        }
    }

    const registry = globalThis.__IVAC_ENC || {};
    const exposed = Object.keys(registry);

    /**
     * Encrypt a token by running the live bundle's encryptText for the given module.
     *
     * @param {string} moduleVar module variable name (e.g. "J0")
     * @param {string} token raw Turnstile token
     * @param {string} secret transform secret (seed)
     * @param {number} skip prefix chars to leave untouched
     * @param {number} encLen number of chars to transform
     * @param {string} [charset] optional alphabet the transformed window must use
     * @returns {string}
     */
    function encrypt(moduleVar, token, secret, skip, encLen, charset) {
        const fn = registry[moduleVar];
        if (typeof fn !== 'function') {
            throw new Error(`encrypt module '${moduleVar}' not found (have: ${exposed.join(',')})`);
        }
        const out = fn(token, secret, skip, encLen);
        const reason = wellformedReason(out, token, skip, encLen, charset);
        if (reason) {
            throw new Error(`encrypt produced malformed output: ${reason} (module=${moduleVar})`);
        }
        return out;
    }

    return {
        modules: exposed,
        scannedModules: modules,
        bundleHash,
        encrypt,
        hasModule: (m) => typeof registry[m] === 'function',
    };
}

module.exports = { load, patchBundle, MODULE_RE, wellformedReason };

// One-shot CLI mode (used by the analyzer for ground-truth verification):
//   node captcha_live_runtime.cjs '<json>'
//   json = { bundle_path, jobs: [ {type, module, token, secret, skip, enc_len}, ... ] }
// Prints: { ok, bundle_hash, modules, results: { <type>: <encrypted>|null }, errors: {...} }
if (require.main === module) {
    const out = { ok: false, results: {}, errors: {} };
    try {
        const input = JSON.parse(process.argv[2] || '{}');
        const bundlePath = input.bundle_path || path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'ivac-bundle.js');
        const rt = load(bundlePath);
        out.bundle_hash = rt.bundleHash;
        out.modules = rt.modules;
        for (const job of input.jobs || []) {
            try {
                out.results[job.type] = rt.encrypt(job.module, job.token, job.secret, job.skip, job.enc_len);
            } catch (e) {
                out.results[job.type] = null;
                out.errors[job.type] = String(e && e.message ? e.message : e);
            }
        }
        out.ok = true;
    } catch (e) {
        out.errors.fatal = String(e && e.message ? e.message : e);
    }
    process.stdout.write(JSON.stringify(out));
    // Bundle eval may leave timers/handles; exit explicitly.
    process.exit(0);
}
