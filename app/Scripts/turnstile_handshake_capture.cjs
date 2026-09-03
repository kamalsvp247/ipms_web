// Captures the postMessage handshake between Turnstile's api.js and its challenge iframe.
//
// The emulator (turnstile_emulator.cjs) runs Cloudflare's bootstrap in node:vm with no errors
// and no stub misses, but never calls out: it registers ['message', 'error',
// 'DOMContentLoaded'] and blocks on `message`. In a browser the parent frame's api.js hands
// the iframe its parameters that way, so nothing downstream — the interpreter, the Worker,
// the token-bearing POST — runs until those messages exist.
//
// Capture works by installing a capture-phase `message` listener in every frame before any
// page script runs, accumulating onto a global array, and reading it back with
// Runtime.evaluate afterwards.
//
// It deliberately does NOT use page.exposeFunction() to push events out. Cloudflare detects
// the binding puppeteer injects and answers with a widget that never renders an iframe and
// never fires a callback — measured 0/2 solves against 2/2 for every other variant. Polling a
// plain global is the only safe way to get data out of this page.
//
// Usage:
//   node turnstile_handshake_capture.cjs [--site-key <k>] [--page-url <u>]

const fs = require('fs');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_handshake');

if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');
const solver = require('./in_house_captcha_solver.cjs');

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

const CHROME_ARGS = [
    '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
    '--disable-blink-features=AutomationControlled', '--no-first-run', '--no-default-browser-check',
    '--window-size=1280,800', `--user-agent=${USER_AGENT}`,
];

// Installed before any page script. Structured clones can hold values JSON cannot represent,
// so each message is serialised defensively and a failure is recorded rather than thrown.
const HOOK = `
(() => {
  window.__ihcMsgs = [];
  const t0 = Date.now();
  window.addEventListener('message', (e) => {
    try {
      window.__ihcMsgs.push({
        t: Date.now() - t0,
        origin: e.origin,
        source: e.source === window.parent ? 'parent' : (e.source === window ? 'self' : 'other'),
        type: typeof e.data,
        data: typeof e.data === 'string' ? e.data : JSON.stringify(e.data),
      });
    } catch (err) {
      window.__ihcMsgs.push({ t: Date.now() - t0, origin: e.origin, error: String(err && err.message) });
    }
  }, true);
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

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const target = new URL(args.pageUrl).href;
    const browser = await puppeteer.launch({ headless: true, args: CHROME_ARGS, defaultViewport: null });

    try {
        const context = await browser.createBrowserContext();
        const page = await context.newPage();
        const html = Buffer.from(solver.buildPage(args.siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();
        const sessions = [];
        const armed = new Set();

        const arm = async (session, label, isRoot) => {
            if (armed.has(session)) {
                return;
            }

            armed.add(session);
            sessions.push({ session, label });

            session.on('Target.attachedToTarget', (e) => {
                const connection = session.connection();
                const child = connection ? connection.session(e.sessionId) : null;

                if (child) {
                    arm(child, `${e.targetInfo.type}:${e.targetInfo.targetId.slice(0, 8)}`, false).catch(() => {});
                }
            });

            await session.send('Target.setAutoAttach', {
                autoAttach: true, waitForDebuggerOnStart: true, flatten: true,
            }).catch(() => {});

            await session.send('Page.enable').catch(() => {});
            // The hold at waitForDebuggerOnStart is what makes this land before Cloudflare's
            // own script; installed afterwards it would miss the opening messages.
            await session.send('Page.addScriptToEvaluateOnNewDocument', { source: HOOK }).catch(() => {});

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

        await arm(cdp, 'page', true);

        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});

        // Solve normally so the capture covers the whole exchange, not just the opening.
        let token = null;

        try {
            const handle = await page.waitForFunction(
                () => window.__ihcToken || (window.__ihcError ? { error: window.__ihcError } : false),
                { timeout: 20000, polling: 100 },
            );
            token = await handle.jsonValue();
        } catch (e) {
            token = null;
        }

        await sleep(1000);

        const frames = [];

        for (const { session, label } of sessions) {
            const result = await session.send('Runtime.evaluate', {
                expression: 'JSON.stringify(window.__ihcMsgs || [])',
                returnByValue: true,
            }).catch(() => null);

            const raw = result && result.result ? result.result.value : null;
            let messages = [];

            try {
                messages = raw ? JSON.parse(raw) : [];
            } catch (e) {
                messages = [];
            }

            if (messages.length > 0) {
                frames.push({ frame: label, count: messages.length, messages });
            }
        }

        const report = {
            captured_at: new Date().toISOString(),
            site_key: args.siteKey,
            page_url: target,
            solved: typeof token === 'string' || Boolean(token && !token.error),
            frames_with_messages: frames.length,
            frames,
        };

        fs.mkdirSync(OUT_DIR, { recursive: true });
        fs.writeFileSync(
            path.join(OUT_DIR, `${report.captured_at.replace(/[:.]/g, '-')}.json`),
            JSON.stringify(report, null, 2),
        );

        process.stdout.write(JSON.stringify(report, null, 2));
    } finally {
        await browser.close().catch(() => {});
    }
}

main().catch((e) => {
    process.stderr.write(`fatal: ${e.stack}\n`);
    process.exit(1);
});
