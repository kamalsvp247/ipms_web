// Decides whether a browserless Turnstile tier is possible at all.
//
// Step 3 established that Cloudflare rejects any platform claim even when the User-Agent AND
// every client hint are moved to match, so the cross-check sits below the HTTP layer. That
// leaves one question, and it gates weeks of work on steps 4-7: can a non-browser client
// speak this protocol at all when it is telling the truth about what it is?
//
// The experiment isolates the transport and nothing else:
//
//   1. Drive a real solve in Chrome, exactly as the production solver does.
//   2. Pause the FIRST /fo/ flow POST at Request stage and capture its URL, headers and body.
//   3. ABORT it, so the browser never sends it.
//   4. Issue those same bytes from Node, over plain https (HTTP/1.1) and over http2.
//
// Aborting is what makes this clean. The request is issued exactly once, by Node, in a live
// session with fresh tokens — so a rejection cannot be "already used", and the payload cannot
// be wrong, because it is the payload Chrome built. The only difference left between the arm
// that is known to work and the arm under test is the TLS handshake and HTTP client.
//
// Reading the result:
//   200 + ~800 KB  -> transport is fine. The challenge interpreter came back, steps 4-7 are
//                     worth building, and the remaining work is executing that program.
//   403 / 4xx      -> the check is at the TLS layer. No amount of VM work fixes it; Chrome
//                     stays in the hot path and 100 accounts is a CPU problem, not a
//                     protocol one.
//
// Usage:
//   node turnstile_transport_test.cjs [--samples 3] [--site-key <k>] [--page-url <u>]

const fs = require('fs');
const http2 = require('http2');
const https = require('https');
const path = require('path');
const zlib = require('zlib');

const STORAGE = path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const OUT_DIR = path.join(__dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_transport');

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
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-blink-features=AutomationControlled',
    '--no-first-run',
    '--no-default-browser-check',
    '--window-size=1280,800',
    `--user-agent=${USER_AGENT}`,
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
    const args = {
        samples: 3,
        siteKey: '0x4AAAAAACghKkJHL1t7UkuZ',
        pageUrl: 'https://appointment.ivacbd.com/',
    };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--samples') args.samples = parseInt(argv[++i], 10);
        else if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
    }

    return args;
}

function log(msg) {
    process.stderr.write(`[transport-test] ${new Date().toISOString()} ${msg}\n`);
}

/**
 * Drive one solve far enough to capture the first flow POST, then abort it.
 *
 * The POST is issued from the widget's out-of-process iframe, so a page session never sees
 * it — the Fetch pattern has to be installed on every target down the tree, holding each new
 * one at waitForDebuggerOnStart until it is armed.
 *
 * @return {Promise<{url: string, method: string, headers: object, body: string, base64: boolean}>}
 */
async function captureFlowRequest(browser, siteKey, pageUrl, budgetMs) {
    const target = new URL(pageUrl).href;
    const context = await browser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(solver.buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();
        const armed = new Set();
        let resolveCapture;
        let rejectCapture;
        const captured = new Promise((resolve, reject) => {
            resolveCapture = resolve;
            rejectCapture = reject;
        });

        const arm = async (session, isRoot) => {
            if (armed.has(session)) {
                return;
            }

            armed.add(session);

            session.on('Fetch.requestPaused', async (e) => {
                const isFlowPost = e.request.method === 'POST'
                    && /\/cdn-cgi\/challenge-platform\/h\/[a-z]\/fo\//.test(e.request.url);

                if (isFlowPost) {
                    let body = e.request.postData || '';
                    let base64 = false;

                    if (!body) {
                        const fetched = await session
                            .send('Fetch.getRequestPostData', { requestId: e.requestId })
                            .catch(() => null);

                        if (fetched) {
                            body = fetched.postData;
                            base64 = Boolean(fetched.base64Encoded);
                        }
                    }

                    // Abort rather than continue: the request must be issued exactly once,
                    // by Node, or a rejection could just mean Cloudflare saw it twice.
                    await session
                        .send('Fetch.failRequest', { requestId: e.requestId, errorReason: 'Aborted' })
                        .catch(() => {});

                    resolveCapture({
                        url: e.request.url,
                        method: e.request.method,
                        headers: e.request.headers,
                        body,
                        base64,
                    });

                    return;
                }

                if (isRoot && e.request.url === target && e.resourceType === 'Document') {
                    await session
                        .send('Fetch.fulfillRequest', {
                            requestId: e.requestId,
                            responseCode: 200,
                            responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                            body: html,
                        })
                        .catch(() => {});

                    return;
                }

                await session.send('Fetch.continueRequest', { requestId: e.requestId }).catch(() => {});
            });

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

            await session.send('Fetch.enable', {
                patterns: [{ urlPattern: '*', requestStage: 'Request' }],
            }).catch(() => {});

            await session.send('Runtime.runIfWaitingForDebugger').catch(() => {});
        };

        await arm(cdp, true);

        page.goto(target, { waitUntil: 'domcontentloaded', timeout: budgetMs }).catch(() => {});

        const timer = setTimeout(() => rejectCapture(new Error('no flow POST within budget')), budgetMs);

        try {
            return await captured;
        } finally {
            clearTimeout(timer);
        }
    } finally {
        await context.close().catch(() => {});
    }
}

/** Cloudflare answers br or gzip; the size only means something decoded. */
function decode(buffer, encoding) {
    try {
        if (encoding === 'br') return zlib.brotliDecompressSync(buffer);
        if (encoding === 'gzip') return zlib.gunzipSync(buffer);
        if (encoding === 'deflate') return zlib.inflateSync(buffer);
    } catch (e) {
        return buffer;
    }

    return buffer;
}

/**
 * Headers Chrome put on the request, minus the ones a client must own. Content-Length is
 * recomputed by the transport, and the HTTP/2 pseudo-headers are not real headers.
 */
function wireHeaders(headers) {
    const out = {};

    for (const [name, value] of Object.entries(headers)) {
        const lower = name.toLowerCase();

        if (lower.startsWith(':') || lower === 'content-length' || lower === 'host') {
            continue;
        }

        out[lower] = value;
    }

    return out;
}

/** Issue the captured request over plain https — HTTP/1.1, Node's TLS stack. */
function sendOverH1(captured) {
    const url = new URL(captured.url);
    const body = Buffer.from(captured.body, captured.base64 ? 'base64' : 'utf8');

    return new Promise((resolve) => {
        const req = https.request(
            {
                host: url.host,
                path: url.pathname + url.search,
                method: captured.method,
                headers: { ...wireHeaders(captured.headers), 'content-length': body.length },
            },
            (res) => {
                const chunks = [];
                res.on('data', (c) => chunks.push(c));
                res.on('end', () => {
                    const raw = Buffer.concat(chunks);
                    const decoded = decode(raw, res.headers['content-encoding']);

                    resolve({
                        arm: 'node-h1',
                        protocol: 'http/1.1',
                        status: res.statusCode,
                        bytes_wire: raw.length,
                        bytes_decoded: decoded.length,
                        cf_ray: res.headers['cf-ray'] || null,
                        cf_chl_gen: res.headers['cf-chl-gen'] ? 'present' : null,
                        body_preview: decoded.subarray(0, 160).toString('utf8'),
                    });
                });
            },
        );

        req.on('error', (e) => resolve({ arm: 'node-h1', protocol: 'http/1.1', error: e.message }));
        req.write(body);
        req.end();
    });
}

/**
 * Issue the same request over HTTP/2. Chrome negotiated h3 for this call, so if h1 is refused
 * but h2 is accepted the discriminator is the protocol rather than the TLS fingerprint — a
 * materially different conclusion.
 */
function sendOverH2(captured) {
    const url = new URL(captured.url);
    const body = Buffer.from(captured.body, captured.base64 ? 'base64' : 'utf8');

    return new Promise((resolve) => {
        const client = http2.connect(url.origin);
        const done = (result) => {
            client.close();
            resolve(result);
        };

        client.on('error', (e) => resolve({ arm: 'node-h2', protocol: 'h2', error: e.message }));

        const req = client.request({
            ...wireHeaders(captured.headers),
            ':method': captured.method,
            ':path': url.pathname + url.search,
            'content-length': body.length,
        });

        const chunks = [];
        let responseHeaders = {};

        req.on('response', (h) => {
            responseHeaders = h;
        });
        req.on('data', (c) => chunks.push(c));
        req.on('error', (e) => done({ arm: 'node-h2', protocol: 'h2', error: e.message }));
        req.on('end', () => {
            const raw = Buffer.concat(chunks);
            const decoded = decode(raw, responseHeaders['content-encoding']);

            done({
                arm: 'node-h2',
                protocol: 'h2',
                status: responseHeaders[':status'],
                bytes_wire: raw.length,
                bytes_decoded: decoded.length,
                cf_ray: responseHeaders['cf-ray'] || null,
                cf_chl_gen: responseHeaders['cf-chl-gen'] ? 'present' : null,
                body_preview: decoded.subarray(0, 160).toString('utf8'),
            });
        });

        req.write(body);
        req.end();
    });
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const browser = await puppeteer.launch({ headless: true, args: CHROME_ARGS, defaultViewport: null });
    const samples = [];

    try {
        for (let i = 0; i < args.samples; i++) {
            log(`sample ${i + 1}/${args.samples}: capturing flow POST`);

            let captured;

            try {
                captured = await captureFlowRequest(browser, args.siteKey, args.pageUrl, 20000);
            } catch (e) {
                log(`  capture failed: ${e.message}`);
                samples.push({ capture_error: e.message });
                continue;
            }

            log(`  captured ${captured.body.length} byte body -> replaying from Node`);

            const [h1, h2] = await Promise.all([sendOverH1(captured), sendOverH2(captured)]);
            log(`  h1 ${h1.status ?? h1.error} | h2 ${h2.status ?? h2.error}`);

            samples.push({
                url: captured.url,
                request_bytes: captured.body.length,
                arms: [h1, h2],
            });
        }
    } finally {
        await browser.close().catch(() => {});
    }

    const verdictFor = (arm) => {
        const results = samples.flatMap((s) => (s.arms || []).filter((a) => a.arm === arm));
        const ok = results.filter((r) => r.status === 200 && r.bytes_decoded > 100_000);

        return {
            arm,
            samples: results.length,
            accepted: ok.length,
            statuses: [...new Set(results.map((r) => r.status ?? r.error))],
        };
    };

    const report = {
        captured_at: new Date().toISOString(),
        site_key: args.siteKey,
        page_url: args.pageUrl,
        note: 'Request aborted in Chrome and issued once from Node — same session, same bytes.',
        verdicts: [verdictFor('node-h1'), verdictFor('node-h2')],
        samples,
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
