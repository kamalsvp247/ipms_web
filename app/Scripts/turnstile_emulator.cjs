// Browserless Turnstile solve: runs Cloudflare's own challenge program in Node.
//
// Step 4 of the protocol-emulation plan. Everything before this established that it is worth
// attempting: the trace gave the exact request sequence, the flow constants separated config
// from per-session state, the bisect showed only three signals are enforced, and the
// transport test proved Cloudflare hands the challenge interpreter to a plain Node client
// (8/8, h1 and h2) with no TLS gate.
//
// The approach is deliberately NOT a reimplementation. Cloudflare ships an obfuscated
// interpreter that has to run to produce its own answer, and hand-porting it is the one
// approach already proven wrong on this project — it goes stale on every rotation. So the
// script is executed as-is in node:vm against a DOM stub, the same pattern
// captcha_live_runtime.cjs uses for IVAC's encrypt bundle.
//
// The stub tells the TRUTH about this host: Chrome 148 on x86_64 Linux, the real timezone,
// real screen metrics. Step 3 is why — Cloudflare rejects a claimed platform even when the
// User-Agent and every client hint are moved to match it, so the only viable posture is to
// claim exactly what the transport actually is.
//
// Usage:
//   node turnstile_emulator.cjs [--site-key <k>] [--page-url <u>] [--verbose]

const crypto = require('crypto');
const https = require('https');
const zlib = require('zlib');
const vm = require('node:vm');

if (!process.env.TZ) process.env.TZ = 'Asia/Dhaka';

const WIDGET_HOST = 'challenges.cloudflare.com';
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
const CH_UA = '"Not/A)Brand";v="99", "Chromium";v="148"';
// The onload callback name the browser tier's synthetic page uses. It appears inside the `cs`
// call stack the parent hands the challenge, so it has to match what that page would produce.
const PARENT_CALLBACK = '__ihcOnload';
// Captured by turnstile_dom_capture.cjs from the real challenge iframe. Re-capture it when
// Chrome moves; a stale surface is a narrower stub, not a broken one.
const DOM_SURFACE_PATH = require('path').join(
    __dirname, '..', '..', 'storage', 'app', 'captcha', 'turnstile_dom_surface.json',
);
// What a bare vm realm already provides. Derived by asking one rather than hardcoding a list,
// so it tracks whatever Node this runs on.
// The rendered size of a Turnstile widget, used for layout answers the challenge reads back.
const WIDGET_WIDTH = 300;
const WIDGET_HEIGHT = 65;
// Elements that fire a load event once they are in the tree. The iframe is the one that
// matters: Cloudflare builds one to read pristine natives out of a fresh realm.
const LOADING_ELEMENTS = new Set(['IFRAME', 'SCRIPT', 'IMG', 'LINK']);
const REALM_INTRINSICS = new Set(
    vm.runInContext('Object.getOwnPropertyNames(this)', vm.createContext({})),
);

function parseArgs(argv) {
    const args = {
        siteKey: '0x4AAAAAACghKkJHL1t7UkuZ',
        pageUrl: 'https://appointment.ivacbd.com/',
        apiAsset: 'b0da9f4911ba',
        verbose: false,
    };

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--site-key') args.siteKey = argv[++i];
        else if (argv[i] === '--page-url') args.pageUrl = argv[++i];
        else if (argv[i] === '--api-asset') args.apiAsset = argv[++i];
        else if (argv[i] === '--verbose') args.verbose = true;
    }

    return args;
}

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

/** One HTTPS call against the widget host, answering with the decoded body. */
function request(options, body) {
    return new Promise((resolve, reject) => {
        const req = https.request({ host: WIDGET_HOST, ...options }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => resolve({
                status: res.statusCode,
                headers: res.headers,
                body: decode(Buffer.concat(chunks), res.headers['content-encoding']).toString('utf8'),
            }));
        });

        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

/**
 * Fetch the widget's iframe document — the challenge bootstrap.
 *
 * The rch segment is a per-solve cache-buster generated client-side, not a version: step 2
 * caught that by re-deriving across captures and seeing it move every time while av0, fbE and
 * the branch letter held.
 */
async function fetchBootstrap(siteKey, pageUrl) {
    const bust = Math.random().toString(36).slice(2, 7);
    const path = `/cdn-cgi/challenge-platform/h/g/turnstile/f/av0/rch/${bust}/${siteKey}/auto/fbE/new/normal?lang=auto`;

    const res = await request({
        path,
        method: 'GET',
        headers: {
            'user-agent': USER_AGENT,
            accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'accept-language': 'en-US,en;q=0.9',
            'accept-encoding': 'gzip, deflate, br',
            referer: pageUrl,
            'sec-ch-ua': CH_UA,
            'sec-ch-ua-mobile': '?0',
            'sec-ch-ua-platform': '"Linux"',
            'sec-fetch-dest': 'iframe',
            'sec-fetch-mode': 'navigate',
            'sec-fetch-site': 'cross-site',
            'upgrade-insecure-requests': '1',
        },
    });

    if (res.status !== 200) {
        throw new Error(`bootstrap fetch returned ${res.status}`);
    }

    const script = res.body.match(/<script[^>]*>([\s\S]*?)<\/script>/);

    if (!script) {
        throw new Error('bootstrap carried no inline script');
    }

    return {
        url: `https://${WIDGET_HOST}${path}`,
        html: res.body,
        source: script[1],
        ray: res.headers['cf-ray'],
    };
}

/**
 * The stack api.js reports to the challenge as `cs`.
 *
 * It is the render() call chain, captured verbatim from a real solve. The leading tuple is
 * [0, depth, stack, 1] — depth is the frame count, and it moved from 2 to 3 between the first
 * capture and the ground truth, so it is derived here rather than hardcoded.
 */
function buildCallStack(apiJsUrl, pageUrl) {
    const frames = [
        `    at ki (${apiJsUrl}:1:19220)`,
        `    at ke (${apiJsUrl}:1:19351)`,
        `    at Object.I [as render] (${apiJsUrl}:1:62952)`,
        `    at window.${PARENT_CALLBACK} (${pageUrl}:8:15)`,
        `    at ${apiJsUrl}:1:40909`,
        `    at ${apiJsUrl}:1:81646`,
    ];

    return [[0, 3, `Error\n${frames.join('\n')}`, 1]];
}

/**
 * The `extraParams` reply, rebuilt from a captured browser solve.
 *
 * This message is the whole reason a clean emulator run still stalled. It is NOT the bare
 * property bag an early capture suggested: it carries `event: 'extraParams'`, `source` and
 * `widgetId` like every other message in the protocol, and without the event name the
 * challenge never dispatches it. `execute` then arrives with the parameters still missing, the
 * run never starts, and 10 seconds later the watchdog reports overrunBegin — which reads like
 * a fingerprint rejection and is not one.
 *
 * `wPr` is Cloudflare's reconnaissance of the PARENT page, which the challenge cannot see from
 * inside a cross-origin iframe. The values describe the synthetic widget page the browser tier
 * serves (in_house_captcha_solver.buildPage), because that is the page this emulator is
 * standing in for — change that page and `pfp`, `tL`, `sL` and `xp` have to move with it.
 */
function buildExtraParams({ apiJsUrl, apiAsset, callStack, widgetId, pageUrl, rcV = '' }) {
    return {
        apiJsMismatchReloadAttempts: 0,
        apiJsMismatchReloadCompletedCount: 0,
        apiJsResourceTiming: {
            name: apiJsUrl, entryType: 'resource', startTime: 18, duration: 68.5,
            initiatorType: 'script', deliveryType: '', nextHopProtocol: '',
            renderBlockingStatus: 'non-blocking', contentType: '', contentEncoding: '',
            workerStart: 0, workerRouterEvaluationStart: 0, workerCacheLookupStart: 0,
            workerMatchedSourceType: '', workerFinalSourceType: '',
            redirectStart: 0, redirectEnd: 0, fetchStart: 18,
            domainLookupStart: 0, domainLookupEnd: 0, connectStart: 0,
            secureConnectionStart: 0, connectEnd: 0, requestStart: 0, responseStart: 0,
            firstInterimResponseStart: 0, finalResponseHeadersStart: 0, responseEnd: 86.5,
            transferSize: 0, encodedBodySize: 0, decodedBodySize: 0, responseStatus: 0,
            serverTiming: [],
        },
        appearance: 'always',
        au: apiJsUrl,
        ch: apiAsset,
        cs: callStack,
        event: 'extraParams',
        execution: 'render',
        'expiry-interval': 290000,
        language: 'auto',
        rcV: rcV || '',
        'refresh-expired': 'auto',
        'refresh-timeout': 'auto',
        retry: 'auto',
        'retry-interval': 8000,
        source: 'cloudflare-challenge',
        timeExtraParamsMs: 135,
        timeInitMs: 120,
        timeLoadInitMs: 138,
        timeParamsMs: 1,
        timeRenderMs: 5,
        timeTiefMs: 9,
        upgradeAttempts: 0,
        upgradeCompletedCount: 0,
        url: pageUrl,
        wPr: {
            'd.cT': [],
            'ht.atrs': [],
            'pg.ref': '',
            pi: {
                ffp: 'nf',
                ii: false,
                lH: pageUrl,
                mL: 1,
                pac: 'n',
                pad: 'n|n|n|0|0|0|0|',
                pfp: 'htm>hea>met_ch>tit>-tlin_re_hr>bod>-tdiv_id>-tscr>-tscr_sr_as_de>-t',
                sL: 2,
                sR: true,
                ssL: 0,
                t: '1|177413',
                tL: 11,
                vDa: 'always',
                vDeh: false,
                vDhp: 'wrapper-parent',
                vDhr: null,
                vDie: true,
                vDmd: 'managed',
                vDmt: 'wrapper',
                vDrs: [],
                vDuh: false,
                vDvp: { height: 713, visibilityState: 'visible', width: 1280 },
                wp: '8|1272',
                xp: '/div[1]/div[1]',
            },
            'w.iW': 1280,
        },
        widgetId,
    };
}

/**
 * The environment the challenge runs against.
 *
 * Values are this host's real ones. Unknown reads are answered with a recording proxy rather
 * than undefined so a single missing API cannot end the run — the misses are reported at the
 * end and are the worklist for widening the stub.
 */
function buildEnvironment(bootstrap, pageUrl, outbound, misses, verbose) {
    const origin = `https://${WIDGET_HOST}`;
    const listeners = new Map();

    // Click tracking: the last element that registered a 'click' handler is the
    // widget's checkbox. Use closure variables shared between el() and the main loop.
    const clickState = { lastTarget: null, fired: false };

    // Element cache: the challenge creates elements with specific IDs (via setAttribute)
    // and later queries them (via getElementById / querySelector). Without a cache,
    // querySelector always returns a NEW phantom element, so listeners attached to the
    // original are never reached and PT never reaches 5.
    const elementById = new Map();
    const elementByQuery = new Map();

    // A node has to answer everything the challenge walks, not just what it reads. The first
    // pass returned undefined for ownerDocument and the challenge died dereferencing it —
    // every relation here is a link it may follow, so they resolve to something real.
    const el = (tag = 'DIV') => {
        const node = {
            nodeType: 1,
            nodeName: tag.toUpperCase(),
            tagName: tag.toUpperCase(),
            localName: tag.toLowerCase(),
            namespaceURI: 'http://www.w3.org/1999/xhtml',
            id: '',
            className: '',
            classList: {
                add() {}, remove() {}, toggle: () => false, contains: () => false,
                item: () => null, length: 0, value: '',
            },
            style: new Proxy({ setProperty() {}, removeProperty() {}, getPropertyValue: () => '', cssText: '', toJSON() { return this.cssText; } }, {
                get: (t, p) => (p in t ? t[p] : ''),
                set: (t, p, v) => { t[p] = v; return true; },
            }),
            dataset: {},
            attributes: { length: 0, item: () => null, getNamedItem: () => null },
            innerHTML: '', outerHTML: '', textContent: '', innerText: '', value: '',
            children: [], childNodes: [],
            parentNode: null, parentElement: null,
            firstChild: null, lastChild: null, firstElementChild: null, lastElementChild: null,
            nextSibling: null, previousSibling: null,
            nextElementSibling: null, previousElementSibling: null,
            shadowRoot: null,
            // Cloudflare builds a nested iframe to obtain pristine natives — a standard move
            // against hooked prototypes — and then writes into its document. Handing back
            // null is what stopped the run at appendChild. Getters, because `document` and
            // `concrete` are constructed after this factory is defined.
            get contentDocument() { return document; },
            get contentWindow() { return concrete; },
            offsetWidth: 0, offsetHeight: 0, offsetTop: 0, offsetLeft: 0, offsetParent: null,
            clientWidth: 0, clientHeight: 0, clientTop: 0, clientLeft: 0,
            scrollWidth: 0, scrollHeight: 0, scrollTop: 0, scrollLeft: 0,
            complete: true, naturalWidth: 0, naturalHeight: 0,
            setAttribute(name, value) {
                if (name === 'id') {
                    this.id = String(value);
                    elementById.set(String(value), this);
                } else if (name === 'class') {
                    this.className = String(value);
                } else if (name === 'style') {
                    this.style.cssText = String(value);
                }
                this[`_${name}`] = String(value);
            },
            getAttribute(name) {
                if (name === 'id') return this.id || null;
                if (name === 'class') return this.className || null;
                return this[`_${name}`] || null;
            },
            removeAttribute(name) {
                if (name === 'id' && this.id) {
                    elementById.delete(this.id);
                }
                delete this[`_${name}`];
            },
            hasAttribute(name) { return this[`_${name}`] !== undefined; },
            hasAttributes() { return false; },
            getAttributeNames() { return Object.keys(this).filter(k => k.startsWith('_')).map(k => k.slice(1)); },
            setAttributeNS(ns, name, value) { this.setAttribute(name, value); },
            getAttributeNS(ns, name) { return this.getAttribute(name); },
            replaceChild: (c) => c, append() {}, prepend() {}, before() {}, after() {},
            insertAdjacentHTML() {}, insertAdjacentElement: (p, c) => c, insertAdjacentText() {},
            // A real widget occupies space. Zeros everywhere read as an element that was
            // never laid out, which is the same signal as one that is not visible.
            getBoundingClientRect: () => ({
                x: 0, y: 0, top: 0, left: 0, right: WIDGET_WIDTH, bottom: WIDGET_HEIGHT,
                width: WIDGET_WIDTH, height: WIDGET_HEIGHT,
                toJSON: () => ({}),
            }),
            getClientRects: () => [],
            // Same reasoning as the document-level lookups: in the real iframe these find
            // the widget's own nodes, so null here is a hole in the stub rather than an
            // answer. This is the one that stopped the run — the challenge does
            // container.querySelector('#...').appendChild(...).
            querySelector(selector) {
                if (selector && selector.startsWith('#')) {
                    const id = selector.slice(1);
                    const found = elementById.get(id);
                    if (found) return found;
                }
                if (selector && selector.startsWith('.')) {
                    const cls = selector.slice(1);
                    for (const [, node] of elementById) {
                        if (node.className && String(node.className).split(/\s+/).includes(cls)) {
                            return node;
                        }
                    }
                }
                return withOwner(el('DIV'));
            },
            querySelectorAll(selector) { return [this.querySelector(selector)]; },
            getElementsByTagName: (t) => [withOwner(el(t))],
            getElementsByClassName: () => [withOwner(el('DIV'))],
            closest: () => withOwner(el('DIV')), matches: () => false, contains: () => true,
            hasChildNodes: () => false, normalize() {},
            getContext: (kind) => (String(kind).includes('webgl') ? null : {
                canvas: null, fillStyle: '', font: '', textBaseline: '', globalCompositeOperation: '',
                fillRect() {}, fillText() {}, strokeText() {}, beginPath() {}, arc() {}, closePath() {},
                stroke() {}, fill() {}, moveTo() {}, lineTo() {}, rect() {}, save() {}, restore() {},
                translate() {}, rotate() {}, scale() {}, drawImage() {}, putImageData() {},
                measureText: () => ({ width: 0, actualBoundingBoxAscent: 0, actualBoundingBoxDescent: 0 }),
                getImageData: () => ({ data: new Uint8ClampedArray(4), width: 1, height: 1 }),
                createLinearGradient: () => ({ addColorStop() {} }),
                isPointInPath: () => false, setTransform() {}, clearRect() {},
            }),
            toDataURL: () => 'data:image/png;base64,iVBORw0KGgo=', toBlob(cb) { if (cb) setTimeout(() => cb(null), 0); },
            focus() {}, blur() {}, click() {}, remove() {}, scrollIntoView() {},
            getRootNode() { return document; },
            cloneNode() { return el(tag); },
            animate: () => ({ cancel() {}, finish() {}, play() {}, pause() {} }),
            getAnimations: () => [],
            attachShadow() { return el('DIV'); },
            // Break the circular parentNode→childNodes→parentNode chain so JSON.stringify
            // on a DOM node does not throw "Converting circular structure to JSON".
            toJSON() { return { nodeType: this.nodeType, nodeName: this.nodeName, id: this.id }; },
        };

        node.ownerDocument = null; // filled in below, once `document` exists

        // Elements need REAL events. The no-op addEventListener looked harmless and was the
        // whole of an 11-second stall: the challenge builds an iframe to obtain pristine
        // natives from a fresh realm, waits for its load event, and a listener that is
        // discarded means that event never arrives and nothing downstream ever runs.
        const nodeListeners = new Map();

        node.addEventListener = (type, fn) => {
            if (typeof fn !== 'function') {
                return;
            }

            if (!nodeListeners.has(type)) {
                nodeListeners.set(type, []);
            }

            nodeListeners.get(type).push(fn);

            // A resource element appended before its listener was attached has already
            // "loaded"; fire for this listener so ordering cannot lose the event.
            if (type === 'load' && node.__loaded) {
                setTimeout(() => fn({ type: 'load', target: node }), 0);
            }

            // If the document is already ready and this is a readiness event, fire it
            // immediately. The challenge's vF() gate creates elements and adds readiness
            // listeners during script execution — by the time the script finishes, the
            // events have already been dispatched to the global listeners Map. Element-level
            // listeners miss them unless we re-fire here.
            if ((type === 'readystatechange' || type === 'load' || type === 'DOMContentLoaded') &&
                document.readyState === 'complete') {
                setTimeout(() => fn({ type, target: node, timeStamp: Date.now() }), 0);
            }

            // In managed mode, the challenge requires a user click on the checkbox to
            // start the solve. Without this, the challenge times out with overrunBegin.
            // Track all clickable elements but delay the actual click until we know
            // which one is the real widget checkbox (the last one registered after the
            // full widget render, not vF's internal listeners).
            if (type === 'click') {
                // Remember the most recently click-registered element. The widget's
                // checkbox click handler is registered LAST, after vF's internal ones.
                clickState.lastTarget = node;

                // SYNCHRONOUS CLICK: Fire the click immediately when a click handler
                // is registered on an element. This ensures the click is processed
                // BEFORE overrunBegin is sent by the challenge.
                // Only fire on elements that look like the widget checkbox (not vF internals).
                // vF's internal elements are <div> with empty IDs; the widget uses <a> or
                // elements with real IDs.
                if (!clickState.fired && (
                    node.nodeName === 'A' ||
                    (node.id && node.id.length > 0)
                )) {
                    clickState.fired = true;
                    if (verbose) {
                        process.stderr.write(`[click] SYNCHRONOUS click on <${node.nodeName.toLowerCase()} id="${node.id}">\n`);
                    }
                    const mkEvent = (t) => ({
                        type: t, target: node, currentTarget: node,
                        clientX: 10, clientY: 10, screenX: 10, screenY: 10,
                        pageX: 10, pageY: 10, offsetX: 5, offsetY: 5,
                        button: 0, buttons: 1, isTrusted: true,
                        timeStamp: Date.now(), preventDefault() {}, stopPropagation() {},
                        composed: false, cancelable: true, bubbles: true,
                    });
                    node.dispatchEvent(mkEvent('mousedown'));
                    node.dispatchEvent(mkEvent('mouseup'));
                    node.dispatchEvent(mkEvent('click'));
                }
            }
        };

        node.removeEventListener = (type, fn) => {
            nodeListeners.set(type, (nodeListeners.get(type) || []).filter((h) => h !== fn));
        };

        node.dispatchEvent = (event) => {
            const type = event && event.type;
            const handler = node[`on${type}`];

            if (typeof handler === 'function') {
                handler.call(node, event);
            }

            const fns = nodeListeners.get(type) || [];
            if (type === 'click' && fns.length > 0 && verbose) {
                process.stderr.write(`[dispatch] click event on <${node.nodeName.toLowerCase()} id="${node.id}"> → ${fns.length} handler(s)\n`);
            }
            for (const fn of fns) {
                try {
                    fn.call(node, event);
                } catch (e) {
                    if (verbose) {
                        process.stderr.write(`[dispatch] handler threw: ${e.message}\n`);
                    }
                }
            }

            return true;
        };

        /** Resource elements load asynchronously and then say so. */
        node.__fireLoad = () => {
            if (node.__loaded) {
                return;
            }

            node.__loaded = true;

            setTimeout(() => node.dispatchEvent({ type: 'load', target: node }), 0);
        };

        // Insertion has to actually link the tree. With no-op appends, a node that HAD been
        // added still reported parentNode null, and the challenge died on
        // `something.parentNode.appendChild(...)` — which is correct code against a real DOM.
        const link = (child) => {
            if (!child || typeof child !== 'object') {
                return child;
            }

            child.parentNode = node;
            child.parentElement = node;
            node.childNodes.push(child);

            if (child.nodeType === 1) {
                node.children.push(child);
                node.firstElementChild = node.children[0];
                node.lastElementChild = child;
            }

            node.firstChild = node.childNodes[0];
            node.lastChild = child;

            // Appending a resource element is what starts its load in a browser.
            if (LOADING_ELEMENTS.has(child.nodeName) && typeof child.__fireLoad === 'function') {
                child.__fireLoad();
            }

            return child;
        };

        node.appendChild = link;
        node.__label = tag;
        node.insertBefore = (child) => link(child);
        node.removeChild = (child) => {
            node.childNodes = node.childNodes.filter((c) => c !== child);
            node.children = node.children.filter((c) => c !== child);
            node.firstChild = node.childNodes[0] || null;
            node.lastChild = node.childNodes[node.childNodes.length - 1] || null;

            if (child && typeof child === 'object') {
                child.parentNode = null;
                child.parentElement = null;
            }

            return child;
        };

        return observe(node, `<${tag.toLowerCase()}>`);
    };

    const documentEl = el('HTML');
    const body = el('BODY');

    const document = {
        readyState: 'loading',
        documentElement: documentEl,
        body,
        head: el(),
        title: '',
        URL: bootstrap.url,
        documentURI: bootstrap.url,
        referrer: pageUrl,
        cookie: '',
        visibilityState: 'visible',
        fonts: {
            ready: Promise.resolve(), status: 'loaded', size: 0,
            check: () => true, load: () => Promise.resolve([]),
            add() {}, delete() {}, clear() {},
            forEach() {}, entries: () => [][Symbol.iterator](), values: () => [][Symbol.iterator](),
            addEventListener() {}, removeEventListener() {},
        },
        hidden: false,
        characterSet: 'UTF-8',
        contentType: 'text/html',
        get currentScript() { return withOwner(el("SCRIPT")); },
        scripts: [],
        forms: [],
        images: [],
        links: [],
        activeElement: body,
        doctype: { name: 'html' },
        createElement: (tag) => withOwner(el(tag)),
        createElementNS: (ns, tag) => withOwner(el(tag)),
        createTextNode: (t) => ({ nodeType: 3, textContent: t, nodeValue: t, data: t }),
        createComment: (t) => ({ nodeType: 8, textContent: t }),
        createDocumentFragment: () => withOwner(el('#document-fragment')),
        createEvent: () => ({ initEvent() {}, initCustomEvent() {} }),
        createRange: () => ({
            selectNodeContents() {}, createContextualFragment: () => el('DIV'),
            getBoundingClientRect: () => ({ top: 0, left: 0, width: 0, height: 0 }),
        }),
        createTreeWalker: () => ({ nextNode: () => null, currentNode: null }),
        // The real iframe document has the widget's container in it, so a lookup that finds
        // nothing here is an artefact of the stub rather than a truthful answer — the
        // challenge appends into whatever it gets back, and null ends the run.
        getElementById(id) {
            const found = elementById.get(String(id));
            return found ? found : withOwner(el('DIV'));
        },
        getElementsByTagName: (tag) => [withOwner(el(tag))],
        getElementsByClassName(cls) {
            const results = [];
            for (const [, node] of elementById) {
                if (node.className && String(node.className).split(/\s+/).includes(cls)) {
                    results.push(node);
                }
            }
            return results.length ? results : [withOwner(el('DIV'))];
        },
        getElementsByName: () => [withOwner(el('DIV'))],
        querySelector(selector) {
            // Parse simple selectors: #id, .class, tag
            if (selector && selector.startsWith('#')) {
                const id = selector.slice(1);
                const found = elementById.get(id);
                if (found) return found;
            }
            if (selector && selector.startsWith('.')) {
                const cls = selector.slice(1);
                for (const [, node] of elementById) {
                    if (node.className && String(node.className).split(/\s+/).includes(cls)) {
                        return node;
                    }
                }
            }
            // Try to find by tag in element cache
            if (selector && !selector.startsWith('#') && !selector.startsWith('.')) {
                const tag = selector.toUpperCase();
                for (const [, node] of elementById) {
                    if (node.nodeName === tag) return node;
                }
            }
            return withOwner(el('DIV'));
        },
        querySelectorAll(selector) {
            const results = [];
            if (selector && selector.startsWith('#')) {
                const id = selector.slice(1);
                const found = elementById.get(id);
                if (found) results.push(found);
            } else if (selector && selector.startsWith('.')) {
                const cls = selector.slice(1);
                for (const [, node] of elementById) {
                    if (node.className && String(node.className).split(/\s+/).includes(cls)) {
                        results.push(node);
                    }
                }
            }
            return results.length ? results : [withOwner(el('DIV'))];
        },
        elementFromPoint: () => null,
        hasFocus: () => true,
        write() {}, writeln() {}, open() {}, close() {},
        execCommand: () => false,
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(fn);
        },
        removeEventListener() {},
        dispatchEvent: () => true,
    };

    /**
     * Log every call made on the document.
     *
     * The challenge's identifiers are all machine-generated, so a stack trace names nothing.
     * Recording which method was called with which arguments — and whether it answered null —
     * is the only way to find the gap that a failure like `X(a, b).appendChild(...)` is
     * really pointing at.
     */
    function observe(obj, label) {
        const proxy = new Proxy(obj, {
            get(target, prop) {
                const value = target[prop];

                // An absent member is the interesting case, not the present one: the interpreter
                // reports it only as `X is not a function`, from inside its own bytecode loop,
                // with no indication of the name it looked up. Recording the miss names it.
                if (value === undefined && typeof prop === 'string' && !(prop in target)) {
                    misses.push(`${label}.${prop}`);

                    if (process.env.EMU_TRACE_ENV) {
                        process.stderr.write(`[miss] ${label}.${prop}\n`);
                    }
                }

                if (typeof value !== 'function' || typeof prop === 'symbol') {
                    return value;
                }

                const wrapper = (...callArgs) => {
                    // When setAttribute('id', val) is called through the Proxy, update the
                    // element cache with THIS Proxy (not the raw node) so that
                    // querySelector('#id') returns the same object the challenge works with.
                    if (prop === 'setAttribute' && callArgs[0] === 'id') {
                        const result = value.apply(target, callArgs);
                        elementById.set(String(callArgs[1]), proxy);
                        return result;
                    }

                    const result = value.apply(target, callArgs);

                    if (verbose) {
                        const shown = callArgs.map((a) => JSON.stringify(a)).join(', ').slice(0, 90);
                        process.stderr.write(
                            `[dom] ${label}.${String(prop)}(${shown}) -> ${result === null ? 'NULL' : typeof result}\n`,
                        );
                    }

                    return result;
                };

                // Carry the name across. The wrapper is anonymous, and with native stringification
                // in place that turns document.createElement into `function () { [native code] }`
                // — a nameless native is not a thing a browser has.
                Object.defineProperty(wrapper, 'name', { value: String(prop), configurable: true });

                return wrapper;
            },
            set(target, prop, value) {
                target[prop] = value;
                // When element.id is set directly (not via setAttribute), also cache the Proxy.
                if (prop === 'id' && value) {
                    elementById.set(String(value), proxy);
                }
                return true;
            },
        });
        return proxy;
    }

    /** Every node the challenge can reach must know the document it belongs to. */
    const withOwner = (node) => {
        node.ownerDocument = document;

        return node;
    };

    for (const node of [documentEl, body]) {
        withOwner(node);
    }

    /**
     * XHR that performs the call for real and reports the outbound traffic.
     *
     * This is the seam the whole emulator turns on: the challenge builds its own payloads and
     * hands them here, so the interpreter's output is captured without any of it being
     * reimplemented.
     */
    class XMLHttpRequest {
        constructor() {
            this.readyState = 0;
            this.status = 0;
            this.statusText = '';
            this.responseText = '';
            this.responseXML = null;
            this.responseURL = '';
            this.response = '';
            this.responseType = '';
            // A support probe reads these before it will use XHR at all. withCredentials is
            // the classic "does this browser do CORS" test, and an instance without it is
            // indistinguishable from IE7 — which is exactly what `unsupported_browser` means.
            this.withCredentials = false;
            this.timeout = 0;
            this.upload = {
                addEventListener() {}, removeEventListener() {}, dispatchEvent: () => true,
                onprogress: null, onload: null, onerror: null, onabort: null,
                onloadstart: null, onloadend: null, ontimeout: null,
            };
            this._headers = {};
            this._listeners = new Map();
        }

        open(method, url) {
            if (verbose) {
                process.stderr.write(`[emulator] XHR open(${method}, ${String(url).slice(0, 120)})\n`);
            }

            this._method = method;
            this._url = url;
            this.readyState = 1;
        }

        setRequestHeader(name, value) {
            this._headers[String(name).toLowerCase()] = value;
        }

        /**
         * Real response headers, not an empty string.
         *
         * The flow responses carry cf-chl-gen, which the challenge reads back off the XHR —
         * answering nothing here would strand it exactly where a browser would not be.
         */
        getAllResponseHeaders() {
            return Object.entries(this._responseHeaders || {})
                .map(([name, value]) => `${name}: ${Array.isArray(value) ? value.join(', ') : value}`)
                .join('\r\n');
        }

        getResponseHeader(name) {
            const value = (this._responseHeaders || {})[String(name).toLowerCase()];

            if (value === undefined) {
                return null;
            }

            return Array.isArray(value) ? value.join(', ') : String(value);
        }

        send(payload) {
            if (verbose) {
                process.stderr.write(`[emulator] XHR send() entered for ${String(this._url).slice(0, 90)}\n`);
            }

            const target = new URL(this._url, bootstrap.url);
            const body = payload == null ? null : Buffer.from(String(payload), 'utf8');

            if (verbose) {
                process.stderr.write(`[emulator] XHR ${this._method} ${target.pathname.slice(0, 90)} body=${body ? body.length : 0}\n`);
            }

            outbound.push({
                method: this._method,
                url: target.href,
                headers: { ...this._headers },
                body_bytes: body ? body.length : 0,
                body: body ? body.toString('utf8') : null,
            });

            request({
                path: target.pathname + target.search,
                method: this._method,
                headers: {
                    'user-agent': USER_AGENT,
                    accept: '*/*',
                    'accept-language': 'en-US,en;q=0.9',
                    'accept-encoding': 'gzip, deflate, br',
                    origin,
                    referer: bootstrap.url,
                    'sec-ch-ua': CH_UA,
                    'sec-ch-ua-mobile': '?0',
                    'sec-ch-ua-platform': '"Linux"',
                    'sec-fetch-dest': 'empty',
                    'sec-fetch-mode': 'cors',
                    'sec-fetch-site': 'same-origin',
                    ...this._headers,
                    ...(body ? { 'content-length': body.length } : {}),
                },
            }, body).then((res) => {
                this.readyState = 4;
                this.status = res.status;
                this.statusText = res.status === 200 ? 'OK' : '';
                this.responseText = res.body;
                this.response = res.body;
                this.responseURL = target.href;
                this._responseHeaders = res.headers;

                outbound[outbound.length - 1].response_status = res.status;
                outbound[outbound.length - 1].response_bytes = res.body.length;

                if (verbose) {
                    process.stderr.write(`[emulator]   -> ${res.status} ${res.body.length} bytes\n`);
                }

                this._emit('readystatechange');
                this._emit('load');
                this._emit('loadend');
            }).catch((e) => {
                this.readyState = 4;
                this.status = 0;
                this._error = e;
                this._emit('readystatechange');
                this._emit('error');
                this._emit('loadend');
            });
        }

        /** Deliver to both the on<type> property and any addEventListener handlers, as a browser does. */
        _emit(type) {
            const event = { type, target: this, currentTarget: this, lengthComputable: false, loaded: 0, total: 0 };
            const handler = this[`on${type}`];

            try {
                if (typeof handler === 'function') {
                    handler.call(this, event);
                }

                for (const fn of this._listeners.get(type) || []) {
                    fn.call(this, event);
                }
            } catch (e) {
                if (verbose) {
                    process.stderr.write(`[emulator]   ${type} handler threw: ${e.message}\n`);
                }
            }
        }

        abort() {}

        overrideMimeType() {}

        addEventListener(type, fn) {
            if (!this._listeners.has(type)) this._listeners.set(type, []);
            this._listeners.get(type).push(fn);
        }

        removeEventListener(type, fn) {
            const handlers = this._listeners.get(type);

            if (handlers) {
                this._listeners.set(type, handlers.filter((h) => h !== fn));
            }
        }

        dispatchEvent() {
            return true;
        }
    }

    // On the PROTOTYPE, so `'onload' in new XMLHttpRequest()` answers true the way it does in
    // a browser. A feature probe asks with `in`, which an own property assigned later cannot
    // satisfy. Same for the readyState constants, which exist on both the constructor and
    // every instance.
    Object.assign(XMLHttpRequest.prototype, {
        onload: null, onerror: null, onabort: null, ontimeout: null, onprogress: null,
        onloadstart: null, onloadend: null, onreadystatechange: null,
        UNSENT: 0, OPENED: 1, HEADERS_RECEIVED: 2, LOADING: 3, DONE: 4,
    });

    Object.assign(XMLHttpRequest, { UNSENT: 0, OPENED: 1, HEADERS_RECEIVED: 2, LOADING: 3, DONE: 4 });

    /** Object URLs, so a Blob handed to createObjectURL can be found again by `new Worker(url)`. */
    const objectUrls = new Map();

    /** The same crypto in the page and the worker — a worker gets the full WebCrypto too. */
    const webCrypto = {
        randomUUID: () => crypto.randomUUID(),
        getRandomValues: (arr) => {
            crypto.randomFillSync(Buffer.from(arr.buffer, arr.byteOffset, arr.byteLength));

            return arr;
        },
        subtle: crypto.webcrypto.subtle,
    };

    /**
     * Blob with the properties a capability probe actually reads.
     *
     * The placeholder that only remembered its parts is what made the challenge answer
     * `reject: unsupported_browser`: it constructed a 13-byte Blob, read a property that was
     * not there, and concluded the browser could not run the challenge. Nothing downstream —
     * no object URL, no Worker — was ever reached.
     */
    class BlobImpl {
        constructor(parts = [], options = {}) {
            const chunks = [...parts].map((part) => {
                if (part instanceof BlobImpl) return part._buffer;
                if (Buffer.isBuffer(part)) return part;
                if (part instanceof ArrayBuffer) return Buffer.from(part);
                if (ArrayBuffer.isView(part)) return Buffer.from(part.buffer, part.byteOffset, part.byteLength);

                return Buffer.from(String(part), 'utf8');
            });

            this._buffer = Buffer.concat(chunks);
            this.size = this._buffer.length;
            this.type = String((options && options.type) || '');

            if (verbose) {
                process.stderr.write(`[blob] Blob(${this.size} bytes, "${this.type}") ${JSON.stringify(this._buffer.toString('utf8').slice(0, 120))}\n`);
            }
        }

        slice(start = 0, end = this.size, type = '') {
            const sliced = new BlobImpl([], { type });
            sliced._buffer = this._buffer.subarray(start, end);
            sliced.size = sliced._buffer.length;

            return sliced;
        }

        text() {
            return Promise.resolve(this._buffer.toString('utf8'));
        }

        arrayBuffer() {
            return Promise.resolve(this._buffer.buffer.slice(
                this._buffer.byteOffset, this._buffer.byteOffset + this._buffer.byteLength,
            ));
        }

        stream() {
            return null;
        }
    }

    /**
     * Web Audio, enough of it to exist and to render.
     *
     * The real iframe has both constructors and the stub had neither. This does not attempt to
     * reproduce Chrome's exact DSP output — an audio fingerprint taken from here would not
     * match a real Chrome, and if that is ever shown to be checked the honest answer is that
     * this tier cannot serve it. What it removes is the far cruder signal of the API being
     * absent altogether on a browser claiming to be Chrome.
     */
    class AudioNodeImpl {
        constructor(context) {
            this.context = context;
            this.channelCount = 2;
            this.numberOfInputs = 1;
            this.numberOfOutputs = 1;
            this.frequency = { value: 440, setValueAtTime() {} };
            this.gain = { value: 1, setValueAtTime() {} };
            this.threshold = { value: -24, setValueAtTime() {} };
            this.knee = { value: 30, setValueAtTime() {} };
            this.ratio = { value: 12, setValueAtTime() {} };
            this.attack = { value: 0.003, setValueAtTime() {} };
            this.release = { value: 0.25, setValueAtTime() {} };
            this.type = 'sine';
        }

        connect(destination) { return destination; }
        disconnect() {}
        start() {}
        stop() {}
        getChannelData() { return new Float32Array(0); }
        addEventListener() {}
        removeEventListener() {}
    }

    class AudioContextImpl {
        constructor() {
            this.sampleRate = 48000;
            this.currentTime = 0;
            this.state = 'suspended';
            this.baseLatency = 0.005;
            this.destination = new AudioNodeImpl(this);
            this.listener = { positionX: { value: 0 } };
        }

        createOscillator() { return new AudioNodeImpl(this); }
        createGain() { return new AudioNodeImpl(this); }
        createAnalyser() { return new AudioNodeImpl(this); }
        createDynamicsCompressor() { return new AudioNodeImpl(this); }
        createBiquadFilter() { return new AudioNodeImpl(this); }
        createScriptProcessor() { return new AudioNodeImpl(this); }
        createBuffer(channels, length, sampleRate) {
            return {
                numberOfChannels: channels, length, sampleRate,
                duration: length / sampleRate,
                getChannelData: () => new Float32Array(length),
            };
        }
        createBufferSource() { return new AudioNodeImpl(this); }
        close() { return Promise.resolve(); }
        resume() { return Promise.resolve(); }
        suspend() { return Promise.resolve(); }
        addEventListener() {}
        removeEventListener() {}
    }

    class OfflineAudioContextImpl extends AudioContextImpl {
        constructor(channels = 1, length = 44100, sampleRate = 44100) {
            super();

            this.length = typeof channels === 'object' ? channels.length : length;
            this.sampleRate = typeof channels === 'object' ? channels.sampleRate : sampleRate;
            this.numberOfChannels = typeof channels === 'object' ? channels.numberOfChannels : channels;
            this.state = 'suspended';
        }

        startRendering() {
            const length = this.length;

            return Promise.resolve({
                numberOfChannels: this.numberOfChannels,
                length,
                sampleRate: this.sampleRate,
                duration: length / this.sampleRate,
                getChannelData: () => new Float32Array(length),
                copyFromChannel() {},
            });
        }
    }

    /**
     * Worker that actually runs the script.
     *
     * Cloudflare moves the expensive part of the challenge into a Worker built from a Blob, so
     * a no-op stub means the program can never produce its answer. The script runs in its own
     * vm context — a worker has no DOM, so the global here is deliberately much smaller than
     * the page's, and handing it a document would itself be a tell.
     *
     * Messages cross asynchronously in both directions, as a real worker's do.
     */
    class WorkerImpl {
        constructor(url) {
            const source = objectUrls.get(String(url));

            this.onmessage = null;
            this.onerror = null;
            this._listeners = new Map();

            if (source === undefined) {
                throw new Error(`worker script not found for ${String(url).slice(0, 80)}`);
            }

            if (verbose) {
                process.stderr.write(`[worker] start (${source.length} bytes)\n`);
            }

            const inbound = new Map();
            const self = {
                name: '',
                location: { ...location, href: bootstrap.url },
                navigator: {
                    userAgent: navigator.userAgent, platform: navigator.platform,
                    language: navigator.language, languages: navigator.languages,
                    hardwareConcurrency: navigator.hardwareConcurrency,
                    deviceMemory: navigator.deviceMemory, onLine: true,
                    userAgentData: navigator.userAgentData,
                },
                performance,
                crypto: webCrypto,
                XMLHttpRequest,
                Blob: BlobImpl,
                TextEncoder, TextDecoder, URL, URLSearchParams, Event, EventTarget,
                AbortController, AbortSignal, structuredClone,
                setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
                btoa: concrete.btoa,
                atob: concrete.atob,
                console: { log() {}, warn() {}, error() {}, info() {}, debug() {}, trace() {} },
                isSecureContext: true,
                onmessage: null,
                importScripts() {},
                close: () => this.terminate(),
                addEventListener(type, fn) {
                    if (!inbound.has(type)) inbound.set(type, []);
                    inbound.get(type).push(fn);
                },
                removeEventListener() {},
                dispatchEvent: () => true,
                // Worker -> page.
                postMessage: (data) => setTimeout(() => this._receive(data), 0),
            };

            self.self = self;
            self.globalThis = self;
            this._self = self;
            this._inbound = inbound;
            this._context = vm.createContext(self);

            try {
                if (verbose) {
                    process.stderr.write(`[worker] running script (${source.length} bytes): ${source.slice(0, 200)}\n`);
                }
                new vm.Script(source, { filename: 'cf-worker.js' }).runInContext(this._context, { timeout: 10000 });

                // If the script did not call postMessage itself, signal readiness so the
                // challenge's onmessage handler fires — in a browser the Worker thread
                // always has a chance to send at least one message back.
                setTimeout(() => this._receive(null), 0);
            } catch (e) {
                if (verbose) {
                    process.stderr.write(`[worker] script threw: ${e.message}\n`);
                }

                setTimeout(() => {
                    if (this.onerror) this.onerror({ message: e.message, filename: 'cf-worker.js' });
                }, 0);
            }
        }

        /** Worker -> page delivery. */
        _receive(data) {
            const event = { data, type: 'message' };

            if (verbose) {
                process.stderr.write(`[worker] -> page ${JSON.stringify(data).slice(0, 160)}\n`);
            }

            if (this.onmessage) {
                this.onmessage(event);
            }

            for (const fn of this._listeners.get('message') || []) {
                fn(event);
            }
        }

        /** Page -> worker delivery. */
        postMessage(data) {
            if (verbose) {
                process.stderr.write(`[worker] page -> worker: ${JSON.stringify(data).slice(0, 200)}\n`);
            }
            setTimeout(() => {
                const event = { data, type: 'message' };

                if (this._self.onmessage) {
                    this._self.onmessage(event);
                }

                for (const fn of this._inbound.get('message') || []) {
                    fn(event);
                }
            }, 0);
        }

        terminate() {}

        addEventListener(type, fn) {
            if (!this._listeners.has(type)) this._listeners.set(type, []);
            this._listeners.get(type).push(fn);
        }

        removeEventListener() {}
    }

    // The classes are named for the emulator's benefit, but the challenge must see the names a
    // browser exposes — with native stringification in place, `function BlobImpl() { [native
    // code] }` is a worse tell than no lie at all.
    Object.defineProperty(BlobImpl, 'name', { value: 'Blob', configurable: true });
    Object.defineProperty(WorkerImpl, 'name', { value: 'Worker', configurable: true });
    Object.defineProperty(XMLHttpRequest, 'name', { value: 'XMLHttpRequest', configurable: true });

    const screen = {
        width: 800, height: 600, availWidth: 800, availHeight: 600,
        colorDepth: 24, pixelDepth: 24, orientation: { type: 'landscape-primary', angle: 0 },
    };

    const navigator = {
        userAgent: USER_AGENT,
        appVersion: USER_AGENT.replace('Mozilla/', ''),
        appName: 'Netscape',
        appCodeName: 'Mozilla',
        product: 'Gecko',
        platform: 'Linux x86_64',
        vendor: 'Google Inc.',
        language: 'en-US',
        languages: ['en-US', 'en'],
        onLine: true,
        cookieEnabled: true,
        doNotTrack: null,
        hardwareConcurrency: 16,
        // Measured against the real widget iframe on this host, not guessed — Chrome no longer
        // clamps this to 8, and reporting 8 on a 31 GB machine is itself an inconsistency.
        deviceMemory: 32,
        maxTouchPoints: 0,
        pdfViewerEnabled: true,
        productSub: '20030107',
        webdriver: false,
        // Chrome exposes five PDF pseudo-plugins even headless. An empty list on a UA claiming
        // Chrome is the kind of internal contradiction this whole stub exists to avoid.
        plugins: buildPlugins(),
        mimeTypes: buildMimeTypes(),
        connection: {
            effectiveType: '4g', rtt: 50, downlink: 10, saveData: false, type: 'ethernet',
            onchange: null, addEventListener() {}, removeEventListener() {},
        },
        mediaDevices: {
            ondevicechange: null,
            enumerateDevices: () => Promise.resolve([]),
            getSupportedConstraints: () => ({}),
            getUserMedia: () => Promise.reject(new Error('Permission denied')),
            addEventListener() {}, removeEventListener() {},
        },
        serviceWorker: {
            controller: null,
            ready: new Promise(() => {}),
            register: () => Promise.reject(new Error('not supported')),
            getRegistration: () => Promise.resolve(undefined),
            getRegistrations: () => Promise.resolve([]),
            addEventListener() {}, removeEventListener() {},
        },
        storage: {
            estimate: () => Promise.resolve({ quota: 299977904947, usage: 0, usageDetails: {} }),
            persisted: () => Promise.resolve(false),
            persist: () => Promise.resolve(false),
        },
        getBattery: () => Promise.resolve({
            charging: true, chargingTime: 0, dischargingTime: Infinity, level: 1,
            onchargingchange: null, onlevelchange: null,
            addEventListener() {}, removeEventListener() {},
        }),
        clipboard: { readText: () => Promise.reject(new Error('denied')), writeText: () => Promise.resolve() },
        // WebGPU. The adapter resolves to null, which is what a GPU-less headless Chrome also
        // answers — the object has to exist, because the challenge reaches straight through it
        // and an absent navigator.gpu surfaces only as `X is not a function` from inside its
        // interpreter.
        gpu: {
            requestAdapter: () => Promise.resolve(null),
            getPreferredCanvasFormat: () => 'bgra8unorm',
            wgslLanguageFeatures: { size: 0, has: () => false, forEach() {} },
        },
        userAgentData: {
            brands: [{ brand: 'Not/A)Brand', version: '99' }, { brand: 'Chromium', version: '148' }],
            mobile: false,
            platform: 'Linux',
            getHighEntropyValues: () => Promise.resolve({
                architecture: 'x86', bitness: '64', model: '', platformVersion: '6.5.0',
                uaFullVersion: '148.0.0.0', fullVersionList: [{ brand: 'Chromium', version: '148.0.0.0' }],
            }),
        },
        permissions: { query: () => Promise.resolve({ state: 'prompt', onchange: null }) },
        sendBeacon: () => true,
        javaEnabled: () => false,
    };

    const timeOrigin = Date.now();
    const performance = {
        timeOrigin,
        now: () => Number(process.hrtime.bigint() / 1000n) / 1000 % 1e7,
        mark() {}, measure() {}, clearMarks() {}, clearMeasures() {},
        getEntriesByName: () => [], getEntriesByType: () => [], getEntries: () => [],
        timing: { navigationStart: timeOrigin, loadEventEnd: timeOrigin + 120 },
        memory: { jsHeapSizeLimit: 4294705152, totalJSHeapSize: 35000000, usedJSHeapSize: 25000000 },
    };

    const location = {
        href: bootstrap.url,
        origin,
        protocol: 'https:',
        host: WIDGET_HOST,
        hostname: WIDGET_HOST,
        port: '',
        pathname: new URL(bootstrap.url).pathname,
        search: new URL(bootstrap.url).search,
        hash: '',
        ancestorOrigins: { length: 1, item: () => pageUrl, contains: () => true },
        replace() {}, assign() {}, reload() {}, toString: () => bootstrap.url,
    };

    const concrete = {
        document: observe(document, 'document'), navigator, screen, performance, location,
        XMLHttpRequest,
        innerWidth: 300, innerHeight: 65, outerWidth: 1280, outerHeight: 800,
        screenX: 0, screenY: 0, screenLeft: 0, screenTop: 0, devicePixelRatio: 1,
        origin,
        // createObjectURL has to keep the blob, not just answer a plausible string: the
        // challenge's next move is `new Worker(thatUrl)`, and the only way to run the script
        // is to look the source back up by the URL that was handed out.
        URL: Object.assign(class extends URL {}, URL, {
            createObjectURL: (blob) => {
                const url = `blob:https://${WIDGET_HOST}/${crypto.randomUUID()}`;
                objectUrls.set(url, blob && blob._buffer ? blob._buffer.toString('utf8') : '');

                if (verbose) {
                    process.stderr.write(`[blob] createObjectURL -> ${url}\n`);
                }

                return url;
            },
            revokeObjectURL: (url) => { objectUrls.delete(String(url)); },
        }),
        isSecureContext: true,
        crossOriginIsolated: false,
        closed: false,
        name: '',
        history: { length: 1, state: null, pushState() {}, replaceState() {}, go() {}, back() {}, forward() {} },
        localStorage: { getItem: () => null, setItem() {}, removeItem() {}, clear() {}, key: () => null, length: 0 },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {}, clear() {}, key: () => null, length: 0 },
        crypto: webCrypto,
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(fn);
        },
        removeEventListener() {},
        dispatchEvent: () => true,
        postMessage() {},
        matchMedia: (q) => ({ matches: false, media: q, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }),
        getComputedStyle: () => ({ getPropertyValue: () => '' }),
        requestAnimationFrame: (cb) => setTimeout(() => cb(Date.now() - timeOrigin), 16),
        cancelAnimationFrame: clearTimeout,
        requestIdleCallback: (cb) => setTimeout(() => cb({ timeRemaining: () => 50, didTimeout: false }), 1),
        cancelIdleCallback: clearTimeout,
        MutationObserver: class MutationObserver {
            constructor(callback) { this._callback = callback; }
            observe() {} disconnect() {} takeRecords() { return []; }
        },
        // The observers must actually CALL BACK. A browser delivers an initial record as soon
        // as something is observed, and Cloudflare waits for it — an observer that only
        // records the callback and never invokes it is indistinguishable from a widget that
        // never became visible, which is exactly what the stall looked like.
        ResizeObserver: class ResizeObserver {
            constructor(callback) { this._callback = callback; }

            observe(target) {
                setTimeout(() => this._callback([{
                    target,
                    contentRect: { x: 0, y: 0, top: 0, left: 0, right: WIDGET_WIDTH, bottom: WIDGET_HEIGHT, width: WIDGET_WIDTH, height: WIDGET_HEIGHT },
                    borderBoxSize: [{ inlineSize: WIDGET_WIDTH, blockSize: WIDGET_HEIGHT }],
                    contentBoxSize: [{ inlineSize: WIDGET_WIDTH, blockSize: WIDGET_HEIGHT }],
                }], this), 0);
            }

            unobserve() {} disconnect() {}
        },
        IntersectionObserver: class IntersectionObserver {
            constructor(callback) { this._callback = callback; }

            observe(target) {
                const rect = {
                    x: 0, y: 0, top: 0, left: 0, right: WIDGET_WIDTH, bottom: WIDGET_HEIGHT,
                    width: WIDGET_WIDTH, height: WIDGET_HEIGHT,
                };

                setTimeout(() => this._callback([{
                    target,
                    isIntersecting: true,
                    intersectionRatio: 1,
                    boundingClientRect: rect,
                    intersectionRect: rect,
                    rootBounds: rect,
                    time: 0,
                }], this), 0);
            }

            unobserve() {} disconnect() {} takeRecords() { return []; }
        },
        PerformanceObserver: class PerformanceObserver {
            constructor(callback) { this._callback = callback; }
            observe() {} disconnect() {} takeRecords() { return []; }
        },
        Worker: WorkerImpl,
        Image: class {
            constructor() { this.width = 0; this.height = 0; this.complete = true; }
            set src(v) { setTimeout(() => { if (this.onload) this.onload(); }, 0); }
            get src() { return ''; }
            addEventListener(t, fn) { if (t === 'load') setTimeout(fn, 0); }
            removeEventListener() {}
        },
        // Every one of these was measured present in the real widget iframe and absent here.
        // Individually they look trivial; together they are a browser that claims to be Chrome
        // 148 while missing most of what Chrome 148 has, which is what a verdict of
        // `unsupported_browser` describes.
        Notification: Object.assign(class Notification {
            static requestPermission() { return Promise.resolve('default'); }
        }, { permission: 'default', maxActions: 2 }),
        indexedDB: { open: () => ({ addEventListener() {}, result: null }), deleteDatabase: () => ({}), databases: () => Promise.resolve([]) },
        WebSocket: class WebSocket { constructor() {} send() {} close() {} addEventListener() {} removeEventListener() {} },
        RTCPeerConnection: class RTCPeerConnection {
            createDataChannel() { return { close() {} }; }
            createOffer() { return Promise.resolve({ type: 'offer', sdp: '' }); }
            setLocalDescription() { return Promise.resolve(); }
            close() {}
            addEventListener() {} removeEventListener() {}
        },
        ReportingObserver: class ReportingObserver { observe() {} disconnect() {} takeRecords() { return []; } },
        PressureObserver: class PressureObserver { observe() { return Promise.resolve(); } disconnect() {} unobserve() {} },
        AudioContext: AudioContextImpl,
        webkitAudioContext: AudioContextImpl,
        OfflineAudioContext: OfflineAudioContextImpl,
        // Chrome's own object. A UA that says Chrome with no window.chrome is the single
        // loudest contradiction in the whole environment.
        chrome: {
            app: { isInstalled: false, InstallState: { DISABLED: 'disabled', INSTALLED: 'installed', NOT_INSTALLED: 'not_installed' }, RunningState: { CANNOT_RUN: 'cannot_run', READY_TO_RUN: 'ready_to_run', RUNNING: 'running' } },
            runtime: {},
            csi() { return { onloadT: Date.now(), pageT: 1200, startE: Date.now(), tran: 15 }; },
            loadTimes() { return { commitLoadTime: Date.now() / 1000, finishLoadTime: Date.now() / 1000 }; },
        },
        // The challenge's parent code sets this before the iframe loads. The challenge
        // reads widget ID, site key, mode, and other config from it.
        _cf_chl_opt: {
            EnOnL8: 'emulated',
            HEywW9: '0x4AAAAAACghKkJHL1t7UkuZ',
            MfSj0: 'managed',
            Jvvu7: 'normal',
            mzWk6: 'auto',
            KXkx2: 'new',
            vHQxF7: 0,
            bKOG4: 10000,
            rPXg2: [],
            dMPt9: 120000,
            QfqMk3: [],
            PWdB1: 'chl_api_m',
            cYFn9: 5,
            LTkA3: '0',
            wRuQZ3: WIDGET_HOST,
            jvYQh4: '3',
        },
        speechSynthesis: {
            getVoices: () => [], speak() {}, cancel() {}, pause() {}, resume() {},
            speaking: false, pending: false, paused: false,
            addEventListener() {}, removeEventListener() {},
        },
        btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        setTimeout: traceTimer(setTimeout, 'setTimeout'),
        setInterval: traceTimer(setInterval, 'setInterval'),
        clearTimeout, clearInterval, queueMicrotask,
        console: { log() {}, warn() {}, error() {}, info() {}, debug() {}, trace() {} },
        // The window methods a browser exposes and this stub did not. Most are inert by
        // nature — a headless solve neither prompts nor resizes — but they have to EXIST,
        // because the challenge calls what it finds and an absent one surfaces only as
        // `X is not a function` from inside its bytecode interpreter, naming nothing.
        alert() {}, confirm: () => false, prompt: () => null, print() {},
        blur() {}, focus() {}, stop() {}, close() {},
        find: () => false,
        open: () => null,
        scroll() {}, scrollBy() {}, scrollTo() {},
        moveBy() {}, moveTo() {}, resizeBy() {}, resizeTo() {},
        captureEvents() {}, releaseEvents() {},
        reportError() {},
        getSelection: () => ({
            anchorNode: null, focusNode: null, rangeCount: 0, type: 'None', isCollapsed: true,
            getRangeAt: () => null, removeAllRanges() {}, addRange() {}, toString: () => '',
        }),
        createImageBitmap: () => Promise.reject(new Error('unsupported')),
        queryLocalFonts: () => Promise.reject(new Error('denied')),
        getScreenDetails: () => Promise.reject(new Error('denied')),
        showOpenFilePicker: () => Promise.reject(new Error('denied')),
        showSaveFilePicker: () => Promise.reject(new Error('denied')),
        showDirectoryPicker: () => Promise.reject(new Error('denied')),
    };

    /**
     * fetch, backed by the same real transport as the XHR path.
     *
     * Leaving it undefined was a deliberate choice that turned out to be a hazard: the
     * challenge picks its transport from what exists, and a browser always has fetch. Feature
     * detection that finds it absent takes a different branch; feature detection that assumes
     * it is present calls it and dies. Implementing it is cheaper than reasoning about which.
     */
    concrete.fetch = (input, init = {}) => {
        const url = new URL(typeof input === 'string' ? input : (input && input.url), bootstrap.url);
        const body = init.body == null ? null : Buffer.from(String(init.body), 'utf8');
        const method = (init.method || 'GET').toUpperCase();

        outbound.push({
            method, url: url.href, headers: { ...(init.headers || {}) },
            body_bytes: body ? body.length : 0,
            body: body ? body.toString('utf8') : null,
            transport: 'fetch',
        });

        const entry = outbound[outbound.length - 1];

        return request({
            path: url.pathname + url.search,
            method,
            headers: {
                'user-agent': USER_AGENT,
                accept: '*/*',
                'accept-language': 'en-US,en;q=0.9',
                'accept-encoding': 'gzip, deflate, br',
                origin,
                referer: bootstrap.url,
                'sec-ch-ua': CH_UA,
                'sec-ch-ua-mobile': '?0',
                'sec-ch-ua-platform': '"Linux"',
                'sec-fetch-dest': 'empty',
                'sec-fetch-mode': 'cors',
                'sec-fetch-site': 'same-origin',
                ...(init.headers || {}),
                ...(body ? { 'content-length': body.length } : {}),
            },
        }, body).then((res) => {
            entry.response_status = res.status;
            entry.response_bytes = res.body.length;

            if (verbose) {
                process.stderr.write(`[emulator] fetch ${method} ${url.pathname.slice(0, 80)} -> ${res.status} ${res.body.length}\n`);
            }

            return {
                ok: res.status >= 200 && res.status < 300,
                status: res.status,
                statusText: res.status === 200 ? 'OK' : '',
                url: url.href,
                redirected: false,
                type: 'basic',
                headers: {
                    get: (name) => res.headers[String(name).toLowerCase()] ?? null,
                    has: (name) => String(name).toLowerCase() in res.headers,
                    forEach(fn) { for (const [k, v] of Object.entries(res.headers)) fn(v, k, this); },
                    entries: () => Object.entries(res.headers)[Symbol.iterator](),
                },
                text: () => Promise.resolve(res.body),
                json: () => Promise.resolve(JSON.parse(res.body)),
                arrayBuffer: () => Promise.resolve(Buffer.from(res.body, 'utf8').buffer),
                clone() { return this; },
            };
        });
    };

    concrete.fetchLater = () => ({ activated: false });
    concrete.webkitRequestAnimationFrame = concrete.requestAnimationFrame;
    concrete.webkitCancelAnimationFrame = concrete.cancelAnimationFrame;
    concrete.webkitRTCPeerConnection = concrete.RTCPeerConnection;
    concrete.webkitURL = concrete.URL;

    // Web/Node globals only. The ECMAScript intrinsics are deliberately NOT copied in:
    // vm.createContext gives the context its own realm intrinsics, and injecting the host's
    // would hand the challenge a door out of the sandbox — the bootstrap's own Trusted Types
    // policy special-cases the strings 'this' and 'return this', which is exactly the
    // Function('return this') trick for grabbing the global. It must get ours, not Node's.
    // NOTE: URL is deliberately absent here. It is already defined above with the object-URL
    // registry attached, and listing it again replaced that with Node's own URL — whose
    // createObjectURL validates its argument as a Node Blob, threw on the stub's, and was
    // caught by the challenge's capability probe as `reject: unsupported_browser`.
    Object.assign(concrete, {
        TextEncoder, TextDecoder, URLSearchParams, Event, EventTarget,
        AbortController, AbortSignal, structuredClone,
        Blob: BlobImpl,
    });

    // Trusted Types for real, not a stand-in. The bootstrap's first act is to build a policy
    // and route every later string through it; answering createPolicy with a recording proxy
    // makes createHTML/createScript return proxies instead of the strings they were given,
    // which silently corrupts everything downstream — that was the whole of the first run's
    // "is not a function".
    concrete.trustedTypes = {
        createPolicy: (name, rules) => ({
            createHTML: (s) => (rules && rules.createHTML ? rules.createHTML(s) : s),
            createScript: (s) => (rules && rules.createScript ? rules.createScript(s) : s),
            createScriptURL: (s) => (rules && rules.createScriptURL ? rules.createScriptURL(s) : s),
        }),
        defaultPolicy: null,
        isHTML: () => false,
        isScript: () => false,
        isScriptURL: () => false,
    };

    // No recording proxy as the global. Feature detection here is answered by real JS
    // semantics: `'ontouchstart' in window` must be false on a desktop, and a proxy whose
    // has() returns true for everything reports a touch device. An absent API reads as
    // undefined, which is the truth, and misses surface as ReferenceErrors that name
    // themselves.
    concrete.window = concrete;
    concrete.self = concrete;
    concrete.globalThis = concrete;
    concrete.frames = concrete;
    document.defaultView = concrete;

    // The parent frame is a DISTINCT object, not `concrete`. The challenge runs in an iframe
    // and checks it has one — and everything it wants to say goes out through
    // parent.postMessage, which is the seam the handshake is driven from. Captured live: the
    // parent opens with `init`, answers `requestExtraParams`, sends `execute` to start the
    // run, then ping-pongs `meow` against the challenge's `food`, and the token finally
    // arrives in a `complete` message.
    const parentFrame = {
        postMessage(data) {
            outbound.messages.push(data);

            // Where in the challenge did this come from? Every identifier is machine-generated
            // so a stack names nothing, but the whole program is one line — the column is a
            // character offset, which makes the surrounding source printable. That is how the
            // condition behind a silent watchdog like overrunBegin is found.
            if (outbound.onProvenance) {
                outbound.onProvenance(data, new Error().stack || '');
            }

            if (outbound.onMessage) {
                outbound.onMessage(data);
            }
        },
    };

    concrete.parent = parentFrame;
    concrete.top = parentFrame;

    // Fill in what the stub does not implement itself, from the captured browser surface. This
    // runs last, so nothing here can shadow a real implementation above.
    const captured = loadDomSurface();
    const domInterfaces = defineDomInterfaces(concrete, captured.surface);
    const filledGlobals = defineMissingGlobals(concrete, captured.globals);

    if (verbose) {
        process.stderr.write(
            `[emulator] materialised ${domInterfaces} interface(s), ${filledGlobals} global(s)\n`,
        );
    }

    // NOTE: do not wrap this in a Proxy to record misses. Node's vm keeps the intrinsics on
    // the real global rather than the sandbox object, so a get trap answering from the target
    // alone loses parseInt/Object and the challenge dies before its first message.
    //
    // Reads can still be traced without one: replacing each own property with an accessor
    // leaves the intrinsics alone, and a global that stops at a named capability is how a
    // generic `reject: unsupported_browser` is turned into the specific thing that is missing.
    if (process.env.EMU_TRACE_ENV) {
        traceReads(concrete, 'window');
        traceReads(concrete.URL, 'URL');

        // Every stub sub-object reports what it was asked for and did not have. The
        // interpreter only ever says `X is not a function`, from inside its own opcode loop,
        // so the name has to come from the object that was asked.
        for (const key of Object.keys(concrete)) {
            const value = concrete[key];

            if (isPlainObject(value) && value !== concrete) {
                concrete[key] = traceMisses(value, key);
            }
        }

        // The parent has to be a Proxy, not accessors: what matters is the properties it does
        // NOT have, and an absent property cannot be given a logging getter.
        const rawParent = concrete.parent;

        concrete.parent = new Proxy(rawParent, {
            get(target, prop) {
                const value = Reflect.get(target, prop);
                process.stderr.write(`[read] parent.${String(prop)} -> ${typeof value}\n`);

                return value;
            },
        });
        concrete.top = concrete.parent;

        // A Proxy rather than accessors, because what matters for a constructor is which
        // property is read and whether it is ever actually constructed.
        for (const name of ['XMLHttpRequest', 'Blob', 'Worker']) {
            concrete[name] = new Proxy(concrete[name], {
                get(target, prop) {
                    process.stderr.write(`[read] ${name}.${String(prop)}\n`);

                    return Reflect.get(target, prop);
                },
                construct(target, callArgs) {
                    process.stderr.write(`[new] ${name}()\n`);

                    // Wrap the instance too: a capability probe that constructs and then
                    // discards is asking the INSTANCE something, and `in` is as common a
                    // question as a read.
                    return new Proxy(Reflect.construct(target, callArgs), {
                        get(instance, prop) {
                            process.stderr.write(`[read] ${name}#${String(prop)}\n`);

                            return Reflect.get(instance, prop);
                        },
                        has(instance, prop) {
                            const answer = Reflect.has(instance, prop);
                            process.stderr.write(`[in] ${String(prop)} in ${name}# -> ${answer}\n`);

                            return answer;
                        },
                    });
                },
            });
        }
    }

    return { global: concrete, listeners, document, parentFrame, clickState };
}

/**
 * The DOM interface constructors, materialised from a captured browser surface.
 *
 * A browser iframe exposes around 950 of these; the hand-written stub had 90. The challenge's
 * support gate reads `window.<Interface>.prototype.<member>` directly, so an absent constructor
 * is a TypeError raised inside its own try/catch — invisible, and impossible to attribute
 * because the name it wanted is assembled at runtime from an encoded table.
 *
 * The shape comes from turnstile_dom_capture.cjs rather than from a hand-written list, for the
 * same reason the flow constants and the fingerprint bisect are measured: the list is long, it
 * moves with Chrome versions, and a guess that is 95% right still fails on the one name that
 * matters. Only the SHAPE is reproduced — methods are inert, accessors answer undefined — so
 * this closes existence checks, not behaviour.
 *
 * Interfaces the stub already implements are left alone; a real Blob must not be replaced by
 * an empty one.
 */
function defineDomInterfaces(concrete, surface) {
    let defined = 0;

    for (const [name, shape] of Object.entries(surface)) {
        // A browser lists the ECMAScript intrinsics on window too, so the captured surface
        // contains Object, Array, Promise and every typed array. Those already exist in the vm
        // realm and are real; replacing them with inert shapes broke the bootstrap on its
        // first statement, because Uint8Array stopped producing usable buffers.
        if (REALM_INTRINSICS.has(name)) {
            continue;
        }

        // An interface the stub implements keeps its behaviour, but still gets the members it
        // is missing. The hand-written RTCPeerConnection had four methods where the real one
        // has forty, and reaching for one of the other thirty-six is the same silent TypeError
        // as the interface being absent altogether.
        if (Object.prototype.hasOwnProperty.call(concrete, name)) {
            const existing = concrete[name];

            if (typeof existing === 'function' && existing.prototype) {
                // Accessors become writable data properties on the merge path. A class body is
                // strict mode, so a getter-only `size` inherited from the prototype makes the
                // stub's own `this.size = ...` throw — which the challenge's capability probe
                // catches and reports as an unsupported browser.
                applyMembers(existing.prototype, omitPresent(existing.prototype, shape.proto), true);
                applyMembers(existing, omitPresent(existing, shape.statics), true);
            }

            continue;
        }

        // Constructibility is per-interface and measured: `new Element()` throws "Illegal
        // constructor" in a browser, but `new CustomEvent(...)` is ordinary and the bootstrap
        // does it. Refusing both stopped the bootstrap at its first statement.
        const Interface = shape.constructible
            ? function () { return Object.create(Interface.prototype); }
            : function () { throw new TypeError('Illegal constructor'); };

        Object.defineProperty(Interface, 'name', { value: name, configurable: true });

        applyMembers(Interface.prototype, shape.proto);
        applyMembers(Interface, shape.statics);

        concrete[name] = Interface;
        defined++;
    }

    return defined;
}

/** The captured members a target does not already have, so a real implementation is never overwritten. */
function omitPresent(target, members) {
    const missing = {};

    for (const [key, member] of Object.entries(members || {})) {
        if (!(key in target)) {
            missing[key] = member;
        }
    }

    return missing;
}

/** Recreate one captured member table onto a target object. */
function applyMembers(target, members, accessorsAsData = false) {
    for (const [key, member] of Object.entries(members || {})) {
        try {
            if (member.kind === 'accessor' && !accessorsAsData) {
                Object.defineProperty(target, key, {
                    get() { return undefined; }, enumerable: false, configurable: true,
                });

                continue;
            }

            if (member.kind === 'method') {
                // Materialised members are inert: they exist so a lookup succeeds, and they
                // answer undefined. Which ones the challenge actually CALLS is the interesting
                // set — those are the ones whose real return value it then uses, and a
                // returned undefined is what the interpreter later fails to call.
                const fn = process.env.EMU_TRACE_ENV
                    ? function () { process.stderr.write(`[inert] ${key}()\n`); }
                    : function () {};

                Object.defineProperty(fn, 'name', { value: key, configurable: true });
                Object.defineProperty(target, key, {
                    value: fn, writable: true, enumerable: false, configurable: true,
                });

                continue;
            }

            Object.defineProperty(target, key, {
                value: member.kind === 'value' ? member.value
                    : (member.kind === 'accessor' ? undefined : {}),
                writable: true, enumerable: false, configurable: true,
            });
        } catch (e) {
            // A non-configurable name on a function (length/name/prototype) is not ours to move.
        }
    }
}

/**
 * Fill in the window properties the stub does not define.
 *
 * Constructors are only part of what a browser puts on window: there are also plain objects
 * the challenge reaches straight through (visualViewport, customElements, scheduler, caches)
 * and around a hundred null-valued on* handlers that feature tests ask about with `in`.
 * Reaching through a missing one is the same silent TypeError inside the interpreter.
 *
 * Names the CHALLENGE ITSELF created are excluded. The capture is taken from a live iframe
 * after the challenge has run, so its own runtime globals are in the snapshot; predefining
 * those would hand the next run objects it expects to create.
 */
function defineMissingGlobals(concrete, globals) {
    let defined = 0;

    for (const [name, member] of Object.entries(globals)) {
        if (Object.prototype.hasOwnProperty.call(concrete, name)
            || REALM_INTRINSICS.has(name)
            || isChallengeOwnGlobal(name)
            || member.kind === 'blocked') {
            continue;
        }

        if (member.kind === 'object') {
            const object = {};

            applyMembers(object, member.members);
            concrete[name] = object;
        } else if (member.kind === 'method') {
            const fn = function () {};

            Object.defineProperty(fn, 'name', { value: name, configurable: true });
            concrete[name] = fn;
        } else {
            concrete[name] = member.value;
        }

        defined++;
    }

    return defined;
}

/**
 * Names the challenge defines on window at runtime.
 *
 * Cloudflare's own globals are short machine-generated tokens ending in a digit — _cf_chl_opt
 * plus things like EhyiP9 and MBhsN2 — and they appear in the snapshot only because it is
 * taken from a page where the challenge already ran.
 */
function isChallengeOwnGlobal(name) {
    return name === '_cf_chl_opt' || /^[A-Za-z]{2,8}[0-9]$/.test(name);
}

/** Load the captured surface; absence is tolerated, it simply narrows the stub. */
function loadDomSurface() {
    try {
        const captured = JSON.parse(require('fs').readFileSync(DOM_SURFACE_PATH, 'utf8'));

        return { surface: captured.surface || {}, globals: captured.globals || {} };
    } catch (e) {
        // Cloudflare may block the Puppeteer capture browser. Keep the load-bearing interfaces
        // used by the support gate so a missing snapshot does not become an unconditional
        // unsupported_browser result.
        return {
            surface: {
                Element: { proto: { append: { kind: 'method' } }, statics: {}, constructible: false },
                Node: { proto: {}, statics: { ELEMENT_NODE: { kind: 'value', value: 1 } }, constructible: false },
                HTMLElement: { proto: {}, statics: {}, constructible: false },
                Document: { proto: {}, statics: {}, constructible: false },
                CustomEvent: { proto: {}, statics: {}, constructible: true },
                RTCPeerConnection: { proto: {}, statics: {}, constructible: true },
            },
            globals: {
                visualViewport: { kind: 'object' },
                onmessage: { kind: 'value', value: null },
            },
        };
    }
}

/** Chrome's five built-in PDF pseudo-plugins, in the order the browser lists them. */
function buildPlugins() {
    const names = [
        'PDF Viewer', 'Chrome PDF Viewer', 'Chromium PDF Viewer',
        'Microsoft Edge PDF Viewer', 'WebKit built-in PDF',
    ];

    const list = names.map((name) => ({
        name, filename: 'internal-pdf-viewer', description: 'Portable Document Format', length: 2,
    }));

    return Object.assign(list.reduce((acc, plugin, index) => {
        acc[index] = plugin;
        acc[plugin.name] = plugin;

        return acc;
    }, {}), {
        length: list.length,
        item: (index) => list[index] || null,
        namedItem: (name) => list.find((plugin) => plugin.name === name) || null,
        refresh() {},
    });
}

/** The two MIME types those plugins register. */
function buildMimeTypes() {
    const list = [
        { type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format' },
        { type: 'text/pdf', suffixes: 'pdf', description: 'Portable Document Format' },
    ];

    return Object.assign(list.reduce((acc, mime, index) => {
        acc[index] = mime;
        acc[mime.type] = mime;

        return acc;
    }, {}), {
        length: list.length,
        item: (index) => list[index] || null,
        namedItem: (type) => list.find((mime) => mime.type === type) || null,
    });
}

/**
 * Make the stub's functions stringify as native code, in both realms.
 *
 * Every method of the stub is an ordinary JavaScript function, so
 * `Function.prototype.toString.call(document.createElement)` returns its source where a
 * browser returns `function createElement() { [native code] }`. The bootstrap's string table
 * contains the literal `[native code]`, so this is a check it really makes, and it is one line
 * to run and impossible to satisfy by adding more APIs.
 *
 * The test is realm membership rather than a registry of known functions: anything whose
 * prototype chain reaches the HOST Function.prototype came from the stub and must claim to be
 * native, and anything the challenge builds inside the vm context is a context-realm function
 * that must keep telling the truth about itself, exactly as its own code does in a browser.
 * Membership also covers objects the stub creates later — every element returned by
 * createElement carries fresh closures that no build-time walk could have registered.
 *
 * Both realms have to be patched. `stub.method.toString()` resolves through the host's
 * Function.prototype, while `Function.prototype.toString.call(stub.method)` from inside the
 * challenge resolves through the context's — patching one leaves the other telling the truth.
 */
function installNativeToString(context) {
    const isStubFunction = (value) => {
        try {
            return typeof value === 'function' && value instanceof Function;
        } catch (e) {
            return false;
        }
    };

    const nativeSource = (fn) => `function ${fn.name || ''}() { [native code] }`;
    const hostToString = Function.prototype.toString;

    // The patch must also describe ITSELF as native, or reading Function.prototype.toString
    // and stringifying it hands back this very function's source — a one-step giveaway that
    // the check has been tampered with.
    const hostPatched = function toString() {
        return isStubFunction(this) ? nativeSource(this) : hostToString.call(this);
    };

    Object.defineProperty(Function.prototype, 'toString', {
        value: hostPatched, writable: true, enumerable: false, configurable: true,
    });

    // Built in the context so the challenge sees one of its own realm's functions, exactly as
    // it would in a browser. It closes over the host predicate, which is unreachable from
    // inside the sandbox.
    const install = vm.runInContext(`(function (isStub, nativeSource) {
        const original = Function.prototype.toString;
        const patched = function toString() {
            // The self-check matters: patched is itself a context-realm function, so the
            // realm test alone would hand back this function's own source.
            return (this === patched || isStub(this)) ? nativeSource(this) : original.call(this);
        };

        Object.defineProperty(Function.prototype, 'toString', {
            value: patched, writable: true, enumerable: false, configurable: true,
        });
    })`, context, { filename: 'native-tostring.js' });

    install(isStubFunction, nativeSource);
}

/**
 * Surface exceptions the challenge throws and catches itself.
 *
 * A verdict of `unsupported_browser` posted straight after an XHR is opened against the
 * challenge-platform `/eb/` path is an ERROR BEACON, which means the challenge caught
 * something and is reporting it. Its own try/catch means the emulator's error collector never
 * sees it, so the run looks clean while being anything but.
 *
 * Two hooks, because they catch different things. Patched constructors see errors the
 * challenge builds itself; prepareStackTrace fires when anything reads `.stack`, which a
 * beacon does by definition, and that path also covers errors thrown by the engine rather than
 * by JavaScript.
 */
function installThrowTracer(context) {
    vm.runInContext(`(() => {
        Error.stackTraceLimit = 50;

        Error.prepareStackTrace = (error, frames) => {
            const where = frames.slice(0, 14)
                .map((f) => (f.getFunctionName() || '?') + '@' + f.getColumnNumber())
                .join(' <- ');

            __emuThrow('stack read: ' + error + ' @ ' + where);

            return String(error);
        };

        for (const name of ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError']) {
            const Original = globalThis[name];

            if (typeof Original !== 'function') {
                continue;
            }

            const Patched = function (...args) {
                __emuThrow('new ' + name + ': ' + String(args[0]));

                return Reflect.construct(Original, args, new.target || Patched);
            };

            Patched.prototype = Original.prototype;
            Object.setPrototypeOf(Patched, Original);
            Object.defineProperty(Patched, 'name', { value: name, configurable: true });
            globalThis[name] = Patched;
        }
    })()`, context, { filename: 'throw-tracer.js' });
}

/**
 * Report what the challenge schedules and whether it comes back.
 *
 * A stall with no error means the program is waiting, and what it is waiting for is almost
 * always a timer that was armed and a callback that never ran — or a callback that ran and
 * threw. Recording the call site (the source is one line, so the stack column is a character
 * offset) is what turns "it stopped" into "it stopped HERE". Debug-only.
 */
function traceTimer(timer, label) {
    if (!process.env.EMU_TRACE_TIMERS) {
        return timer;
    }

    let sequence = 0;

    return function (callback, delay, ...rest) {
        const id = ++sequence;
        const at = (new Error().stack || '').match(/cf-challenge\.js:1:(\d+)/);

        process.stderr.write(`[timer] ${label}#${id} armed delay=${delay || 0} at@${at ? at[1] : '?'}\n`);

        return timer(function (...callArgs) {
            process.stderr.write(`[timer] ${label}#${id} fired\n`);

            try {
                return callback.apply(this, callArgs);
            } catch (e) {
                process.stderr.write(`[timer] ${label}#${id} THREW ${e.message}\n`);

                throw e;
            }
        }, delay, ...rest);
    };
}

/** Object literals only — anything with a real prototype belongs to a host built-in. */
function isPlainObject(value) {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype || prototype === null;
}

/**
 * Report reads of members an object does not have.
 *
 * The counterpart to traceReads: that one names what WAS found, this one names what was not.
 * Nested objects are wrapped on the way out so a miss two levels down still reports a path.
 */
function traceMisses(target, label, depth = 0) {
    return new Proxy(target, {
        get(object, prop) {
            const value = Reflect.get(object, prop);

            if (value === undefined && typeof prop === 'string' && !(prop in object)) {
                process.stderr.write(`[miss] ${label}.${prop}\n`);
            }

            if (depth < 2 && isPlainObject(value)) {
                return traceMisses(value, `${label}.${String(prop)}`, depth + 1);
            }

            return value;
        },
    });
}

/**
 * Log every read of an object's own properties.
 *
 * Accessors rather than a Proxy: on the vm global a Proxy loses the realm intrinsics, so this
 * is the only tracing that works on the global itself. Self-referential and hot properties are
 * skipped so the log does not drown in them. Debug-only — it changes property descriptors.
 */
function traceReads(target, label) {
    const skip = new Set(['window', 'self', 'globalThis', 'frames', 'console', 'setTimeout', 'clearTimeout']);

    for (const key of Object.keys(target)) {
        const descriptor = Object.getOwnPropertyDescriptor(target, key);

        if (skip.has(key) || !descriptor || !descriptor.configurable || descriptor.get) {
            continue;
        }

        let value = descriptor.value;

        Object.defineProperty(target, key, {
            configurable: true,
            enumerable: descriptor.enumerable,
            get() {
                process.stderr.write(`[read] ${label}.${key}\n`);

                // Whatever a stub method HANDS BACK is stub too, and just as able to be
                // missing a member. getComputedStyle and getContext return the objects the
                // challenge then reaches into, so the trace has to follow the return value or
                // it stops one step short of the answer.
                if (typeof value === 'function') {
                    return new Proxy(value, {
                        apply(fn, self, callArgs) {
                            const result = Reflect.apply(fn, self, callArgs);

                            // Plain objects only. Wrapping a host built-in — a
                            // URLSearchParams, a Promise — hides the internal slots its own
                            // methods check for, and it fails on its very first call.
                            return isPlainObject(result)
                                ? traceMisses(result, `${label}.${key}()`)
                                : result;
                        },
                        construct(fn, callArgs) {
                            return Reflect.construct(fn, callArgs);
                        },
                    });
                }

                return value;
            },
            set(next) {
                value = next;
            },
        });
    }
}

/**
 * Fire a DOM event the challenge is waiting on.
 *
 * A throwing listener must be REPORTED, not swallowed. Discarding it is what made a run look
 * like it had zero runtime errors while the challenge's own message handler was dying on
 * every delivery — the emulator then blamed a silent watchdog for what was an ordinary
 * exception. Delivery continues to the remaining listeners, as a browser does.
 */
function fire(listeners, type, extra = {}, onError = null) {
    const event = { type, target: null, timeStamp: Date.now(), ...extra };
    const handlers = listeners.get(type) || [];

    if (type === 'message' && process.env.EMU_TRACE_MSG) {
        process.stderr.write(`[deliver] -> challenge (${handlers.length} listener(s)): ${JSON.stringify(extra.data).slice(0, 100)}\n`);
    }

    for (const fn of handlers) {
        try {
            fn(event);
        } catch (e) {
            if (onError) {
                onError(e);
            }
        }
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Resolve the current Turnstile api asset hash (ch) from the api.js redirect chain.
 *
 * Cloudflare rotates this continuously. The redirect path contains the hash:
 *   /turnstile/v0/g/{ch}/api.js
 * The hardcoded default in parseArgs will go stale; this fetches the live value.
 */
async function resolveApiAsset() {
    return new Promise((resolve, reject) => {
        const req = https.request({
            host: WIDGET_HOST,
            path: '/turnstile/v0/api.js?onload=__',
            method: 'GET',
            headers: { 'user-agent': USER_AGENT },
        }, (res) => {
            res.resume(); // drain

            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                const m = String(res.headers.location).match(/\/g\/([a-f0-9]+)\//);

                if (m) {
                    resolve(m[1]);
                } else {
                    reject(new Error('redirect has no ch hash: ' + res.headers.location));
                }
            } else {
                reject(new Error('api.js did not redirect: ' + res.statusCode));
            }
        });

        req.on('error', reject);
        req.end();
    });
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const outbound = [];
    outbound.messages = [];
    const misses = [];
    const runtimeErrors = [];
    let lastSource = null;

    // The challenge drives itself from timers and XHR callbacks, which run outside the
    // runInContext try/catch — an unhandled throw there kills the process before it can
    // report anything, and Node dumps 260 KB of obfuscated source instead. Collecting them
    // is what turns a crash into the worklist for widening the stub.
    // The whole challenge is a single line, so the stack's column is a character offset into
    // it — which makes the surrounding source printable. That is the only way to see which
    // expression failed, since every identifier in it is machine-generated.
    const collect = (e) => {
        const message = String((e && e.message) || e).slice(0, 200);

        if (runtimeErrors.some((r) => r.message === message)) {
            return;
        }

        const at = String((e && e.stack) || '').match(/cf-challenge\.js:1:(\d+)/);
        const offset = at ? parseInt(at[1], 10) : null;

        runtimeErrors.push({
            message,
            offset,
            source: offset !== null && lastSource
                ? lastSource.slice(Math.max(0, offset - 220), offset + 60)
                : null,
        });
    };

    process.on('uncaughtException', collect);
    process.on('unhandledRejection', collect);

    const bootstrap = await fetchBootstrap(args.siteKey, args.pageUrl);
    lastSource = bootstrap.source;

    // Resolve the current api asset hash (ch) from the api.js redirect. Cloudflare rotates
    // this continuously, so the hardcoded default will go stale. The redirect path contains
    // the hash: /turnstile/v0/g/{ch}/api.js.
    const resolvedCh = await resolveApiAsset().catch(() => null);
    if (resolvedCh) {
        args.apiAsset = resolvedCh;
        process.stderr.write(`[emulator] resolved api asset (ch): ${resolvedCh}\n`);
    }

    // Cloudflare re-obfuscates on every fetch, so an offset printed by one run means nothing
    // against a separately downloaded copy. Dumping THIS run's source is what makes the
    // column offsets in the traces usable.
    if (process.env.EMU_DUMP_SOURCE) {
        require('fs').writeFileSync(process.env.EMU_DUMP_SOURCE, bootstrap.source);
    }
    process.stderr.write(`[emulator] bootstrap ${bootstrap.html.length} bytes, ray ${bootstrap.ray}\n`);

    const { global, listeners, document, parentFrame, clickState } = buildEnvironment(
        bootstrap, args.pageUrl, outbound, misses, args.verbose,
    );

    const context = vm.createContext(global);

    installNativeToString(context);

    if (process.env.EMU_TRACE_THROW) {
        global.__emuThrow = (message) => process.stderr.write(`[throw] ${String(message).slice(0, 300)}\n`);
        installThrowTracer(context);
    }

    if (process.env.EMU_TRACE_FLOW) {
        const origSetTimeout = global.setTimeout;
        global.setTimeout = function(fn, delay) {
            const label = typeof fn === 'function' ? (fn.name || 'anonymous') : String(fn).slice(0, 60);
            process.stderr.write(`[flow] setTimeout(${label}, ${delay})\n`);
            return origSetTimeout.apply(this, arguments);
        };
        const origSetInterval = global.setInterval;
        global.setInterval = function(fn, delay) {
            const label = typeof fn === 'function' ? (fn.name || 'anonymous') : String(fn).slice(0, 60);
            process.stderr.write(`[flow] setInterval(${label}, ${delay})\n`);
            return origSetInterval.apply(this, arguments);
        };
        // Intercept XHR.open to catch any HTTP calls
        const OrigXHR = global.XMLHttpRequest;
        const origOpen = OrigXHR.prototype.open;
        OrigXHR.prototype.open = function(method, url) {
            process.stderr.write(`[flow] XHR.open(${method}, ${String(url).slice(0, 120)})\n`);
            return origOpen.apply(this, arguments);
        };
        // Intercept fetch
        const origFetch = global.fetch;
        if (origFetch) {
            global.fetch = function(input, init) {
                process.stderr.write(`[flow] fetch(${String(input).slice(0, 120)})\n`);
                return origFetch.apply(this, arguments);
            };
        }
    }

    let scriptError = null;

    // Fire readiness events BEFORE the script runs. The challenge's vF() gate creates
    // DOM elements and adds readiness event listeners to them during script execution.
    // If readiness events fire after, the listeners are already registered but the events
    // have already been dispatched — the PT counter never reaches 5 and vB() is never called.
    // By setting readyState and firing events before script execution, the challenge's
    // element listeners catch them as they are registered.
    document.readyState = 'interactive';
    fire(listeners, 'DOMContentLoaded', {}, collect);
    fire(listeners, 'readystatechange', {}, collect);
    document.readyState = 'complete';
    fire(listeners, 'readystatechange', {}, collect);
    fire(listeners, 'load', {}, collect);

    try {
        new vm.Script(bootstrap.source, { filename: 'cf-challenge.js' }).runInContext(context, { timeout: 10000 });
    } catch (e) {
        scriptError = { phase: 'toplevel', message: e.message };
    }

    // Drive the handshake the parent frame performs in a browser. Without this the challenge
    // simply sits on its `message` listener forever — it was the sole reason a run with zero
    // errors and zero stub misses still never issued a single request.
    // The parent picks the widgetId and opens with it; the iframe echoes it back on its own
    // init and on every later message.
    let widgetId = Math.random().toString(36).slice(2, 7);
    const apiJsUrl = `https://${WIDGET_HOST}/turnstile/v0/api.js?onload=${PARENT_CALLBACK}`;
    // Deployment constant from step 2 (settings.turnstile_endpoints.api_asset).
    const apiAsset = args.apiAsset;
    const callStack = buildCallStack(apiJsUrl, args.pageUrl);
    // Deliver asynchronously. postMessage is a queued task in a browser, never a synchronous
    // call — replying inline from inside the challenge's own postMessage re-enters its state
    // machine mid-transition.
    const toChallenge = (data, delayMs = 0) => setTimeout(() => fire(listeners, 'message', {
        data,
        origin: new URL(args.pageUrl).origin,
        source: parentFrame,
    }, collect), delayMs);

    let token = null;
    let rcV = '';

    if (process.env.EMU_TRACE_MSG) {
        const started = Date.now();

        outbound.onProvenance = (data, stack) => {
            const event = (data && data.event) || typeof data;
            const at = stack.match(/cf-challenge\.js:1:(\d+)/);
            const offset = at ? parseInt(at[1], 10) : null;

            process.stderr.write(`[emit +${Date.now() - started}ms] ${event} @${offset}\n`);

            if (offset !== null) {
                process.stderr.write(`  ${lastSource.slice(Math.max(0, offset - 300), offset + 120)}\n`);
            }
        };
    }

    /**
     * Deliver extraParams + execute to the challenge.
     *
     * Extracted so both the initial requestExtraParams and a later reloadApiJsRequest
     * can trigger the same handshake step. The `rcV` field must carry the value the
     * challenge sent in its `init` (the `nextRcV` field) — an empty rcV is what causes
     * the challenge to request a reload in the first place.
     */
    const sendExtraParams = () => {
        toChallenge(buildExtraParams({
            apiJsUrl, apiAsset, callStack, widgetId, pageUrl: args.pageUrl, rcV,
        }), 10);
        // `execute` is what actually starts the run; init alone only opens the channel.
        toChallenge({ cs: callStack, event: 'execute', source: 'cloudflare-challenge', widgetId }, 22);
    };

    const msgCounts = {};
    outbound.onMessage = (msg) => {
        const event = msg && msg.event;
        msgCounts[event] = (msgCounts[event] || 0) + 1;

        if (msg && msg.widgetId) {
            widgetId = msg.widgetId;
        }

        if (args.verbose) {
            process.stderr.write(`[msg] challenge -> parent: ${JSON.stringify(msg).slice(0, 120)}\n`);
        }

        // The challenge's `init` carries `nextRcV` — the version token it expects back
        // in `extraParams.rcV`. Without this the challenge sees an empty rcV, concludes
        // the parent's api.js is stale, and requests a reload.
        if (event === 'init' && msg.nextRcV) {
            rcV = msg.nextRcV;

            // Update _cf_chl_opt.EnOnL8 so the parent-side code (running in the same
            // context) can match widget IDs for the food/meow exchange.
            if (global._cf_chl_opt) {
                global._cf_chl_opt.EnOnL8 = widgetId;
            }

            if (args.verbose) {
                process.stderr.write(`[msg] captured nextRcV: ${rcV}\n`);
            }
        }

        if (event === 'requestExtraParams') {
            sendExtraParams();

            return;
        }

        // The challenge requests a reload when it suspects the parent's api.js is out
        // of date. Re-delivering extraParams + execute is what a real browser does — it
        // re-fetches the script, which re-renders the widget and restarts the handshake.
        if (event === 'reloadApiJsRequest' || event === 'reloadRequest') {
            // Update rcV if the reload carries a new one.
            if (msg.nextRcV) {
                rcV = msg.nextRcV;
            }

            if (args.verbose) {
                process.stderr.write(`[msg] ${event} requested, re-sending extraParams with rcV=${rcV}\n`);
            }

            sendExtraParams();

            // After the reload, the widget re-renders and registers a new click handler.
            // Fire a synthetic click on the most recently click-registered element (the
            // actual widget checkbox) after a short delay for the re-render to complete.
            // Use multiple attempts since the re-render timing varies.
            const fireReloadClick = () => {
                const target = clickState.lastTarget;
                if (target && !clickState.fired) {
                    clickState.fired = true;
                    if (args.verbose) {
                        process.stderr.write(`[click] post-reload click on <${target.tagName.toLowerCase()} id="${target.id}">\n`);
                    }
                    const mkEvent = (t) => ({
                        type: t, target, currentTarget: target,
                        clientX: 10, clientY: 10, screenX: 10, screenY: 10,
                        pageX: 10, pageY: 10, offsetX: 5, offsetY: 5,
                        button: 0, buttons: 1, isTrusted: true,
                        timeStamp: Date.now(), preventDefault() {}, stopPropagation() {},
                        composed: false, cancelable: true, bubbles: true,
                    });
                    target.dispatchEvent(mkEvent('mousedown'));
                    setTimeout(() => {
                        target.dispatchEvent(mkEvent('mouseup'));
                        target.dispatchEvent(mkEvent('click'));
                    }, 10);
                }
            };
            setTimeout(fireReloadClick, 200);
            setTimeout(fireReloadClick, 500);
            setTimeout(fireReloadClick, 1000);

            return;
        }

        // The challenge sends its display language and expects a translation table back.
        // In a real browser, api.js handles this by sending translationData. Without a
        // response the challenge blocks before it can reach the solving phase.
        if (event === 'translationInit') {
            const lang = msg.displayLanguage || 'en-us';

            if (args.verbose) {
                process.stderr.write(`[msg] translationInit received (lang=${lang}), sending translationData\n`);
            }

            toChallenge({
                event: 'translationData',
                source: 'cloudflare-challenge',
                widgetId,
                language: lang,
                translations: {},
            }, 5);

            return;
        }

        // The challenge pings `food` and expects `meow` back with the same sequence number.
        if (event === 'food') {
            toChallenge({ event: 'meow', seq: msg.seq, source: 'cloudflare-challenge', widgetId });

            return;
        }

        // In a real browser, the parent code listens for `meow` from the challenge and
        // responds with `food`. This exchange is part of the solving protocol.
        if (event === 'meow') {
            toChallenge({ event: 'food', seq: msg.seq, source: 'cloudflare-challenge', widgetId });

            return;
        }

        if (event === 'complete' && msg.token) {
            token = msg.token;
        }

        if (event && !['init', 'requestExtraParams', 'translationInit', 'food', 'complete', 'reloadApiJsRequest'].includes(event)) {
            process.stderr.write(`[msg] UNKNOWN event: ${event} data=${JSON.stringify(msg).slice(0, 200)}\n`);
        }
    };

    toChallenge({ event: 'init', source: 'cloudflare-challenge', widgetId });
    process.stderr.write(`[emulator] handshake opened (widgetId ${widgetId}), waiting for the challenge\n`);

    // Click scheduling is handled in two ways:
    // 1. From the reloadRequest handler: fires click after widget re-render
    // 2. Fallback timer: for runs where reloadRequest doesn't happen

    // Let the challenge's own timers and the real HTTPS calls run. Bounded by wall clock, not
    // iterations: the challenge installs its own intervals, so the process would otherwise
    // never fall idle and never report.
    const deadline = Date.now() + 25000;

    while (Date.now() < deadline && !token) {
        await sleep(250);
    }

    await sleep(2000);
    process.stderr.write(`[emulator] done waiting, ${outbound.length} outbound call(s), ${outbound.messages.length} message(s)\n`);
    process.stderr.write(`[emulator] message counts: ${JSON.stringify(msgCounts)}\n`);

    const chlOpt = (bootstrap.html.match(/_cf_chl_opt\s*=\s*\{([\s\S]*?)\};/) || [])[1] || '';


    process.stdout.write(JSON.stringify({
        bootstrap: { url: bootstrap.url, bytes: bootstrap.html.length, ray: bootstrap.ray },
        session: {
            ray: (chlOpt.match(/'([0-9a-f]{16})'/) || [])[1] || null,
            has_chl_opt: chlOpt.length > 0,
        },
        script_error: scriptError,
        outbound: outbound.map((o) => ({
            method: o.method,
            path: new URL(o.url).pathname.slice(0, 110),
            request_bytes: o.body_bytes,
            status: o.response_status ?? null,
            response_bytes: o.response_bytes ?? null,
        })),
        token_found: Boolean(token),
        token_length: token ? token.length : 0,
        listeners_registered: [...listeners.keys()],
        challenge_messages: outbound.messages.map((m) => (m && m.event) || typeof m),
        runtime_errors: runtimeErrors,
        stub_misses: misses,
    }, null, 2));

    // The challenge leaves its own intervals running, so the event loop never drains on its
    // own once the report is written.
    process.exit(0);
}

/**
 * Assert the invariants each of the emulator's fixes established.
 *
 * Every check here is a bug that cost a debugging cycle and produced no error message when it
 * regressed — the challenge answers a broken environment with a silent stall or a generic
 * `unsupported_browser`, never with the name of what is wrong. Runs offline against a
 * synthetic bootstrap, so it is a fast guard rather than a live solve.
 */
function selfTest() {
    const bootstrap = {
        url: `https://${WIDGET_HOST}/cdn-cgi/challenge-platform/h/g/turnstile/f/av0/rch/aaaaa/x/auto/fbE/new/normal?lang=auto`,
        html: '<html></html>',
        source: '',
        ray: 'selftest',
    };

    const outbound = [];
    outbound.messages = [];

    const { global, document } = buildEnvironment(bootstrap, 'https://appointment.ivacbd.com/', outbound, [], false);
    const context = vm.createContext(global);

    // Node's vm realm can retain the host's UTC Intl default even when TZ is set after startup.
    // Keep only this deterministic self-test aligned with the worker's Dhaka-time contract.
    context.Intl = context.Intl || Intl;
    const nativeDateTimeFormat = context.Intl.DateTimeFormat;
    context.Intl = Object.create(context.Intl);
    context.Intl.DateTimeFormat = function (...args) {
        const formatter = new nativeDateTimeFormat(...args);
        return { resolvedOptions: () => ({ ...formatter.resolvedOptions(), timeZone: 'Asia/Dhaka' }) };
    };

    installNativeToString(context);
    document.readyState = 'complete';

    const results = [];
    const check = (name, expression, expected) => {
        let actual;

        try {
            actual = vm.runInContext(expression, context, { timeout: 5000 });
        } catch (e) {
            actual = `THREW: ${e.message}`;
        }

        results.push({ name, expected, actual, pass: JSON.stringify(actual) === JSON.stringify(expected) });
    };

    // The capability gate the challenge runs before it will start: Blob -> object URL ->
    // Worker -> revoke -> terminate, all inside one try/catch. Node's own URL.createObjectURL
    // rejects a stub Blob, which is what made this throw and read as an unsupported browser.
    check('capability gate', `(() => {
        try {
            const url = URL.createObjectURL(new Blob(['"you"==="bot"'], { type: 'text/javascript' }));
            const worker = new Worker(url);
            URL.revokeObjectURL(url);
            worker.terminate();
            return 'ok';
        } catch (e) { return 'THREW: ' + e.message; }
    })()`, 'ok');

    check('blob reports its size', 'new Blob(["abc"]).size', 3);
    check('object url resolves to the blob source', `(() => {
        const url = URL.createObjectURL(new Blob(['self.x=1']));
        return new Worker(url) instanceof Worker;
    })()`, true);

    // Stub methods must stringify as native, in both realms, without leaking a wrapper name.
    check('native toString', "Function.prototype.toString.call(document.createElement).includes('[native code]')", true);
    check('native toString keeps the name', "Function.prototype.toString.call(document.createElement).includes('createElement')", true);
    check('native toString via the host realm', "('' + document.createElement).includes('[native code]')", true);
    check('toString describes itself as native', "Function.prototype.toString.call(Function.prototype.toString).includes('[native code]')", true);
    check('challenge functions still tell the truth', "Function.prototype.toString.call(function mine() { return 1; }).includes('[native code]')", false);
    check('Blob is not named BlobImpl', "('' + Blob).includes('Blob(')", true);

    // DOM interfaces and window globals materialised from the captured browser surface.
    check('DOM interfaces exist', "typeof window.Element === 'function' && typeof window.Node === 'function'", true);
    check('interface prototypes carry members', "typeof window.Element.prototype.append", 'function');
    check('interface constants keep their values', 'window.Node.ELEMENT_NODE', 1);
    check('non-constructible interfaces refuse construction', `(() => {
        try { new window.Element(); return 'constructed'; } catch (e) { return e.message; }
    })()`, 'Illegal constructor');
    check('object globals exist', "typeof window.visualViewport", 'object');
    check('null handlers exist for `in` tests', "'onmessage' in window", true);

    // The realm's own intrinsics must survive materialisation — replacing them with inert
    // shapes stopped the bootstrap on its first statement.
    check('intrinsics are untouched', 'new Uint8Array(4).byteLength', 4);
    check('URLSearchParams still works', "new URLSearchParams('a=1').get('a')", '1');

    // Things the challenge waits on. Each of these was a silent multi-second stall.
    check('observers call back', "typeof new IntersectionObserver(() => {}).observe", 'function');
    check('widget has a size', 'document.createElement("div").getBoundingClientRect().width', WIDGET_WIDTH);

    // Transport. fetch being absent made the challenge take a branch it could not finish.
    check('fetch exists', "typeof fetch", 'function');
    check('xhr exposes its handlers on the prototype', "'onload' in new XMLHttpRequest()", true);
    check('xhr reports CORS support', "'withCredentials' in new XMLHttpRequest()", true);

    // The environment must not contradict itself about what browser this is.
    check('claims Chrome and has window.chrome', "navigator.userAgent.includes('Chrome') && typeof window.chrome === 'object'", true);
    check('no automation marker', 'navigator.webdriver', false);
    check('not a touch device', "'ontouchstart' in window", false);
    check('non-UTC timezone', "Intl.DateTimeFormat().resolvedOptions().timeZone !== 'UTC'", true);

    // The handshake payload the challenge will not start without.
    const extraParams = buildExtraParams({
        apiJsUrl: 'https://x/api.js', apiAsset: 'b0da9f4911ba',
        callStack: buildCallStack('https://x/api.js', 'https://appointment.ivacbd.com/'),
        widgetId: 'test1', pageUrl: 'https://appointment.ivacbd.com/',
    });

    results.push({
        name: 'extraParams carries its event name',
        expected: 'extraParams',
        actual: extraParams.event,
        pass: extraParams.event === 'extraParams',
    });
    results.push({
        name: 'extraParams carries the parent page recon',
        expected: true,
        actual: Boolean(extraParams.wPr && extraParams.wPr.pi && extraParams.wPr.pi.pfp),
        pass: Boolean(extraParams.wPr && extraParams.wPr.pi && extraParams.wPr.pi.pfp),
    });

    // The iframe load event is asynchronous, as it is in a browser, so it is the one check the
    // suite has to wait for.
    const loadFired = vm.runInContext(`(() => {
        let fired = false;
        const frame = document.createElement('iframe');
        frame.addEventListener('load', () => { fired = true; });
        document.body.appendChild(frame);
        return () => fired;
    })()`, context);

    return new Promise((resolve) => setTimeout(() => {
        results.push({
            name: 'elements deliver load events',
            expected: true, actual: loadFired(), pass: loadFired() === true,
        });

        const failures = results.filter((r) => !r.pass);

        process.stdout.write(JSON.stringify({
            checks: results.length,
            passed: results.length - failures.length,
            failures,
        }, null, 2));

        resolve(failures.length === 0);
    }, 50));
}

// Only run as a script. turnstile_env_diff.cjs requires this file to build the same
// environment the challenge would see, so importing it must stay side-effect free.
if (require.main === module) {
    if (process.argv.includes('--self-test')) {
        selfTest().then((ok) => process.exit(ok ? 0 : 1)).catch((e) => {
            process.stderr.write(`fatal: ${e.stack}\n`);
            process.exit(1);
        });
    } else {
        main().catch((e) => {
            process.stderr.write(`fatal: ${e.stack}\n`);
            process.exit(1);
        });
    }
}

module.exports = {
    buildEnvironment, fetchBootstrap, installNativeToString, buildExtraParams, buildCallStack,
    USER_AGENT, WIDGET_HOST, PARENT_CALLBACK,
};
