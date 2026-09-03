// Diff the ORDER of environment accesses between the real challenge iframe and the emulator.
//
// The emulator now runs Cloudflare's challenge browserless up to a point: it clears the
// support gate, spawns the worker, builds the widget DOM — and then stalls with
// `TypeError: <register> is not a function` raised inside the bundle's own bytecode
// interpreter. That message is a dead end by construction: the callee was loaded into a
// register by an earlier opcode, so the failing NAME appears nowhere in the error, and the
// object-level miss tracing already ruled out every stub the challenge actually touches.
//
// Static analysis is out (every identifier is machine-generated and the bundle is
// re-obfuscated on each fetch), so the name has to come from a differential: record the
// ordered sequence of window/document/navigator reads in a run that WORKS, record the same
// sequence in the run that does not, and align them. The first divergence is the answer.
//
// The recorder is deliberately symmetric with the emulator's own EMU_TRACE_ENV tracing —
// accessors over own properties, never a Proxy on the global — so the two sequences are
// directly comparable.
//
// It reads back through a plain global array. page.exposeFunction() must NOT be used here:
// Cloudflare detects the binding puppeteer injects and the widget never renders at all.
//
// Usage:
//   node turnstile_access_diff.cjs [--site-key <k>] [--page-url <u>]

const fs = require('fs');
const vm = require('node:vm');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_accessdiff');

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
 * Installed in every frame before any page script.
 *
 * Only own, configurable, non-accessor data properties are wrapped, and the getter hands back
 * the untouched original — so nothing observable changes except that the read is recorded.
 * The intrinsics are skipped: they are the same in both realms and would bury the signal.
 */
const RECORDER = `
(() => {
  window.__ihcReads = [];
  const seen = window.__ihcReads;
  const skip = new Set(['__ihcReads', 'window', 'self', 'globalThis', 'frames', 'top', 'parent',
                        'document', 'location', 'navigator', 'console']);

  const wrap = (target, label) => {
    let names;
    try { names = Object.getOwnPropertyNames(target); } catch (e) { return; }

    for (const key of names) {
      if (label === 'window' && skip.has(key)) continue;

      let d;
      try { d = Object.getOwnPropertyDescriptor(target, key); } catch (e) { continue; }
      if (!d || !d.configurable || d.get || d.set) continue;

      let value = d.value;
      try {
        Object.defineProperty(target, key, {
          configurable: true,
          enumerable: d.enumerable,
          get() { if (seen.length < 120000) seen.push(label + '.' + key); return value; },
          set(v) { value = v; },
        });
      } catch (e) { /* non-writable slots are not ours to move */ }
    }
  };

  wrap(window, 'window');
  wrap(document, 'document');
  wrap(navigator, 'navigator');

  // These four are read constantly and are identical on both sides; recording them would
  // swamp the diff without ever being the divergence.
  ['document', 'location', 'navigator'].forEach((key) => {
    const original = window[key];
    try {
      Object.defineProperty(window, key, {
        configurable: true,
        get() { return original; },
      });
    } catch (e) {}
  });
})();
`;

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

/** Record the access sequence inside the real challenge iframe during a successful solve. */
async function recordReal(siteKey, pageUrl) {
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

        const arm = async (session, isRoot) => {
            if (armed.has(session)) {
                return;
            }

            armed.add(session);

            if (!isRoot) {
                children.push(session);
            }

            session.on('Target.attachedToTarget', (e) => {
                const connection = session.connection();
                const child = connection ? connection.session(e.sessionId) : null;

                if (child) {
                    arm(child, false).catch(() => {});
                }
            });

            await session.send('Target.setAutoAttach', {
                autoAttach: true, waitForDebuggerOnStart: true, flatten: true,
            }).catch(() => {});

            await session.send('Page.enable').catch(() => {});
            // The hold at waitForDebuggerOnStart is what lands this before Cloudflare's script.
            await session.send('Page.addScriptToEvaluateOnNewDocument', { source: RECORDER }).catch(() => {});

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

        await arm(cdp, true);
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});

        // Whether the recorder itself broke the solve is a result, not a nuisance: a sequence
        // recorded from a run that failed would not be the working path we need to diff.
        let solved = false;

        try {
            const handle = await page.waitForFunction(
                () => window.__ihcToken || (window.__ihcError ? { error: window.__ihcError } : false),
                { timeout: 25000, polling: 100 },
            );
            const value = await handle.jsonValue();
            solved = typeof value === 'string' || (value && !value.error);
        } catch (e) {
            solved = false;
        }

        await sleep(500);

        for (const session of children) {
            const where = await session.send('Runtime.evaluate', {
                expression: 'location.href', returnByValue: true,
            }).catch(() => null);

            const href = where && where.result ? String(where.result.value || '') : '';

            if (!href.includes('challenge-platform')) {
                continue;
            }

            const reads = await session.send('Runtime.evaluate', {
                expression: 'JSON.stringify(window.__ihcReads || [])', returnByValue: true,
            }).catch(() => null);

            if (reads && reads.result && reads.result.value) {
                return { solved, url: href, reads: JSON.parse(reads.result.value) };
            }
        }

        return { solved, url: null, reads: [] };
    } finally {
        await browser.close().catch(() => {});
    }
}

/** The same sequence from the emulator, by running it with its own tracing enabled. */
async function recordEmulated(siteKey, pageUrl) {
    const { spawnSync } = require('child_process');

    const run = spawnSync('node', [path.join(__dirname, 'turnstile_emulator.cjs'),
        '--site-key', siteKey, '--page-url', pageUrl], {
        env: { ...process.env, EMU_TRACE_ENV: '1' },
        encoding: 'utf8',
        timeout: 120000,
        maxBuffer: 64 * 1024 * 1024,
    });

    const reads = [];

    for (const line of (run.stderr || '').split('\n')) {
        const match = line.match(/^\[read\] (\S+)$/);

        if (match) {
            reads.push(match[1]);
        }
    }

    let report = null;

    try {
        report = JSON.parse(run.stdout);
    } catch (e) {
        report = null;
    }

    return { reads, report };
}

/**
 * Align the two sequences and report where they part company.
 *
 * Only names present in BOTH vocabularies are compared. The emulator traces things the
 * recorder cannot see in a browser and vice versa, and treating those as divergences would
 * flag the instrumentation rather than the bug.
 */
function firstDivergence(real, emulated) {
    const realVocab = new Set(real);
    const emuVocab = new Set(emulated);
    const shared = (list, other) => list.filter((n) => other.has(n));

    const a = shared(real, emuVocab);
    const b = shared(emulated, realVocab);

    for (let i = 0; i < Math.min(a.length, b.length); i++) {
        if (a[i] !== b[i]) {
            return {
                index: i,
                real: a.slice(Math.max(0, i - 6), i + 6),
                emulated: b.slice(Math.max(0, i - 6), i + 6),
            };
        }
    }

    return {
        index: Math.min(a.length, b.length),
        note: 'no ordering divergence in the shared vocabulary — one run simply stops earlier',
        real_tail: a.slice(-8),
        emulated_tail: b.slice(-8),
    };
}

async function main() {
    const args = parseArgs(process.argv.slice(2));

    const real = await recordReal(args.siteKey, args.pageUrl);
    const emulated = await recordEmulated(args.siteKey, args.pageUrl);

    const realOnly = [...new Set(real.reads)].filter((n) => !emulated.reads.includes(n));
    const emuOnly = [...new Set(emulated.reads)].filter((n) => !real.reads.includes(n));

    const report = {
        measured_at: new Date().toISOString(),
        real: { solved: real.solved, url: real.url, reads: real.reads.length },
        emulated: {
            reads: emulated.reads.length,
            token: emulated.report ? emulated.report.token_found : null,
            messages: emulated.report ? emulated.report.challenge_messages : null,
            errors: emulated.report ? emulated.report.runtime_errors.map((e) => e.message) : null,
        },
        // The interesting list: read in a working run, never read in ours. The challenge
        // stops before reaching them, so the LAST shared read plus the first of these is the
        // frontier.
        read_only_in_real: realOnly.slice(0, 60),
        read_only_in_emulator: emuOnly.slice(0, 30),
        divergence: firstDivergence(real.reads, emulated.reads),
    };

    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(
        path.join(OUT_DIR, `${report.measured_at.replace(/[:.]/g, '-')}.json`),
        JSON.stringify(report, null, 2),
    );

    process.stdout.write(JSON.stringify(report, null, 2));
}

main().catch((e) => {
    process.stderr.write(`fatal: ${e.stack}\n`);
    process.exit(1);
});
