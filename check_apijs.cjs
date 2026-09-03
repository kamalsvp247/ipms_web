const https = require('https');
const zlib = require('zlib');
const req = https.request({
  host: 'challenges.cloudflare.com',
  path: '/turnstile/v0/api.js?onload=__ihcOnload',
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
    console.log('api.js size:', body.length);
    console.log('Status:', res.statusCode);
    // Look for asset hash patterns
    const chRef = body.match(/['"]([a-f0-9]{8,12})['"]/g);
    if (chRef) console.log('Hash-like values:', [...new Set(chRef)].slice(0, 20));
    // The key is: what does api.js call to get the ch value?
    // Search for 'ch' as a key
    const lines = body.split(';').filter(l => l.includes('"ch"') || l.includes("'ch'"));
    console.log('Lines with "ch":', lines.length);
    if (lines.length > 0) console.log('First:', lines[0].slice(0, 200));
    console.log('\nFirst 1000 chars:', body.slice(0, 1000));
  });
});
req.end();
