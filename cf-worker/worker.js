/**
 * IVAC API relay — Cloudflare Worker
 *
 * Proxies requests to https://api.ivacbd.com so they originate from a
 * Cloudflare IP, bypassing the origin's IP allowlist.
 *
 * Deploy:
 *   cd cf-worker && npx wrangler deploy
 *
 * Usage:
 *   POST https://<worker>.workers.dev/iams/api/v1/auth/sign-in-v2
 *   Header: X-Worker-Secret: <your secret>
 *   Body: { "phone": "...", "email": "...", "password": "...", "c": "..." }
 */

const ORIGIN = 'https://api.ivacbd.com';

// Hop-by-hop headers that must not be forwarded
const HOP_BY_HOP = new Set([
    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
    'te', 'trailers', 'transfer-encoding', 'upgrade',
    'cf-connecting-ip', 'cf-ipcountry', 'cf-ray', 'cf-visitor',
    'x-forwarded-proto', 'x-real-ip',
]);

export default {
    async fetch(request, env) {
        // Auth check
        const secret = env.WORKER_SECRET;
        if (secret && request.headers.get('X-Worker-Secret') !== secret) {
            return new Response(JSON.stringify({ error: 'Unauthorized' }), {
                status: 401,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        // CORS preflight
        if (request.method === 'OPTIONS') {
            return new Response(null, { status: 204, headers: corsHeaders() });
        }

        const url = new URL(request.url);
        const targetUrl = ORIGIN + url.pathname + url.search;

        // Build forwarded headers — strip hop-by-hop and worker-specific headers
        const forwardHeaders = new Headers();
        for (const [key, value] of request.headers.entries()) {
            const lower = key.toLowerCase();
            if (HOP_BY_HOP.has(lower)) continue;
            if (lower === 'x-worker-secret') continue;
            if (lower === 'host') continue;
            forwardHeaders.set(key, value);
        }
        forwardHeaders.set('Host', 'api.ivacbd.com');

        let body = null;
        if (request.method !== 'GET' && request.method !== 'HEAD') {
            body = await request.arrayBuffer();
        }

        const start = Date.now();
        let originResponse;
        try {
            originResponse = await fetch(targetUrl, {
                method: request.method,
                headers: forwardHeaders,
                body: body,
                redirect: 'manual',
            });
        } catch (err) {
            return new Response(JSON.stringify({ error: String(err) }), {
                status: 502,
                headers: { 'Content-Type': 'application/json', ...corsHeaders() },
            });
        }

        const duration = Date.now() - start;

        // Strip hop-by-hop from response headers
        const responseHeaders = new Headers(originResponse.headers);
        for (const key of HOP_BY_HOP) {
            responseHeaders.delete(key);
        }
        responseHeaders.set('X-Duration-Ms', String(duration));
        for (const [k, v] of Object.entries(corsHeaders())) {
            responseHeaders.set(k, v);
        }

        return new Response(originResponse.body, {
            status: originResponse.status,
            headers: responseHeaders,
        });
    },
};

function corsHeaders() {
    return {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Device-ID, X-Worker-Secret',
    };
}
