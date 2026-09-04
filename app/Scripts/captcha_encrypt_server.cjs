const http = require('http');
const fs = require('fs');
const crypto = require('crypto');
const { load } = require('./captcha_live_runtime.cjs');

const PORT = Number(process.env.CAPTCHA_SIDECAR_PORT || 8787);
const HOST = process.env.CAPTCHA_SIDECAR_HOST || '127.0.0.1';
const BUNDLE_PATH = process.env.CAPTCHA_BUNDLE_PATH || '/var/lib/ipms/captcha/ivac-bundle.js';
const META_PATH = process.env.CAPTCHA_META_PATH || '/var/lib/ipms/captcha/encrypt_meta.json';

let live = null;
let staged = null;
let meta = {};

function readMeta() {
  try { return JSON.parse(fs.readFileSync(META_PATH, 'utf8')); }
  catch (_) { return {}; }
}
function hashFile(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}
function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}
function body(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', chunk => { data += chunk; if (data.length > 2_000_000) reject(new Error('request too large')); });
    req.on('end', () => { try { resolve(data ? JSON.parse(data) : {}); } catch (e) { reject(e); } });
    req.on('error', reject);
  });
}
function snapshot() {
  return {
    ok: !!live,
    bundle_hash: live?.bundleHash || null,
    modules: live?.modules || [],
    staged_hash: staged?.bundleHash || null,
    staged_modules: staged?.modules || [],
    meta,
  };
}
function loadLive() {
  const nextHash = hashFile(BUNDLE_PATH);
  meta = readMeta();
  if (live && live.bundleHash === nextHash) {
    console.log('meta-only refresh, skipped re-eval');
    return false;
  }
  live = load(BUNDLE_PATH);
  console.log(`loaded bundle ${live.bundleHash.slice(0, 12)} modules=[${live.modules.join(',')}]`);
  return true;
}
function loadCandidate() {
  const candidate = load(BUNDLE_PATH);
  staged = candidate;
  console.log(`staged bundle ${candidate.bundleHash.slice(0, 12)} modules=[${candidate.modules.join(',')}] (live untouched)`);
  return candidate;
}
function param(job, key, fallback) {
  return job[key] === undefined || job[key] === null ? fallback : job[key];
}
function encrypt(job) {
  const type = job.type || 'login';
  const cfg = meta[type] || {};
  const token = String(job.token || '');
  return live.encrypt(
    param(job, 'module', cfg.module), token, String(param(job, 'secret', cfg.secret || '')),
    Number(param(job, 'skip', cfg.skip || 0)), Number(param(job, 'encLen', cfg.enc_len || 0)), cfg.charset
  );
}

try { loadLive(); } catch (e) { console.error(`initial load failed: ${e.message}`); }

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') return json(res, live ? 200 : 503, snapshot());
    if (req.method === 'POST' && req.url === '/reload') {
      loadLive(); return json(res, 200, { ok: true, ...snapshot() });
    }
    if (req.method === 'POST' && req.url === '/stage') {
      const current = hashFile(BUNDLE_PATH);
      if (live && current === live.bundleHash) {
        console.log(`stage skipped: ${current.slice(0, 12)} is already live`);
        return json(res, 200, { ok: true, staging: false, reason: 'already-live', ...snapshot() });
      }
      if (staged && current === staged.bundleHash) {
        console.log(`stage skipped: ${current.slice(0, 12)} is already staged`);
        return json(res, 200, { ok: true, staging: false, reason: 'already-staged', ...snapshot() });
      }
      // A real IVAC bundle can take seconds to evaluate. Acknowledge immediately
      // and keep the live encryptor untouched while the candidate is prepared.
      json(res, 202, { ok: true, staging: true, ...snapshot() });
      setImmediate(() => {
        try { loadCandidate(); }
        catch (e) { console.error(`staging failed: ${e.message}`); }
      });
      return;
    }
    if (req.method === 'POST' && req.url === '/promote') {
      const input = await body(req);
      if (staged && input.bundle_hash === staged.bundleHash) {
        live = staged; staged = null; meta = readMeta();
        console.log(`promoted staged bundle ${live.bundleHash.slice(0, 12)} (instant, no re-eval)`);
        return json(res, 200, { ok: true, promoted: true, ...snapshot() });
      }
      staged = null; loadLive();
      return json(res, 200, { ok: true, promoted: false, ...snapshot() });
    }
    if (req.method === 'POST' && req.url === '/encrypt') {
      const input = await body(req);
      if (!live) return json(res, 503, { ok: false, error: 'sidecar not ready' });
      return json(res, 200, { ok: true, token: encrypt(input), bundle_hash: live.bundleHash });
    }
    return json(res, 404, { ok: false, error: 'not found' });
  } catch (e) {
    console.error(`request failed: ${e.stack || e.message}`);
    return json(res, 500, { ok: false, error: e.message });
  }
});
server.listen(PORT, HOST, () => console.log(`Captcha encryption server started on ${HOST}:${PORT}`));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
let lastMtime = 0;
setInterval(() => {
  try { const m = fs.statSync(META_PATH).mtimeMs; if (m !== lastMtime) { lastMtime = m; meta = readMeta(); } } catch (_) {}
}, 1000).unref();
