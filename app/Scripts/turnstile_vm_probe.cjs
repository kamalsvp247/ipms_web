// Surveys the browser API surface Cloudflare's challenge script actually touches.
//
// Step 4 of the protocol-emulation plan is to run Cloudflare's interpreter as-is in an
// isolated context against a minimal DOM stub — the same pattern captcha_live_runtime.cjs
// uses for IVAC's encrypt bundle — rather than hand-porting an algorithm that goes stale on
// every rotation. Building that stub needs to start from evidence, and static analysis
// gives none: the bootstrap is ~256 KB of obfuscated code that assembles every property
// name at runtime from encoded tables, so grepping it finds six API references in the whole
// file when the real surface is far larger.
//
// So the surface is measured instead. The script runs inside node:vm against a global that
// is a recording Proxy: every property read is logged and answered with another recording
// Proxy, which lets execution continue far past the first unknown instead of dying on it.
// The output is an ordered list of what the challenge wanted, which is the specification
// for the real stub.
//
// This is a probe, not the emulator. It deliberately answers everything, so reaching the
// end here does NOT mean the script produced a usable payload — only that nothing threw.
//
// Usage:
//   node turnstile_vm_probe.cjs [--trace <file>] [--limit-ms 5000] [--top 60]

const fs = require('fs');
const vm = require('node:vm');
const path = require('path');

const TRACE_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_traces');

function parseArgs(argv) {
    const args = { limitMs: 5000, top: 60, trace: null };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--limit-ms') args.limitMs = parseInt(argv[++i], 10);
        else if (argv[i] === '--top') args.top = parseInt(argv[++i], 10);
        else if (argv[i] === '--trace') args.trace = argv[++i];
    }

    return args;
}

/** Newest capture on disk, or the named one. */
function loadTrace(name) {
    const files = fs.readdirSync(TRACE_DIR).filter((f) => f.endsWith('.json')).sort();
    const file = name || files[files.length - 1];

    if (!file) {
        throw new Error(`no traces in ${TRACE_DIR} — capture one first`);
    }

    return { file, trace: JSON.parse(fs.readFileSync(path.join(TRACE_DIR, file), 'utf8')) };
}

/**
 * Pull the challenge bootstrap out of a capture.
 *
 * It is the inline script of the widget's iframe document — the one body the tracer has to
 * recover through a Fetch pause, because Chrome hands that navigation to an out-of-process
 * frame and neither CDP session can return its bytes afterwards.
 */
function extractBootstrap(trace) {
    const iframe = trace.calls.find(
        (c) => c.role === 'challenge' && c.resource_type === 'Document' && c.response_body,
    );

    if (!iframe) {
        throw new Error('capture has no iframe document body — re-run the trace');
    }

    const html = iframe.response_body_base64
        ? Buffer.from(iframe.response_body, 'base64').toString('utf8')
        : iframe.response_body;

    const match = html.match(/<script[^>]*>([\s\S]*)<\/script>/);

    if (!match) {
        throw new Error('no inline script in the iframe document');
    }

    return { url: iframe.url, html, source: match[1] };
}

/**
 * A global whose every read is recorded and answered.
 *
 * Unknown properties return another recorder rather than undefined, so one missing API does
 * not end the run and a single pass surveys the whole surface instead of one name per
 * iteration. Recorders are callable and coercible, because the challenge calls what it
 * looks up and feeds the results into string and numeric contexts.
 */
function createRecorder(reads, missing) {
    const seen = new Set();

    const record = (bucket, key) => {
        if (!seen.has(key)) {
            seen.add(key);
            bucket.push(key);
        }
    };

    const make = (pathName, depth) => {
        const target = function () {};
        target.__path = pathName;

        return new Proxy(target, {
            get(_, prop) {
                if (typeof prop === 'symbol') {
                    // Coercion hooks must answer concretely or the engine throws before the
                    // script can continue.
                    if (prop === Symbol.toPrimitive) return () => 0;
                    if (prop === Symbol.iterator) return function* () {};
                    if (prop === Symbol.toStringTag) return 'Object';

                    return undefined;
                }

                const full = pathName ? `${pathName}.${prop}` : prop;
                record(reads, full);

                if (prop === 'then') return undefined; // never look thenable to await
                if (depth > 6) return undefined;

                return make(full, depth + 1);
            },
            set(_, prop) {
                record(reads, `${pathName ? `${pathName}.` : ''}${String(prop)} =`);

                return true;
            },
            has() {
                return true;
            },
            apply(_, __, argv) {
                record(reads, `${pathName}()`);

                return make(`${pathName}()`, depth + 1);
            },
            construct(_, argv) {
                record(reads, `new ${pathName}`);

                return make(`new ${pathName}`, depth + 1);
            },
        });
    };

    return { make, missing };
}

function main() {
    const args = parseArgs(process.argv.slice(2));
    const { file, trace } = loadTrace(args.trace);
    const bootstrap = extractBootstrap(trace);

    const reads = [];
    const missing = [];
    const { make } = createRecorder(reads, missing);

    // Real implementations for the primitives the script genuinely computes with. Answering
    // these with a recorder would corrupt the very values the challenge derives its payload
    // from, and the run would tell us nothing about the rest of the surface.
    const real = {
        Object, Array, String, Number, Boolean, Math, JSON, Date, RegExp, Error, TypeError,
        Function, Symbol, Promise, Map, Set, WeakMap, WeakSet, Proxy, Reflect, BigInt,
        ArrayBuffer, Uint8Array, Uint16Array, Uint32Array, Int8Array, Int32Array, Float32Array,
        Float64Array, DataView, TextEncoder, TextDecoder, URL, URLSearchParams,
        parseInt, parseFloat, isNaN, isFinite, encodeURIComponent, decodeURIComponent,
        encodeURI, decodeURI, escape, unescape, btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        console: { log() {}, warn() {}, error() {}, debug() {} },
    };

    const context = vm.createContext(
        new Proxy(real, {
            get(target, prop) {
                if (typeof prop === 'symbol') return target[prop];
                if (prop in target) return target[prop];

                const key = String(prop);
                if (!missing.includes(key)) missing.push(key);

                return make(key, 1);
            },
            has() {
                return true;
            },
            set(target, prop, value) {
                target[prop] = value;

                return true;
            },
        }),
    );

    let outcome = 'completed';
    let error = null;
    const startedAt = Date.now();

    try {
        new vm.Script(bootstrap.source, { filename: 'cf-challenge.js' }).runInContext(context, {
            timeout: args.limitMs,
        });
    } catch (e) {
        outcome = e.message.includes('timed out') ? 'timed-out' : 'threw';
        error = { message: e.message, stack: String(e.stack || '').split('\n').slice(0, 4) };
    }

    // Group by first segment: the shape of the surface matters more than the long tail.
    const byRoot = {};
    for (const entry of reads) {
        const root = entry.split(/[.(]/)[0];
        byRoot[root] = (byRoot[root] || 0) + 1;
    }

    process.stdout.write(JSON.stringify({
        trace_file: file,
        bootstrap_url: bootstrap.url,
        bootstrap_bytes: bootstrap.html.length,
        script_bytes: bootstrap.source.length,
        outcome,
        ran_ms: Date.now() - startedAt,
        error,
        globals_requested: missing,
        distinct_reads: reads.length,
        surface_by_root: Object.fromEntries(
            Object.entries(byRoot).sort((a, b) => b[1] - a[1]).slice(0, args.top),
        ),
        sample_reads: reads.slice(0, args.top),
    }, null, 2));
}

main();
