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
        resolve({ status: res.statusCode, headers: res.headers, body });
      });
    });
    req.on('error', reject);
    req.end();
  });
}

async function main() {
  let res = await fetch('https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__ihcOnload');
  console.log('Status:', res.statusCode);
  console.log('Location:', res.headers.location);
  
  if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
    res = await fetch(res.headers.location);
    console.log('After redirect - Status:', res.statusCode);
    console.log('Body size:', res.body.length);
    
    // Look for the 'ch' value assignment  
    // In api.js, 'ch' is typically the content hash of the script itself
    // It's used in the extraParams as the api_asset identifier
    const chAssign = res.body.match(/['"]ch['"]\s*[=:]\s*['"]([^'"]+)['"]/);
    if (chAssign) console.log('ch =', chAssign[1]);
    
    // Also look for patterns like: var ch="..."  or .ch="..."
    const chVar = res.body.match(/\.ch\s*=\s*['"]([^'"]+)['"]/);
    if (chVar) console.log('.ch =', chVar[1]);
    
    // Check for any 12-char hex hash (typical apiAsset format)
    const hashes = res.body.match(/\b[a-f0-9]{12}\b/g);
    if (hashes) console.log('12-char hashes:', [...new Set(hashes)].slice(0, 10));
    
    // Print a section around 'ch'
    const idx = res.body.indexOf('"ch"');
    if (idx > -1) console.log('\nAround "ch":', res.body.slice(Math.max(0, idx - 50), idx + 200));
    const idx2 = res.body.indexOf("'ch'");
    if (idx2 > -1) console.log('\nAround \'ch\':', res.body.slice(Math.max(0, idx2 - 50), idx2 + 200));
  }
}

main().catch(e => console.error(e));
