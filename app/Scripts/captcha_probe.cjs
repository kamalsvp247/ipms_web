// Single-boot analyzer probe.
//
// Evaluates the IVAC bundle ONCE and returns, in a single pass, BOTH:
//   1. the resolved captcha secrets + charset (captured from globalThis), and
//   2. the live ground-truth encrypt output for each call site (running the site's
//      real encrypt module on a fixed test token).
//
// This replaces the analyzer's previous TWO separate cold Node executions of the ~2 MB
// bundle (_extract_constants_via_node to resolve secrets, then _compute_live_outputs to
// run the live modules). Each boot parses+executes the whole React app, so collapsing
// them into one roughly halves the analysis "processing" time on a fresh redeploy.
//
// The capture patching (wrapping the charset/config assignments so their values land in
// globalThis) is done in Python and handed here as an already-patched bundle file; this
// script only adds the runtime's module exposure + DOM stub, evaluates, reads the globals
// and runs the encrypt modules. Keeping the patching in Python avoids re-porting its
// brace/paren-aware injectors to JS.
//
// Input (argv[2] = JSON):
//   {
//     patched_bundle_path: string,   // bundle already patched by Python to capture
//                                    //   charset + per-site config/secret vars
//     hash_bundle_path: string,      // ORIGINAL bundle file, hashed for bundle_hash so the
//                                    //   digest matches the sidecar/runtime (utf8 sha256)
//     charset_global: string|null,   // globalThis name holding the captured charset
//     token: string,                 // fixed cross-validation token to encrypt
//     sites: [ { var, global, style: 'config'|'secret',
//                module: string|null, skip: int, enc_len: int } ]
//   }
// Output (stdout JSON):
//   { ok, bundle_hash, charset, modules,
//     sites: { <var>: { secret, output, wellformed_reason } }, errors }

const crypto = require('crypto');
const fs = require('fs');
const vm = require('vm');

const runtime = require('./captcha_live_runtime.cjs');

// The bundle is a full React app; after we capture its (pure) encrypt fns + config
// objects at eval time, any deferred work is irrelevant here. Swallow so a stray
// bundle error cannot mask the JSON we still emit.
process.on('uncaughtException', () => {});
process.on('unhandledRejection', () => {});

function run() {
    const out = { ok: false, bundle_hash: null, charset: null, modules: [], sites: {}, errors: {} };

    let input;
    try {
        input = JSON.parse(process.argv[2] || '{}');
    } catch (e) {
        out.errors.fatal = 'bad input json: ' + String(e && e.message ? e.message : e);
        return out;
    }

    // Hash the ORIGINAL bundle bytes (utf8) exactly as runtime.load()/the sidecar do, so the
    // digest is comparable across the analyzer, the version registry and the live encryptor.
    try {
        out.bundle_hash = crypto
            .createHash('sha256')
            .update(fs.readFileSync(input.hash_bundle_path, 'utf8'))
            .digest('hex');
    } catch (e) {
        out.errors.hash = String(e && e.message ? e.message : e);
    }

    let patchedSource;
    try {
        patchedSource = fs.readFileSync(input.patched_bundle_path, 'utf8');
    } catch (e) {
        out.errors.fatal = 'cannot read patched bundle: ' + String(e && e.message ? e.message : e);
        return out;
    }

    // Reuse the production runtime's patcher: it prepends the shared DOM stub, neuters the
    // React scheduler (so the deferred render never runs -- the single biggest reason one
    // boot is ~1.1s rather than ~1.7s), and exposes every encrypt module into
    // globalThis.__IVAC_ENC. Python's capture injections co-exist untouched.
    const { source } = runtime.patchBundle(patchedSource);

    // Wrap in an IIFE so the DOM stub's top-level const/let are function-scoped (matches
    // runtime.load). Save/restore the scheduler globals the header neuters.
    const wrapped = ';(function(){\n' + source + '\n})();';
    const savedSetImmediate = globalThis.setImmediate;
    const savedClearImmediate = globalThis.clearImmediate;
    const savedMessageChannel = globalThis.MessageChannel;
    // Node 22+ exposes a read-only global navigator. The shared browser stub uses
    // Object.assign() to install its deterministic navigator, so remove the
    // configurable host property for the duration of bundle evaluation.
    const savedNavigator = globalThis.navigator;
    const hadNavigator = Object.prototype.hasOwnProperty.call(globalThis, 'navigator');
    try { delete globalThis.navigator; } catch (_) {}
    globalThis.__IVAC_ENC = {};
    try {
        vm.runInThisContext(wrapped, { filename: 'ivac-bundle.probe.js' });
    } catch (e) {
        out.errors.eval = String(e && e.message ? e.message : e);
    } finally {
        globalThis.setImmediate = savedSetImmediate;
        globalThis.clearImmediate = savedClearImmediate;
        globalThis.MessageChannel = savedMessageChannel;
        if (hadNavigator) {
            try { Object.defineProperty(globalThis, 'navigator', { value: savedNavigator, configurable: true, writable: true }); } catch (_) {}
        }
    }

    const registry = globalThis.__IVAC_ENC || {};
    out.modules = Object.keys(registry);

    if (input.charset_global && typeof globalThis[input.charset_global] === 'string') {
        out.charset = globalThis[input.charset_global];
    }
    const charset = out.charset || null;
    const token = typeof input.token === 'string' ? input.token : '';

    for (const site of input.sites || []) {
        try {
            let secret = null;
            if (site.style === 'config') {
                const cfg = globalThis[site.global];
                if (cfg && typeof cfg === 'object' && typeof cfg.secret === 'string') {
                    secret = cfg.secret;
                }
            } else if (typeof globalThis[site.global] === 'string') {
                secret = globalThis[site.global];
            }

            // Drop sites whose secret did not resolve, exactly as the previous probe did.
            if (secret === null) {
                continue;
            }

            const entry = { secret, output: null, wellformed_reason: null };
            const fn = site.module ? registry[site.module] : null;
            if (typeof fn === 'function') {
                try {
                    const o = fn(token, secret, site.skip, site.enc_len);
                    // Strict well-formedness (same guard the sidecar applies in production):
                    // a malformed/identity output yields output=null, mirroring the old
                    // runtime.encrypt() which threw and left results[type] null.
                    const reason = runtime.wellformedReason(o, token, site.skip, site.enc_len, charset);
                    entry.wellformed_reason = reason;
                    entry.output = reason ? null : o;
                } catch (e) {
                    entry.wellformed_reason = String(e && e.message ? e.message : e);
                }
            }
            out.sites[site.var] = entry;
        } catch (e) {
            out.errors[site.var] = String(e && e.message ? e.message : e);
        }
    }

    out.ok = true;
    return out;
}

const result = run();
// Flush stdout, then exit: the bundle eval may leave timers/handles that would otherwise
// keep the process alive. The callback guarantees the JSON is written before exit.
process.stdout.write(JSON.stringify(result), () => process.exit(0));
