/**
 * IVAC Cloudflare-native test worker.
 *
 * api.ivacbd.com is behind Cloudflare, and Cloudflare has a Dhaka PoP (colo DAC), so a
 * Worker reached from Bangladesh runs in DAC. This worker offers three sign-in paths so
 * we can isolate what IVAC's WAF keys on:
 *
 *   /probe      GET  — egress IP (via fetch to ipify) + the colo we ran in
 *   /signin     POST — sign-in via fetch() (Cloudflare adds headers; JA3 = Cloudflare)
 *   /signin-raw POST — sign-in via a raw TCP socket + direct TLS, hand-crafted HTTP/1.1
 *                      with the exact okhttp header profile & order, no fetch() headers.
 *
 * A Worker cannot change its TLS/JA3 fingerprint (runtime BoringSSL) and always egresses
 * a Cloudflare-owned IP; /signin-raw removes every other variable (fetch auto-headers,
 * header order, worker-subrequest tagging) to test whether the HTTP layer is the gate.
 */

import { connect } from 'cloudflare:sockets';

const enc = new TextEncoder();
const dec = new TextDecoder();
const CRLF2 = enc.encode('\r\n\r\n');

function concat(a, b) {
    const out = new Uint8Array(a.length + b.length);
    out.set(a, 0); out.set(b, a.length);
    return out;
}
function indexOfSeq(buf, seq, from = 0) {
    outer: for (let i = from; i + seq.length <= buf.length; i++) {
        for (let j = 0; j < seq.length; j++) if (buf[i + j] !== seq[j]) continue outer;
        return i;
    }
    return -1;
}
function json(obj, status = 200) {
    return new Response(JSON.stringify(obj, null, 2), {
        status, headers: { 'Content-Type': 'application/json' },
    });
}

async function gunzip(bytes) {
    const ds = new DecompressionStream('gzip');
    const stream = new Blob([bytes]).stream().pipeThrough(ds);
    return await new Response(stream).text();
}

/**
 * Send one HTTP/1.1 request over a TLS socket and read the full response.
 * Returns { status, headers, body } with gzip transparently decoded.
 */
async function sendOverTls(tls, rawRequest) {
    const writer = tls.writable.getWriter();
    await writer.write(enc.encode(rawRequest));
    writer.releaseLock();

    const reader = tls.readable.getReader();
    let buf = new Uint8Array(0);
    let headerEnd = -1;
    const readMore = async () => {
        const { value, done } = await reader.read();
        if (done) return false;
        buf = concat(buf, value);
        return true;
    };

    while (headerEnd === -1) {
        if (!(await readMore())) break;
        headerEnd = indexOfSeq(buf, CRLF2);
    }
    const headerText = dec.decode(buf.slice(0, headerEnd === -1 ? buf.length : headerEnd));
    const statusLine = headerText.split('\r\n')[0] || '';
    const status = Number((statusLine.match(/HTTP\/\d\.\d (\d{3})/) || [])[1] || 0);
    const lower = headerText.toLowerCase();
    const bodyStart = headerEnd === -1 ? buf.length : headerEnd + 4;

    const clMatch = lower.match(/content-length:\s*(\d+)/);
    const chunked = /transfer-encoding:\s*chunked/.test(lower);
    if (clMatch) {
        const need = Number(clMatch[1]);
        while (buf.length - bodyStart < need) if (!(await readMore())) break;
    } else if (chunked) {
        const term = enc.encode('\r\n0\r\n\r\n');
        while (indexOfSeq(buf, term, bodyStart) === -1) if (!(await readMore())) break;
    } else {
        while (await readMore()) { /* until close */ }
    }
    try { reader.releaseLock(); tls.close(); } catch (e) { /* ignore */ }

    let bodyBytes = buf.slice(bodyStart);
    if (chunked) bodyBytes = dechunkBytes(bodyBytes);

    let body;
    if (/content-encoding:\s*gzip/.test(lower)) {
        try { body = await gunzip(bodyBytes); } catch (e) { body = '[gzip decode failed] ' + dec.decode(bodyBytes); }
    } else {
        body = dec.decode(bodyBytes);
    }
    return { status, headers: headerText, body };
}

function dechunkBytes(bytes) {
    const s = dec.decode(bytes);
    let out = ''; let i = 0;
    while (i < s.length) {
        const nl = s.indexOf('\r\n', i);
        if (nl === -1) break;
        const size = parseInt(s.slice(i, nl).trim(), 16);
        if (!Number.isFinite(size) || size === 0) break;
        out += s.slice(nl + 2, nl + 2 + size);
        i = nl + 2 + size + 2;
    }
    return enc.encode(out);
}

export default {
    async fetch(request, env) {
        if (env.WORKER_SECRET && request.headers.get('X-Worker-Secret') !== env.WORKER_SECRET) {
            return json({ error: 'Unauthorized' }, 401);
        }
        const url = new URL(request.url);
        const ran = { colo: request.cf?.colo ?? null, country: request.cf?.country ?? null, city: request.cf?.city ?? null };
        const t0 = Date.now();

        try {
            if (url.pathname === '/probe') {
                let egress = null;
                try {
                    const r = await fetch('https://api.ipify.org?format=json', { headers: { 'User-Agent': 'okhttp/4.12.0' } });
                    egress = await r.text();
                } catch (e) { egress = 'ipify error: ' + String(e); }
                return json({ ran, egress, ms: Date.now() - t0 });
            }

            if (url.pathname === '/signin' && request.method === 'POST') {
                const payload = await request.text();
                const r = await fetch('https://api.ivacbd.com/iams/api/v1/auth/sign-in-v2', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'User-Agent': 'okhttp/4.12.0', 'Accept': '*/*' },
                    body: payload,
                });
                const body = await r.text();
                return json({ ran, via: 'fetch', status: r.status, ms: Date.now() - t0, body: body.slice(0, 1500) });
            }

            if (url.pathname === '/inspect') {
                const target = url.searchParams.get('url');
                const method = url.searchParams.get('method') || 'GET';
                const r = await fetch(target, { method, headers: { 'User-Agent': 'okhttp/4.12.0', 'Accept': '*/*' }, redirect: 'manual' });
                const hdrs = {};
                for (const [k, v] of r.headers.entries()) hdrs[k] = v;
                const body = await r.text();
                return json({ ran, target, status: r.status, headers: hdrs, body: body.slice(0, 300), ms: Date.now() - t0 });
            }

            if (url.pathname === '/trace') {
                // Cloudflare's own geo of our egress IP — this is what IVAC's WAF sees.
                const r = await fetch('https://www.cloudflare.com/cdn-cgi/trace', { headers: { 'User-Agent': 'okhttp/4.12.0' } });
                return json({ ran, trace: await r.text(), ms: Date.now() - t0 });
            }

            if (url.pathname === '/geo') {
                // Third-party (non-Cloudflare) geo DB view of our egress IP.
                let out = {};
                try { out.ipapi = await (await fetch('http://ip-api.com/json', { headers: { 'User-Agent': 'okhttp/4.12.0' } })).text(); } catch (e) { out.ipapi = String(e); }
                return json({ ran, ...out, ms: Date.now() - t0 });
            }

            if (url.pathname === '/rawget') {
                const host = url.searchParams.get('host') || 'example.com';
                const path = url.searchParams.get('path') || '/';
                const tls = connect({ hostname: host, port: 443 }, { secureTransport: 'on', allowHalfOpen: false });
                const res = await sendOverTls(tls,
                    `GET ${path} HTTP/1.1\r\nHost: ${host}\r\nConnection: close\r\nAccept-Encoding: identity\r\nUser-Agent: okhttp/4.12.0\r\n\r\n`);
                return json({ ran, host, status: res.status, ms: Date.now() - t0, body: res.body.slice(0, 200) });
            }

            if (url.pathname === '/signin-raw' && request.method === 'POST') {
                const payload = await request.text();
                const bodyLen = enc.encode(payload).length;
                // Direct TLS to the origin from the Worker's colo. okhttp header profile & order.
                const tls = connect({ hostname: 'api.ivacbd.com', port: 443 }, { secureTransport: 'on', allowHalfOpen: false });
                const req =
                    `POST /iams/api/v1/auth/sign-in-v2 HTTP/1.1\r\n` +
                    `Content-Type: application/json\r\n` +
                    `Content-Length: ${bodyLen}\r\n` +
                    `Host: api.ivacbd.com\r\n` +
                    `Connection: close\r\n` +
                    `Accept-Encoding: gzip\r\n` +
                    `User-Agent: okhttp/4.12.0\r\n\r\n` +
                    payload;
                const res = await sendOverTls(tls, req);
                return json({ ran, via: 'raw-socket okhttp', status: res.status, ms: Date.now() - t0, body: res.body.slice(0, 1500) });
            }

            return json({ ran, error: 'Not found', routes: ['GET /probe', 'POST /signin', 'POST /signin-raw'] }, 404);
        } catch (err) {
            return json({ ran, error: String((err && err.stack) || err), ms: Date.now() - t0 }, 502);
        }
    },
};
