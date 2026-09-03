// Diffs the emulator's DOM stub against the environment Cloudflare's challenge iframe
// actually sees.
//
// The emulator (turnstile_emulator.cjs) completes the postMessage handshake, gets `init` and
// `requestExtraParams` back from the challenge, delivers `execute` — and then the challenge
// emits `overrunBegin` and stalls, with no JS errors and no stub misses. A silent watchdog
// means something in the environment is wrong rather than absent, which no amount of
// "add the API it crashed on" can find.
//
// So the same probe is evaluated in both places and the answers compared. Running it inside
// the REAL widget iframe is the part that matters: it is a cross-origin out-of-process frame,
// so the probe has to be evaluated on that target's own CDP session.
//
// Read-only. Nothing here mutates state or contacts IVAC.
//
// Usage:
//   node turnstile_env_diff.cjs [--site-key <k>] [--page-url <u>]

const fs = require('fs');
const vm = require('node:vm');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_envdiff');

if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');
const solver = require('./in_house_captcha_solver.cjs');
const emulator = require('./turnstile_emulator.cjs');

const CHROME_ARGS = [
    '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
    '--disable-blink-features=AutomationControlled', '--no-first-run', '--no-default-browser-check',
    '--window-size=1280,800', `--user-agent=${emulator.USER_AGENT}`,
];

/**
 * The probe. Every entry is a signal the challenge could plausibly collect, written to be
 * safe in both realms — each is wrapped so one failure cannot take the whole probe down, and
 * results are primitives so they compare cleanly.
 */
const PROBE = `(() => {
  const attempt = (fn) => { try { const v = fn(); return v === undefined ? 'undefined' : v; } catch (e) { return 'THREW: ' + (e && e.message); } };
  const canvas = () => document.createElement('canvas');
  return {
    'webgl.context':        attempt(() => !!canvas().getContext('webgl')),
    'webgl2.context':       attempt(() => !!canvas().getContext('webgl2')),
    'webgl.vendor':         attempt(() => { const c = canvas().getContext('webgl'); return c ? String(c.getParameter(c.VENDOR)) : null; }),
    'webgl.renderer':       attempt(() => { const c = canvas().getContext('webgl'); return c ? String(c.getParameter(c.RENDERER)) : null; }),
    'webgl.unmaskedRenderer': attempt(() => { const c = canvas().getContext('webgl'); if (!c) return null; const d = c.getExtension('WEBGL_debug_renderer_info'); return d ? String(c.getParameter(d.UNMASKED_RENDERER_WEBGL)) : 'no-ext'; }),
    'canvas.2d':            attempt(() => !!canvas().getContext('2d')),
    'canvas.toDataURL':     attempt(() => canvas().toDataURL().slice(0, 30)),
    'audio.context':        attempt(() => typeof (window.AudioContext || window.webkitAudioContext)),
    'audio.offline':        attempt(() => typeof window.OfflineAudioContext),
    'nav.userAgent':        attempt(() => navigator.userAgent),
    'nav.platform':         attempt(() => navigator.platform),
    'nav.language':         attempt(() => navigator.language),
    'nav.hardwareConcurrency': attempt(() => navigator.hardwareConcurrency),
    'nav.deviceMemory':     attempt(() => navigator.deviceMemory),
    'nav.maxTouchPoints':   attempt(() => navigator.maxTouchPoints),
    'nav.webdriver':        attempt(() => navigator.webdriver),
    'nav.plugins.length':   attempt(() => navigator.plugins.length),
    'nav.pdfViewerEnabled': attempt(() => navigator.pdfViewerEnabled),
    'nav.productSub':       attempt(() => navigator.productSub),
    'nav.vendor':           attempt(() => navigator.vendor),
    'nav.cookieEnabled':    attempt(() => navigator.cookieEnabled),
    'nav.storage':          attempt(() => typeof navigator.storage),
    'nav.connection':       attempt(() => typeof navigator.connection),
    'nav.mediaDevices':     attempt(() => typeof navigator.mediaDevices),
    'nav.serviceWorker':    attempt(() => typeof navigator.serviceWorker),
    'nav.getBattery':       attempt(() => typeof navigator.getBattery),
    'nav.userAgentData':    attempt(() => typeof navigator.userAgentData),
    'screen.wxh':           attempt(() => screen.width + 'x' + screen.height),
    'screen.colorDepth':    attempt(() => screen.colorDepth),
    'window.innerSize':     attempt(() => window.innerWidth + 'x' + window.innerHeight),
    'window.devicePixelRatio': attempt(() => window.devicePixelRatio),
    'intl.timeZone':        attempt(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
    'date.offset':          attempt(() => new Date().getTimezoneOffset()),
    'perf.now.type':        attempt(() => typeof performance.now()),
    'perf.timeOrigin':      attempt(() => typeof performance.timeOrigin),
    'perf.memory':          attempt(() => typeof performance.memory),
    'perf.getEntries':      attempt(() => typeof performance.getEntriesByType),
    'crypto.subtle':        attempt(() => typeof crypto.subtle),
    'crypto.randomUUID':    attempt(() => typeof crypto.randomUUID),
    'doc.fonts':            attempt(() => typeof document.fonts),
    'doc.fonts.check':      attempt(() => document.fonts.check('12px Arial')),
    'doc.readyState':       attempt(() => document.readyState),
    'doc.visibilityState':  attempt(() => document.visibilityState),
    'doc.referrer':         attempt(() => document.referrer),
    'loc.href':             attempt(() => location.href.slice(0, 60)),
    'loc.ancestorOrigins':  attempt(() => location.ancestorOrigins.length),
    'window.Worker':        attempt(() => typeof Worker),
    'window.Blob':          attempt(() => typeof Blob),
    'window.URL.createObjectURL': attempt(() => typeof URL.createObjectURL),
    'window.WebAssembly':   attempt(() => typeof WebAssembly),
    'window.indexedDB':     attempt(() => typeof indexedDB),
    'window.localStorage':  attempt(() => typeof localStorage),
    'window.RTCPeerConnection': attempt(() => typeof RTCPeerConnection),
    'window.chrome':        attempt(() => typeof window.chrome),
    'window.matchMedia':    attempt(() => typeof matchMedia),
    'window.speechSynthesis': attempt(() => typeof speechSynthesis),
    'window.requestIdleCallback': attempt(() => typeof requestIdleCallback),
    'window.ontouchstart':  attempt(() => 'ontouchstart' in window),
    'window.isSecureContext': attempt(() => isSecureContext),
    'window.MutationObserver': attempt(() => typeof MutationObserver),
    'window.IntersectionObserver': attempt(() => typeof IntersectionObserver),
    'window.PerformanceObserver': attempt(() => typeof PerformanceObserver),
    'window.ReportingObserver': attempt(() => typeof ReportingObserver),
    'window.TextEncoder':   attempt(() => typeof TextEncoder),
    'window.structuredClone': attempt(() => typeof structuredClone),
    'window.queueMicrotask': attempt(() => typeof queueMicrotask),
    'window.Notification':  attempt(() => typeof Notification),
    'window.PressureObserver': attempt(() => typeof PressureObserver),
    'error.stackShape':     attempt(() => { try { null.x; } catch (e) { return String(e.stack || '').split('\\n')[0]; } return 'none'; }),
    'func.toString':        attempt(() => Function.prototype.toString.call(function f() {}).slice(0, 24)),
    'nativeCheck':          attempt(() => Function.prototype.toString.call(document.createElement).includes('[native code]')),
  };
})()`;

/**
 * The names on `window`, so a missing constructor shows up as a name rather than as a crash.
 *
 * The signal probe above only answers questions someone thought to ask. The challenge reads
 * `window.<Something>.prototype.<member>` directly, and when `<Something>` is absent the run
 * dies on a TypeError that names nothing useful — enumerating both sides finds those without
 * having to guess which constructor it wanted.
 */
const GLOBALS_PROBE = `(() => {
  const names = [];
  for (const key of Object.getOwnPropertyNames(window)) {
    try { if (typeof window[key] === 'function') names.push(key); } catch (e) { /* cross-origin */ }
  }
  return names.sort();
})()`;

function isWritable(dir) {
    try {
        fs.accessSync(dir, fs.constants.W_OK);

        return true;
    } catch (e) {
        return false;
    }
}

function parseArgs(argv) {
    const args = { siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: 'https://appointment.ivacbd.com/' };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
    }

    return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Evaluate the probe inside the real widget iframe, which is its own CDP target. */
async function probeRealIframe(siteKey, pageUrl) {
    const target = new URL(pageUrl).href;
    const browser = await puppeteer.launch({ headless: true, args: CHROME_ARGS, defaultViewport: null });

    try {
        const context = await browser.createBrowserContext();
        const page = await context.newPage();
        const html = Buffer.from(solver.buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();
        const children = [];
        const armed = new Set();

        const arm = async (session, info, isRoot) => {
            if (armed.has(session)) {
                return;
            }

            armed.add(session);

            if (!isRoot) {
                children.push({ session, info });
            }

            session.on('Target.attachedToTarget', (e) => {
                const connection = session.connection();
                const child = connection ? connection.session(e.sessionId) : null;

                if (child) {
                    arm(child, e.targetInfo, false).catch(() => {});
                }
            });

            await session.send('Target.setAutoAttach', {
                autoAttach: true, waitForDebuggerOnStart: true, flatten: true,
            }).catch(() => {});

            if (isRoot) {
                await session.send('Fetch.enable', {
                    patterns: [{ urlPattern: solver.cdpEscapeUrl(target), requestStage: 'Request', resourceType: 'Document' }],
                }).catch(() => {});

                session.on('Fetch.requestPaused', ({ requestId }) => {
                    session.send('Fetch.fulfillRequest', {
                        requestId,
                        responseCode: 200,
                        responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                        body: html,
                    }).catch(() => {});
                });
            }

            await session.send('Runtime.runIfWaitingForDebugger').catch(() => {});
        };

        await arm(cdp, null, true);
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
        // Let the widget settle so its iframe target exists and has a live realm.
        await sleep(4000);

        // Filtering on the URL from attachedToTarget does not work: a target is attached
        // before it navigates, so its recorded url is still about:blank. Ask each live realm
        // where it is instead, and take the one that is actually the challenge frame.
        const seen = [];

        for (const { session } of children) {
            const where = await session.send('Runtime.evaluate', {
                expression: 'location.href',
                returnByValue: true,
            }).catch(() => null);

            const href = where && where.result ? String(where.result.value || '') : '';
            seen.push(href || '(no realm)');

            if (!href.includes('challenge-platform')) {
                continue;
            }

            const result = await session.send('Runtime.evaluate', {
                expression: PROBE,
                returnByValue: true,
                awaitPromise: false,
            }).catch(() => null);

            const globals = await session.send('Runtime.evaluate', {
                expression: GLOBALS_PROBE,
                returnByValue: true,
                awaitPromise: false,
            }).catch(() => null);

            if (result && result.result && result.result.value) {
                return {
                    url: href,
                    values: result.result.value,
                    globals: (globals && globals.result && globals.result.value) || [],
                };
            }
        }

        process.stderr.write(`[env-diff] no challenge frame among ${children.length} target(s): ${JSON.stringify(seen)}\n`);

        return null;
    } finally {
        await browser.close().catch(() => {});
    }
}

/** Evaluate the same probe against the emulator's stub. */
async function probeStub(siteKey, pageUrl) {
    const bootstrap = await emulator.fetchBootstrap(siteKey, pageUrl);
    const outbound = [];
    outbound.messages = [];

    const { global, document } = emulator.buildEnvironment(bootstrap, pageUrl, outbound, [], false);
    const context = vm.createContext(global);

    emulator.installNativeToString(context);
    // The challenge only runs once the document is ready, so probing it as 'loading' compares
    // the stub against a state the real iframe was never in when it was measured.
    document.readyState = 'complete';

    return {
        values: vm.runInContext(PROBE, context, { timeout: 5000 }),
        globals: vm.runInContext(GLOBALS_PROBE, context, { timeout: 5000 }),
    };
}

async function main() {
    const args = parseArgs(process.argv.slice(2));

    const [real, stub] = await Promise.all([
        probeRealIframe(args.siteKey, args.pageUrl),
        probeStub(args.siteKey, args.pageUrl),
    ]);

    if (!real) {
        throw new Error('could not evaluate the probe inside the challenge iframe');
    }

    const keys = [...new Set([...Object.keys(real.values), ...Object.keys(stub.values)])].sort();
    const differences = [];
    const matches = [];

    for (const key of keys) {
        const a = JSON.stringify(real.values[key]);
        const b = JSON.stringify(stub.values[key]);

        (a === b ? matches : differences).push({ key, real: real.values[key], stub: stub.values[key] });
    }

    const stubGlobals = new Set(stub.globals);
    const missingGlobals = real.globals.filter((name) => !stubGlobals.has(name));

    const report = {
        captured_at: new Date().toISOString(),
        iframe_url: real.url,
        signals: keys.length,
        matching: matches.length,
        differing: differences.length,
        differences,
        callable_globals: { real: real.globals.length, stub: stub.globals.length },
        missing_globals: missingGlobals,
    };

    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(
        path.join(OUT_DIR, `${report.captured_at.replace(/[:.]/g, '-')}.json`),
        JSON.stringify(report, null, 2),
    );

    process.stdout.write(JSON.stringify(report, null, 2));
}

main().catch((e) => {
    process.stderr.write(`fatal: ${e.stack}\n`);
    process.exit(1);
});
