// A/B the browser binary: full headless Chrome against chrome-headless-shell.
//
// chrome-headless-shell is the OLD headless implementation. It drops the whole browser UI
// layer, so it is meaningfully lighter in both RAM and process count — which is exactly what
// is wanted here, since this host also runs the Java bot.
//
// It is NOT a free swap, and must not be adopted on the memory saving alone. Step 3 of the
// emulation work measured that Cloudflare checks JS-observable browser signals and rejects a
// mismatch, and headless-shell presents a different surface from the new headless mode. A
// binary that halves memory and also halves the success rate is a worse solver, so this
// measures success rate alongside cost and the decision is made on both.
//
// Solves run SEQUENTIALLY. The numbers being compared move with local queueing, so
// overlapping the arms would confound a fingerprint rejection with contention.
//
// Usage:
//   node turnstile_browser_ab.cjs [--samples 10] [--site-key <k>] [--page-url <u>]

const fs = require('fs');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_browser_ab');

if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');
const solver = require('./in_house_captcha_solver.cjs');

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

const BASE_ARGS = [
    '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
    '--disable-blink-features=AutomationControlled', '--disable-background-networking',
    '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding',
    '--disable-background-timer-throttling', '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--disable-component-update', '--disable-default-apps',
    '--disable-sync', '--disable-domain-reliability', '--disable-client-side-phishing-detection',
    '--disable-breakpad', '--metrics-recording-only',
    '--disable-features=Translate,MediaRouter,OptimizationHints,BackForwardCache,InterestFeedContentSuggestions',
    '--window-size=1280,800', `--user-agent=${USER_AGENT}`,
];

function isWritable(dir) {
    try {
        fs.accessSync(dir, fs.constants.W_OK);

        return true;
    } catch (e) {
        return false;
    }
}

function parseArgs(argv) {
    const args = { samples: 10, siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: 'https://appointment.ivacbd.com/' };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--samples') args.samples = parseInt(argv[++i], 10);
        else if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
    }

    return args;
}

function log(msg) {
    process.stderr.write(`[browser-ab] ${msg}\n`);
}

/** Every descendant of a pid, so a browser's renderers and helpers are counted too. */
function processTree(root) {
    const pids = [root];
    const children = new Map();

    for (const entry of fs.readdirSync('/proc')) {
        if (!/^\d+$/.test(entry)) {
            continue;
        }

        try {
            const stat = fs.readFileSync(`/proc/${entry}/stat`, 'utf8');
            const ppid = parseInt(stat.slice(stat.lastIndexOf(')') + 2).split(' ')[1], 10);

            if (!children.has(ppid)) {
                children.set(ppid, []);
            }

            children.get(ppid).push(parseInt(entry, 10));
        } catch (e) {
            // Process exited between readdir and read; nothing to count.
        }
    }

    for (let i = 0; i < pids.length; i++) {
        for (const child of children.get(pids[i]) || []) {
            pids.push(child);
        }
    }

    return pids;
}

/** Summed RSS (MB), CPU ticks and process count for a tree. */
function measureTree(root) {
    let rssKb = 0;
    let ticks = 0;
    let count = 0;

    for (const pid of processTree(root)) {
        try {
            const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
            const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');

            ticks += parseInt(fields[11], 10) + parseInt(fields[12], 10); // utime + stime
            rssKb += parseInt(fs.readFileSync(`/proc/${pid}/statm`, 'utf8').split(' ')[1], 10) * 4;
            count++;
        } catch (e) {
            // Raced with exit.
        }
    }

    return { rss_mb: Math.round(rssKb / 1024), cpu_s: +(ticks / 100).toFixed(1), processes: count };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** One solve in a throwaway context — the same shape the production solver uses. */
async function solveOnce(browser, siteKey, pageUrl, budgetMs) {
    const target = new URL(pageUrl).href;
    const context = await browser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(solver.buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();
        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: solver.cdpEscapeUrl(target), requestStage: 'Request', resourceType: 'Document' }],
        });
        cdp.on('Fetch.requestPaused', ({ requestId }) => {
            cdp.send('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                body: html,
            }).catch(() => {});
        });

        const startedAt = Date.now();
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: budgetMs });

        const handle = await page.waitForFunction(
            () => (window.__ihcToken ? { token: window.__ihcToken } : (window.__ihcError ? { error: window.__ihcError } : false)),
            { timeout: Math.max(1000, budgetMs - (Date.now() - startedAt)), polling: 100 },
        );

        const result = await handle.jsonValue();

        if (result.error) {
            throw new Error(result.error);
        }

        return result.token;
    } finally {
        await context.close().catch(() => {});
    }
}

async function runArm(name, launchOptions, args) {
    log(`${name}: launching`);
    const browser = await puppeteer.launch(launchOptions);
    const pid = browser.process().pid;

    // Let the browser settle so the idle baseline is not measured mid-startup.
    await sleep(1500);
    const idle = measureTree(pid);
    const before = idle.cpu_s;

    let solved = 0;
    let totalMs = 0;
    let peakRss = idle.rss_mb;
    let peakProcs = idle.processes;
    const errors = [];

    try {
        for (let i = 0; i < args.samples; i++) {
            const startedAt = Date.now();

            try {
                await solveOnce(browser, args.siteKey, args.pageUrl, 20000);
                solved++;
                totalMs += Date.now() - startedAt;
            } catch (e) {
                errors.push(String(e.message).slice(0, 80));
            }

            const now = measureTree(pid);
            peakRss = Math.max(peakRss, now.rss_mb);
            peakProcs = Math.max(peakProcs, now.processes);
        }

        const after = measureTree(pid);

        return {
            arm: name,
            samples: args.samples,
            solved,
            rate: Math.round((solved / args.samples) * 100),
            avg_ms: solved ? Math.round(totalMs / solved) : null,
            idle_rss_mb: idle.rss_mb,
            idle_processes: idle.processes,
            peak_rss_mb: peakRss,
            peak_processes: peakProcs,
            cpu_s_total: +(after.cpu_s - before).toFixed(1),
            cpu_s_per_solve: solved ? +((after.cpu_s - before) / solved).toFixed(2) : null,
            errors: [...new Set(errors)].slice(0, 3),
        };
    } finally {
        await browser.close().catch(() => {});
        await sleep(1000);
    }
}

async function main() {
    const args = parseArgs(process.argv.slice(2));

    const chrome = await runArm('chrome', { headless: true, args: BASE_ARGS, defaultViewport: null }, args);
    log(`chrome: ${chrome.solved}/${chrome.samples}, peak ${chrome.peak_rss_mb} MB, ${chrome.cpu_s_per_solve}s CPU/solve`);

    const shell = await runArm('chrome-headless-shell', {
        headless: 'shell',
        args: BASE_ARGS,
        defaultViewport: null,
    }, args);
    log(`shell: ${shell.solved}/${shell.samples}, peak ${shell.peak_rss_mb} MB, ${shell.cpu_s_per_solve}s CPU/solve`);

    // Site isolation is what forces the widget's cross-origin iframe into its own process, so
    // turning it off is the one remaining lever that removes a whole renderer. Whether the
    // challenge still passes in a shared process is not something to assume.
    const shared = await runArm('chrome-no-site-isolation', {
        headless: true,
        args: [...BASE_ARGS, '--disable-site-isolation-trials', '--disable-features=IsolateOrigins,site-per-process'],
        defaultViewport: null,
    }, args);
    log(`shared: ${shared.solved}/${shared.samples}, peak ${shared.peak_rss_mb} MB, ${shared.cpu_s_per_solve}s CPU/solve`);

    const report = {
        captured_at: new Date().toISOString(),
        samples_per_arm: args.samples,
        arms: [chrome, shell, shared],
        verdict: {
            rss_saving_pct: chrome.peak_rss_mb
                ? Math.round(((chrome.peak_rss_mb - shell.peak_rss_mb) / chrome.peak_rss_mb) * 100)
                : null,
            cpu_saving_pct: chrome.cpu_s_per_solve
                ? Math.round(((chrome.cpu_s_per_solve - shell.cpu_s_per_solve) / chrome.cpu_s_per_solve) * 100)
                : null,
            success_delta_pts: shell.rate - chrome.rate,
        },
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
