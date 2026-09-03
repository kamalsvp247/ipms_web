// Captures the DOM interface surface of the real Turnstile challenge iframe.
//
// The emulator's stub is hand-written, so it has the handful of APIs the challenge was seen to
// touch and none of the ~885 interface constructors a browser also exposes. That is not a
// cosmetic gap: the challenge reads `window.<Interface>.prototype.<member>` as part of its
// support gate, and an absent constructor is a TypeError inside its own try/catch — the run
// dies with no error surfaced and no indication of which name it wanted.
//
// Guessing the name from an obfuscated bundle is not viable, so the surface is measured
// instead, the same way the flow constants and the fingerprint bisect were. The output feeds
// turnstile_emulator.cjs, which materialises a constructor per interface with the same
// prototype member names.
//
// Only NAMES are captured — never values, and nothing is executed from the page. The result is
// a shape, not behaviour.
//
// Usage:
//   node turnstile_dom_capture.cjs [--site-key <k>] [--page-url <u>] [--out <path>]

const fs = require('fs');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const DEFAULT_OUT = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_dom_surface.json');

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
 * Read every constructor on `window` and record the shape of its prototype and statics.
 *
 * Each member is recorded with its KIND, not just its name. The three behave differently and
 * the emulator has to reproduce the difference: a getter materialised as a method changes what
 * `typeof` answers, and a numeric constant like Node.ELEMENT_NODE materialised as a function
 * changes its value. Primitive values are carried across; object values are not, since those
 * are live browser objects rather than a shape.
 */
const SURFACE_PROBE = `(() => {
  const surface = {};

  const describe = (owner, key) => {
    let descriptor;

    try { descriptor = Object.getOwnPropertyDescriptor(owner, key); } catch (e) { return null; }
    if (!descriptor) return null;
    if (descriptor.get || descriptor.set) return { kind: 'accessor' };
    if (typeof descriptor.value === 'function') return { kind: 'method' };

    const value = descriptor.value;
    const type = typeof value;

    if (value === null || type === 'number' || type === 'string' || type === 'boolean') {
      return { kind: 'value', value };
    }

    return { kind: 'object' };
  };

  for (const name of Object.getOwnPropertyNames(window)) {
    let value;

    try { value = window[name]; } catch (e) { continue; }

    if (typeof value !== 'function' || !/^[A-Z]/.test(name)) {
      continue;
    }

    const proto = {};
    const statics = {};

    if (value.prototype && typeof value.prototype === 'object') {
      for (const key of Object.getOwnPropertyNames(value.prototype)) {
        if (key === 'constructor') continue;

        const member = describe(value.prototype, key);

        if (member) proto[key] = member;
      }
    }

    for (const key of Object.getOwnPropertyNames(value)) {
      if (['length', 'name', 'prototype'].includes(key)) continue;

      const member = describe(value, key);

      if (member) statics[key] = member;
    }

    // Whether the interface can be constructed at all. Browsers make most DOM interfaces
    // non-constructible ("Illegal constructor") while CustomEvent, Headers, FormData and
    // friends are perfectly constructible — reproducing that backwards breaks the bootstrap
    // either way, so it is measured rather than assumed. A throw about missing arguments
    // still means constructible.
    let constructible = true;

    try { new value(); } catch (e) { constructible = !/Illegal constructor/i.test(String(e && e.message)); }

    surface[name] = { proto, statics, constructible };
  }

  // Every own name on window, one level deep. The interface table above only covers
  // constructors; the challenge also reaches straight through plain objects like
  // visualViewport and cookieStore, and a missing one of those is the same invisible
  // TypeError. The null-valued on* handlers matter too — 'onmessage' in window is a routine
  // feature test that a stub without them answers wrongly.
  const globals = {};

  for (const name of Object.getOwnPropertyNames(window)) {
    let value;

    try { value = window[name]; } catch (e) { globals[name] = { kind: 'blocked' }; continue; }

    if (value === null) { globals[name] = { kind: 'value', value: null }; continue; }

    const type = typeof value;

    if (type === 'number' || type === 'string' || type === 'boolean') {
      globals[name] = { kind: 'value', value }; continue;
    }

    if (type === 'function') { globals[name] = { kind: 'method' }; continue; }
    if (type !== 'object') { globals[name] = { kind: 'object', members: {} }; continue; }

    const members = {};

    // Own names plus one prototype level: browsers put visualViewport.width and
    // scheduler.postTask on the prototype, so own names alone find almost nothing.
    const owners = [value];
    const proto = Object.getPrototypeOf(value);

    if (proto && proto !== Object.prototype) owners.push(proto);

    for (const owner of owners) {
      for (const key of Object.getOwnPropertyNames(owner)) {
        if (key === 'constructor' || members[key]) continue;

        const member = describe(owner, key);

        if (member) members[key] = member;
      }
    }

    globals[name] = { kind: 'object', members };
  }

  return { surface, globals };
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
    const args = {
        siteKey: '0x4AAAAAACghKkJHL1t7UkuZ',
        pageUrl: 'https://appointment.ivacbd.com/',
        out: DEFAULT_OUT,
    };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
        else if (argv[i] === '--out') args.out = argv[++i];
    }

    return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Evaluate the probe inside the challenge iframe.
 *
 * The iframe is cross-origin and out-of-process, so it is its own CDP target. Targets are
 * filtered by asking each live realm for its own location: the url on Target.attachedToTarget
 * is still about:blank at attach time.
 */
async function captureSurface(siteKey, pageUrl) {
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
        await sleep(4000);

        // Baseline: the same probe on the PARENT page, which is an ordinary window in the same
        // Chrome that never ran a challenge. Anything the iframe has and this does not was put
        // there by Cloudflare at runtime, and must never be pre-created for the emulator — a
        // pre-existing inert stub is worse than an absent name, because the challenge finds it
        // and calls it instead of building its own.
        const baseline = await cdp.send('Runtime.evaluate', {
            expression: 'Object.getOwnPropertyNames(window)', returnByValue: true,
        }).catch(() => null);

        const baselineNames = new Set(
            (baseline && baseline.result && baseline.result.value) || [],
        );

        for (const session of children) {
            const where = await session.send('Runtime.evaluate', {
                expression: 'location.href', returnByValue: true,
            }).catch(() => null);

            const href = where && where.result ? String(where.result.value || '') : '';

            if (!href.includes('challenge-platform')) {
                continue;
            }

            const result = await session.send('Runtime.evaluate', {
                expression: SURFACE_PROBE, returnByValue: true,
            }).catch(() => null);

            if (result && result.result && result.result.value) {
                const captured = result.result.value;
                const challengeOwned = Object.keys(captured.globals)
                    .filter((name) => baselineNames.size > 0 && !baselineNames.has(name))
                    .sort();

                for (const name of challengeOwned) {
                    delete captured.globals[name];
                    delete captured.surface[name];
                }

                return { url: href, ...captured, challenge_owned: challengeOwned };
            }
        }

        return null;
    } finally {
        await browser.close().catch(() => {});
    }
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const captured = await captureSurface(args.siteKey, args.pageUrl);

    if (!captured) {
        throw new Error('could not evaluate the probe inside the challenge iframe');
    }

    const names = Object.keys(captured.surface);
    const members = names.reduce((total, name) => total
        + Object.keys(captured.surface[name].proto).length
        + Object.keys(captured.surface[name].statics).length, 0);

    const report = {
        captured_at: new Date().toISOString(),
        iframe_url: captured.url,
        user_agent: emulator.USER_AGENT,
        interfaces: names.length,
        members,
        challenge_owned: captured.challenge_owned,
        globals: captured.globals,
        surface: captured.surface,
    };

    fs.mkdirSync(path.dirname(args.out), { recursive: true });
    fs.writeFileSync(args.out, JSON.stringify(report, null, 1));

    process.stdout.write(JSON.stringify({
        out: args.out, interfaces: names.length, members,
        globals: Object.keys(captured.globals).length,
        challenge_owned: captured.challenge_owned,
    }, null, 2));
}

main().catch((e) => {
    process.stderr.write(`fatal: ${e.stack}\n`);
    process.exit(1);
});
