// Does rendering N Turnstile widgets on ONE page cost less per token than N pages?
//
// Production solves one widget per page, in a throwaway context, and pays 4.1 CPU-s per token.
// Some of that is per-PAGE — context creation, page load, api.js download and parse, process
// startup — and some is per-WIDGET, which is the challenge iframe fetching its 822 KB
// interpreter and executing it. Only the per-page share can be amortised, so the honest
// expectation is a fraction saved, not a multiple: ten widgets still run ten interpreters.
//
// One structural effect could make it better than that. Every widget's iframe is served from
// challenges.cloudflare.com, so N widgets are same-site and Chrome should place them in ONE
// shared renderer rather than N — and the renderer is 72% of the cost.
//
// Two things could make it worse, and neither is a CPU question:
//
//   - A fresh context per solve was measured to be load-bearing twice over: it stops
//     Cloudflare's per-context challenge state leaking between solves, and it is the recovery
//     path for the ~13% of attempts where a widget silently never fires its callback. Batching
//     shares one context across N widgets, which is exactly what that design avoids.
//   - N identical-sitekey widgets on one page is not a shape real traffic has.
//
// So the arms report tokens-per-page and DISTINCT tokens alongside CPU. A batch that returns
// eight tokens where ten were asked for is a 20% failure however cheap it looks, and duplicate
// tokens would mean the batch is not producing independent solves at all.
//
// Usage:
//   node turnstile_batch_page_ab.cjs [--widths 1,3,5,10] [--rounds 3] [--concurrency 2]

const fs = require('fs');
const os = require('os');
const path = require('path');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_batch_ab');

if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');
const solver = require('./in_house_captcha_solver.cjs');
const flagAb = require('./turnstile_flag_ab.cjs');

/**
 * A page carrying `count` widgets, each reporting into a shared array.
 *
 * Deliberately the same shape as the production page — same inline favicon (without it
 * Chrome's /favicon.ico fallback is the one request per solve that actually reaches IVAC),
 * same async api.js, one onload callback. The only difference is the loop.
 *
 * Widgets are rendered into separate containers because turnstile.render() binds one widget to
 * one element; rendering twice into the same container throws.
 */
function buildBatchPage(siteKey, count) {
    const containers = Array.from({ length: count }, (_, i) => `<div id="w${i}"></div>`).join('');

    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>&nbsp;</title>
<link rel="icon" href="data:,"></head><body>
${containers}
<script>
window.__ihcTokens = [];
window.__ihcErrors = [];
window.__ihcExpected = ${count};
window.__ihcOnload = function () {
    for (var i = 0; i < ${count}; i++) {
        turnstile.render('#w' + i, {
            sitekey: '${siteKey}',
            callback: function (token) { window.__ihcTokens.push(token); },
            'error-callback': function (code) { window.__ihcErrors.push(String(code || 'unknown')); },
        });
    }
};
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__ihcOnload" async defer></script>
</body></html>`;
}

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
        widths: [1, 3, 5, 10],
        rounds: 3,
        concurrency: 2,
        // Wider pages legitimately need longer: ten challenges start together and Cloudflare
        // answers each on its own schedule. Scaled rather than fixed so a wide arm is not
        // failed for a timeout the narrow arm never faced.
        baseBudgetMs: 12000,
        perWidgetMs: 1500,
    };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
        else if (argv[i] === '--widths') args.widths = argv[++i].split(',').map((n) => parseInt(n, 10));
        else if (argv[i] === '--rounds') args.rounds = parseInt(argv[++i], 10);
        else if (argv[i] === '--concurrency') args.concurrency = parseInt(argv[++i], 10);
    }

    return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** One page carrying `width` widgets; resolves with every token it managed to collect. */
async function solvePage(browser, siteKey, pageUrl, width, budgetMs) {
    const target = new URL(pageUrl).href;
    const context = await browser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(buildBatchPage(siteKey, width)).toString('base64');

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

        // Wait for ALL widgets, but take whatever arrived if the deadline passes — a partial
        // page is the interesting failure mode here and must be measured, not thrown away.
        await page
            .waitForFunction(
                () => window.__ihcTokens.length + window.__ihcErrors.length >= window.__ihcExpected,
                { timeout: remaining, polling: 150 },
            )
            .catch(() => {});

        return await page.evaluate(() => ({
            tokens: window.__ihcTokens.slice(),
            errors: window.__ihcErrors.slice(),
        }));
    } finally {
        await context.close().catch(() => {});
    }
}

/** One arm: `pages` pages of `width` widgets each, on a browser launched for this arm alone. */
async function runBatch(width, pages, args) {
    const sampler = new flagAb.TreeCpuSampler();
    sampler.start();
    const startedAt = Date.now();

    const browser = await puppeteer.launch({
        headless: true, args: flagAb.BASE_ARGS, defaultViewport: null,
    });

    const budgetMs = args.baseBudgetMs + (width - 1) * args.perWidgetMs;
    const collected = [];
    let next = 0;

    const worker = async () => {
        while (next < pages) {
            next++;

            try {
                collected.push(await solvePage(browser, args.siteKey, args.pageUrl, width, budgetMs));
            } catch (e) {
                collected.push({ tokens: [], errors: [e.message.slice(0, 90)] });
            }
        }
    };

    await Promise.all(Array.from({ length: Math.min(args.concurrency, pages) }, worker));

    await browser.close().catch(() => {});
    await sleep(1200);

    const cpu = sampler.stop();
    const tokens = collected.flatMap((c) => c.tokens);

    return {
        width,
        pages,
        cpu,
        wallMs: Date.now() - startedAt,
        expected: pages * width,
        tokens: tokens.length,
        distinct: new Set(tokens).size,
        errors: collected.flatMap((c) => c.errors),
    };
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    // Hold total tokens per arm roughly constant so every arm faces Cloudflare equally: a
    // 10-wide arm runs fewer pages than a 1-wide one.
    const TOKENS_PER_ARM = 10;

    process.stderr.write(`[batch-ab] widths ${args.widths.join(',')} x ${args.rounds} rounds, ~${TOKENS_PER_ARM} tokens/arm\n`);

    const batches = Object.fromEntries(args.widths.map((w) => [w, []]));

    for (let round = 1; round <= args.rounds; round++) {
        const order = args.widths.slice(round % args.widths.length)
            .concat(args.widths.slice(0, round % args.widths.length));

        for (const width of order) {
            const pages = Math.max(1, Math.round(TOKENS_PER_ARM / width));
            const result = await runBatch(width, pages, args);
            batches[width].push(result);

            process.stderr.write(
                `[batch-ab] round ${round} width ${String(width).padStart(2)} `
                + `${result.tokens}/${result.expected} tokens  ${result.cpu.toFixed(1)} CPU-s  `
                + `${(result.wallMs / 1000).toFixed(1)}s\n`,
            );

            await sleep(1500);
        }
    }

    const summary = args.widths.map((width) => {
        const runs = batches[width];
        const cpu = runs.reduce((t, r) => t + r.cpu, 0);
        const tokens = runs.reduce((t, r) => t + r.tokens, 0);
        const distinct = runs.reduce((t, r) => t + r.distinct, 0);
        const expected = runs.reduce((t, r) => t + r.expected, 0);
        const errors = runs.flatMap((r) => r.errors);

        return {
            widgets_per_page: width,
            expected,
            tokens,
            yield_pct: expected ? Math.round((tokens / expected) * 100) : 0,
            all_distinct: distinct === tokens,
            cpu_total: Number(cpu.toFixed(1)),
            cpu_per_token: tokens ? Number((cpu / tokens).toFixed(2)) : null,
            p50_page_s: Number((runs.reduce((t, r) => t + r.wallMs, 0) / runs.length / 1000).toFixed(1)),
            errors: [...new Set(errors)].slice(0, 3),
        };
    });

    const base = summary.find((s) => s.widgets_per_page === 1);

    for (const row of summary) {
        row.cpu_vs_single_pct = base && base.cpu_per_token && row.cpu_per_token
            ? Math.round(((row.cpu_per_token - base.cpu_per_token) / base.cpu_per_token) * 100)
            : null;
    }

    const report = {
        measured_at: new Date().toISOString(),
        host: os.hostname(),
        rounds: args.rounds,
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

if (require.main === module) {
    main().catch((e) => {
        process.stderr.write(`fatal: ${e.stack}\n`);
        process.exit(1);
    });
}

module.exports = { buildBatchPage };
