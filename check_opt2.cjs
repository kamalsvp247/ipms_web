const https = require('https');
const zlib = require('zlib');
const WIDGET_HOST = 'challenges.cloudflare.com';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
const bust = Math.random().toString(36).slice(2,7);
const path = '/cdn-cgi/challenge-platform/h/g/turnstile/f/av0/rch/' + bust + '/0x4AAAAAACghKkJHL1t7UkuZ/auto/fbE/new/normal?lang=auto';

const req = https.request({host: WIDGET_HOST, path, method:'GET', headers:{
  'user-agent': UA,
  accept: 'text/html',
  referer: 'https://appointment.ivacbd.com/',
  'sec-ch-ua': '"Not/A)Brand";v="99", "Chromium";v="148"',
  'sec-ch-ua-mobile': '?0',
  'sec-ch-ua-platform': '"Linux"',
  'sec-fetch-dest': 'iframe',
  'sec-fetch-mode': 'navigate',
  'sec-fetch-site': 'cross-site'
}}, res => {
  const chunks = [];
  res.on('data', c => chunks.push(c));
  res.on('end', () => {
    const buf = Buffer.concat(chunks);
    const enc = res.headers['content-encoding'];
    let html;
    try {
      if (enc === 'br') html = zlib.brotliDecompressSync(buf).toString('utf8');
      else if (enc === 'gzip') html = zlib.gunzipSync(buf).toString('utf8');
      else if (enc === 'deflate') html = zlib.inflateSync(buf).toString('utf8');
      else html = buf.toString('utf8');
    } catch(e) { html = buf.toString('utf8'); }

    // Extract the raw _cf_chl_opt assignment
    const m = html.match(/_cf_chl_opt\s*=\s*(\{[^;]+\})/);
    if (m) {
      console.log('=== Raw _cf_chl_opt ===');
      console.log(m[1].slice(0, 2000));
    }

    // Also look for ch/api_asset in the HTML
    const chMatch = html.match(/['"]ch['"]\s*:\s*['"]([^'"]+)['"]/);
    if (chMatch) console.log('\nch =', chMatch[1]);

    // Look for api_asset  
    const apiMatch = html.match(/api_asset['"]\s*:\s*['"]([^'"]+)['"]/);
    if (apiMatch) console.log('api_asset =', apiMatch[1]);

    // Look for any hash-like value that could be apiAsset
    const hashMatch = html.match(/\/v0\/api\.js\?[^'"]*ch=([^&'"]+)/);
    if (hashMatch) console.log('api.js ch param =', hashMatch[1]);
  });
});
req.end();
