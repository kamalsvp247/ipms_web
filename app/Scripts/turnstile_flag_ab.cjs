// A/B Chrome launch flags and page shape for CPU cost per solve.
//
// The CPU split says where the money is: 4.1 CPU-s per solve, of which the Node orchestrator
// is 2.5% and Chrome is the rest — 72% renderer, 17% browser process, 4.4% gpu-process. So the
// only levers that matter are ones that make Chrome itself cheaper, and the question is which
// of them Cloudflare notices.
//
// Two properties make a naive measurement worthless, and this harness exists to handle both.
//
// FIRST: CPU is not the only axis. Step 3 of the emulation work measured that Cloudflare
// rejects specific signals outright, so a flag that saves a core and costs a third of the
// solves is a loss. Every arm therefore reports success rate beside CPU, and an arm that
// solves worse is a failure however cheap it is.
//
// SECOND: Cloudflare's own response time drifts. A batch run cold measured 1.79 solves/s and
// the same batch minutes later measured 0.89, with CPU per solve unchanged — the challenge was
// simply slower to answer under sustained hammering. Arms are therefore INTERLEAVED round
// robin rather than run as consecutive blocks: sequential blocks would attribute Cloudflare's
// mood to whichever flag happened to run during it.
//
// CPU is measured exactly rather than sampled. Each arm's browser is a child of this process,
// so once it is closed and reaped, the delta in this process's own cutime/cstime is the whole
// Chrome tree's CPU — renderers included, since the browser process reaps them and its
// accumulated child time rolls up on exit.
//
// Usage:
//   node turnstile_flag_ab.cjs [--rounds 4] [--batch 4] [--concurrency 3] [--arms a,b,c]

const fs = require('fs');
const os = require('os');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_flag_ab');

if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');
const solver = require('./in_house_captcha_solver.cjs');

const CLK = 100; // USER_HZ; /proc reports child CPU in ticks
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

/** Production's flags, copied so an arm can be expressed as a diff against them. */
const BASE_ARGS = [
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
    '--disable-domain-reliability',
    '--disable-client-side-phishing-detection',
    '--disable-breakpad',
    '--metrics-recording-only',
    '--disable-features=Translate,MediaRouter,OptimizationHints,BackForwardCache,InterestFeedContentSuggestions',
    '--window-size=1280,800',
    `--user-agent=${USER_AGENT}`,
];

/**
 * The arms.
 *
 * Each is a named mutation of the production launch, chosen because the CPU split points at
 * it. Nothing here touches a signal step 3 measured as checked — no UA change, no platform
 * claim, no timezone — and `--disable-software-rasterizer` is deliberately absent, because it
 * would remove the WebGL context a real browser has.
 */
const ARMS = {
    /** Production exactly as it runs today. Every other arm is judged against this. */
    baseline: { args: BASE_ARGS },

    /**
     * Fold the GPU process into the browser process. It costs 4.4% of every solve while
     * serving a page with no accelerated content — but it is also where the software WebGL
     * context lives, which Cloudflare does read, so this has to be measured rather than
     * assumed safe.
     */
    'in-process-gpu': { args: [...BASE_ARGS, '--in-process-gpu'] },

    /**
     * Drop site isolation. The widget renders in a cross-origin iframe that Chrome otherwise
     * puts in its own process, so this removes a whole renderer per solve — the largest
     * structural saving available. It also changes the process topology the challenge runs in,
     * which is exactly why it needs a success-rate check.
     */
    'no-site-isolation': {
        // Chrome takes ONE --disable-features, so the isolation flags have to be merged into
        // the existing list rather than passed as a second copy that would silently replace it.
        args: BASE_ARGS.map((a) => (a.startsWith('--disable-features=')
            ? `${a},IsolateOrigins,site-per-process`
            : a)),
    },

    /**
     * Rasterise a 400x300 window instead of 1280x800. Step 3 measured screen metrics as
     * unchecked (5/6, inside noise), so the fingerprint risk is low and the compositing saving
     * is real.
     */
    'small-window': {
        args: BASE_ARGS.map((a) => (a === '--window-size=1280,800' ? '--window-size=400,300' : a)),
    },

    /** Turn off the 2D canvas acceleration and compositing paths a widget never needs. */
    'no-accel': {
        args: [...BASE_ARGS, '--disable-accelerated-2d-canvas', '--disable-gpu-compositing', '--disable-lcd-text', '--disable-partial-raster'],
    },

    /**
     * All of the low-risk savings together. Worth an arm of its own because these are not
     * independent — removing the GPU process changes what compositing costs.
     */
    combined: {
        args: [
            ...BASE_ARGS.map((a) => (a === '--window-size=1280,800' ? '--window-size=400,300' : a)),
            '--in-process-gpu',
            '--disable-accelerated-2d-canvas',
            '--disable-gpu-compositing',
            '--disable-lcd-text',
            '--disable-partial-raster',
        ],
    },
};

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
        rounds: 4,
        batch: 4,
        concurrency: 3,
        arms: Object.keys(ARMS),
        budgetMs: 10000,
    };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
        else if (argv[i] === '--rounds') args.rounds = parseInt(argv[++i], 10);
        else if (argv[i] === '--batch') args.batch = parseInt(argv[++i], 10);
        else if (argv[i] === '--concurrency') args.concurrency = parseInt(argv[++i], 10);
        else if (argv[i] === '--arms') args.arms = argv[++i].split(',');
    }

    return args;
}

/**
 * Positional fields of /proc/<pid>/stat, after the comm field.
 *
 * Split on the LAST ')' — a process name can itself contain parentheses, which is the classic
 * way to misparse this file. After it: state[0], ppid[1], ... utime[11], stime[12].
 */
function procStat(pid) {
    try {
        const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
        const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');

        return {
            ppid: parseInt(fields[1], 10),
            cpu: (parseInt(fields[11], 10) + parseInt(fields[12], 10)) / CLK,
        };
    } catch (e) {
        return null; // exited between readdir and read
    }
}

/**
 * CPU of this process's whole descendant tree, by sampling /proc.
 *
 * The obvious approach — this process's own cutime/cstime — is WRONG for Chrome and was
 * measured to be so: it reported 2.0 CPU-s for a batch the cgroup counted at 6.31, a 3x
 * undercount. cutime only accrues for descendants reaped through the chain, and Chrome's
 * renderer, gpu and utility processes are not; they hang off a zygote and never roll up.
 *
 * Sampling counts them wherever they sit. Arms run strictly one at a time, so every Chrome
 * process alive during an arm's window belongs to that arm.
 */
class TreeCpuSampler {
    constructor(intervalMs = 100) {
        this.intervalMs = intervalMs;
        this.first = new Map();
        this.last = new Map();
        this.timer = null;
    }

    sample() {
        const stats = new Map();

        for (const entry of fs.readdirSync('/proc')) {
            if (!/^\d+$/.test(entry)) {
                continue;
            }

            const pid = parseInt(entry, 10);
            const stat = procStat(pid);

            if (stat) {
                stats.set(pid, stat);
            }
        }

        for (const [pid, stat] of stats) {
            // Walk up to see whether this belongs to us. Bounded, because a broken chain (a
            // reparented orphan) would otherwise loop.
            let cursor = stat.ppid;
            let mine = false;

            for (let depth = 0; depth < 12 && cursor > 1; depth++) {
                if (cursor === process.pid) {
                    mine = true;
                    break;
                }

                const parent = stats.get(cursor);

                if (!parent) {
                    break;
                }

                cursor = parent.ppid;
            }

            if (!mine) {
                continue;
            }

            if (!this.first.has(pid)) {
                this.first.set(pid, stat.cpu);
            }

            this.last.set(pid, stat.cpu);
        }
    }

    start() {
        this.first.clear();
        this.last.clear();
        this.sample();
        this.timer = setInterval(() => this.sample(), this.intervalMs);
    }

    stop() {
        this.sample();
        clearInterval(this.timer);

        let total = 0;

        for (const [pid, start] of this.first) {
            total += Math.max(0, (this.last.get(pid) ?? start) - start);
        }

        return total;
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * One solve, byte-identical to production's solveOnce.
 *
 * It is duplicated rather than imported because production's version leases from the module's
 * own pool, and this harness has to drive a browser it launched itself. Any divergence here
 * makes the whole comparison meaningless, so it is kept a straight copy.
 */
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

        const remaining = Math.max(1000, budgetMs - (Date.now() - startedAt));
        const handle = await page.waitForFunction(
            () => {
                if (window.__ihcToken) return { token: window.__ihcToken };

                return window.__ihcError ? { error: window.__ihcError } : false;
            },
            { timeout: remaining, polling: 100 },
        );

        const result = await handle.jsonValue();

        if (result.error) {
            throw new Error(`turnstile error-callback: ${result.error}`);
        }

        return result.token;
    } finally {
        await context.close().catch(() => {});
    }
}

/**
 * Launch one arm's browser, run a batch, close it, and report what it cost.
 *
 * The browser is launched and closed inside the measurement window on purpose: launch cost
 * differs between arms and is a real part of what production pays, since idle reaping and
 * recycle-after-N mean browsers are relaunched routinely.
 */
async function runBatch(arm, config, args) {
    const sampler = new TreeCpuSampler();
    sampler.start();
    const startedAt = Date.now();

    const browser = await puppeteer.launch({
        headless: true, args: config.args, defaultViewport: null,
    });

    const results = [];
    let next = 0;

    const worker = async () => {
        while (next < args.batch) {
            const index = next++;
            const solveStart = Date.now();

            try {
                const token = await solveOnce(browser, args.siteKey, args.pageUrl, args.budgetMs);
                results[index] = { ok: Boolean(token), ms: Date.now() - solveStart };
            } catch (e) {
                results[index] = { ok: false, ms: Date.now() - solveStart, error: e.message.slice(0, 90) };
            }
        }
    };

    await Promise.all(Array.from({ length: Math.min(args.concurrency, args.batch) }, worker));

    await browser.close().catch(() => {});
    // close() resolves before the OS has reaped every descendant; without this the child CPU
    // of the last renderers lands in the NEXT arm's window.
    await sleep(1200);

    const cpu = sampler.stop();
    const solved = results.filter((r) => r && r.ok).length;

    return {
        arm,
        cpu,
        solved,
        attempted: args.batch,
        wallMs: Date.now() - startedAt,
        latencies: results.filter((r) => r && r.ok).map((r) => r.ms),
        errors: results.filter((r) => r && !r.ok).map((r) => r.error).filter(Boolean),
    };
}

function summarise(arm, batches) {
    const cpu = batches.reduce((t, b) => t + b.cpu, 0);
    const solved = batches.reduce((t, b) => t + b.solved, 0);
    const attempted = batches.reduce((t, b) => t + b.attempted, 0);
    const latencies = batches.flatMap((b) => b.latencies).sort((a, b) => a - b);
    const errors = batches.flatMap((b) => b.errors);

    return {
        arm,
        attempted,
        solved,
        success_pct: attempted ? Math.round((solved / attempted) * 100) : 0,
        cpu_total: Number(cpu.toFixed(1)),
        // Per SOLVE, not per attempt: a failed attempt still burns CPU, and an arm that fails
        // more has to carry that cost. This is the number that decides.
        cpu_per_solve: solved ? Number((cpu / solved).toFixed(2)) : null,
        p50_ms: latencies.length ? latencies[Math.floor(latencies.length / 2)] : null,
        errors: [...new Set(errors)].slice(0, 3),
    };
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const chosen = args.arms.filter((a) => ARMS[a]);

    if (chosen.length === 0) {
        throw new Error(`no known arms in ${args.arms.join(',')}`);
    }

    process.stderr.write(
        `[flag-ab] ${chosen.length} arms x ${args.rounds} rounds x ${args.batch} solves `
        + `(concurrency ${args.concurrency}) = ${chosen.length * args.rounds * args.batch} solves\n`,
    );

    const batches = Object.fromEntries(chosen.map((a) => [a, []]));

    for (let round = 1; round <= args.rounds; round++) {
        // Rotate the order each round so no arm always runs first, which would otherwise give
        // it whatever state Cloudflare carries between back-to-back batches.
        const order = chosen.slice(round % chosen.length).concat(chosen.slice(0, round % chosen.length));

        for (const arm of order) {
            const result = await runBatch(arm, ARMS[arm], args);
            batches[arm].push(result);

            process.stderr.write(
                `[flag-ab] round ${round} ${arm.padEnd(18)} `
                + `${result.solved}/${result.attempted} solved  ${result.cpu.toFixed(1)} CPU-s\n`,
            );

            await sleep(1500);
        }
    }

    const summary = chosen.map((arm) => summarise(arm, batches[arm]));
    const base = summary.find((s) => s.arm === 'baseline');

    for (const row of summary) {
        row.cpu_vs_baseline_pct = base && base.cpu_per_solve && row.cpu_per_solve
            ? Math.round(((row.cpu_per_solve - base.cpu_per_solve) / base.cpu_per_solve) * 100)
            : null;
    }

    const report = {
        measured_at: new Date().toISOString(),
        host: os.hostname(),
        rounds: args.rounds,
        batch: args.batch,
        concurrency: args.concurrency,
        summary,
    };

    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(
        path.join(OUT_DIR, `${report.measured_at.replace(/[:.]/g, '-')}.json`),
        JSON.stringify(report, null, 2),
    );

    process.stdout.write(JSON.stringify(report, null, 2));
}

// Only run as a script. turnstile_batch_page_ab.cjs requires this file for the CPU sampler and
// the production flag list, so importing it must not start a measurement run.
if (require.main === module) {
    main().catch((e) => {
        process.stderr.write(`fatal: ${e.stack}\n`);
        process.exit(1);
    });
}

module.exports = { ARMS, BASE_ARGS, TreeCpuSampler, solveOnce };
