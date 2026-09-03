const src = require('fs').readFileSync('storage/app/captcha/last_bootstrap.js','utf8');

// Look for the main control flow after the IIFE setup
// Search for the vB solve function and its caller patterns
// The challenge flow: init → requestExtraParams → translationInit → solve (vB)
// vB makes the actual HTTP calls

// Search for XHR.open or fetch patterns which indicate solving
const xhrOpen = [...src.matchAll(/\.open\(/g)];
console.log('.open() calls:', xhrOpen.length);
for (const m of xhrOpen.slice(0, 5)) {
  console.log('  offset:', m.index);
  console.log('  context:', src.slice(Math.max(0, m.index-60), m.index+60));
  console.log('---');
}

// Search for fetch calls
const fetchCalls = [...src.matchAll(/fetch\(/g)];
console.log('fetch() calls:', fetchCalls.length);
for (const m of fetchCalls.slice(0, 5)) {
  console.log('  offset:', m.index);
  console.log('  context:', src.slice(Math.max(0, m.index-60), m.index+60));
  console.log('---');
}

// Look for the 'execute' event handling in the challenge
const execIdx = src.indexOf('"execute"');
if (execIdx > -1) {
  console.log('\n"execute" at offset', execIdx);
  console.log(src.slice(Math.max(0, execIdx-200), execIdx+200));
}

// Look for food event - this is the ping before solving
const foodIdx = src.indexOf('"food"');
if (foodIdx > -1) {
  console.log('\n"food" at offset', foodIdx);
  console.log(src.slice(Math.max(0, foodIdx-200), foodIdx+200));
}

// Search for 'timeout' pattern that might relate to overrunBegin
const timeoutMatches = [...src.matchAll(/timeout/gi)];
console.log('\ntimeout occurrences:', timeoutMatches.length);
for (const m of timeoutMatches.slice(0, 5)) {
  console.log('  offset:', m.index, 'context:', src.slice(Math.max(0, m.index-40), m.index+40));
}
