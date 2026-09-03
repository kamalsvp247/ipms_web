const https = require('https');
const zlib = require('zlib');

function fetch(url) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(url);
    const req = https.request({
      host: parsed.hostname,
      path: parsed.pathname + parsed.search,
      method: 'GET',
      headers: {
        'user-agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
        accept: '*/*',
      }
    }, res => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        resolve({ redirect: res.headers.location, status: res.statusCode });
        res.resume();
        return;
      }
      const c = [];
      res.on('data', d => c.push(d));
      res.on('end', () => {
        const buf = Buffer.concat(c);
        let body;
        try { body = zlib.brotliDecompressSync(buf).toString('utf8'); } catch (e) {
          try { body = zlib.gunzipSync(buf).toString('utf8'); } catch (e2) {
            body = buf.toString('utf8');
          }
        }
        resolve({ status: res.statusCode, body });
      });
    });
    req.on('error', reject);
    req.end();
  });
}

async function main() {
  // Step 1: Follow redirect to get the actual api.js URL
  let res = await fetch('https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__ihcOnload');
  console.log('Redirect:', res.redirect);
  
  if (res.redirect) {
    const ch = res.redirect.match(/\/g\/([a-f0-9]+)\//);
    if (ch) console.log('api_asset (ch) from redirect:', ch[1]);
    
    // Step 2: Fetch the actual api.js
    res = await fetch('https://challenges.cloudflare.com' + res.redirect);
    console.log('api.js size:', res.body.length);
    
    // Search for where ch is set in the code
    // It's usually set as a module-level variable
    const chPatterns = [
      /["']ch["']\s*[=:]\s*["']([^"']+)["']/,
      /\.ch\s*=\s*["']([^"']+)["']/,
      /api_asset["']\s*:\s*["']([^"']+)["']/,
    ];
    
    for (const p of chPatterns) {
      const m = res.body.match(p);
      if (m) console.log('Found:', m[0].slice(0, 80));
    }
    
    // Look for the sha256 or hash of the api.js content itself
    const crypto = require('crypto');
    const hash = crypto.createHash('sha256').update(res.body).digest('hex').slice(0, 12);
    console.log('SHA256 of api.js (first 12):', hash);
    
    // The ch value is likely embedded at a specific location in the script
    // Let's look for patterns like: var X="330e41bb475c" or {ch:"330e41bb475c"}
    const hex12 = res.body.match(/\b([a-f0-9]{12})\b/g);
    if (hex12) console.log('All 12-char hex:', [...new Set(hex12)]);
  }
}

main().catch(e => console.error(e));
