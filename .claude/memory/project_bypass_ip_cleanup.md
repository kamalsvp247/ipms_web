---
name: project-bypass-ip-cleanup
description: Bypass-IP validity rule (500/503) and the cleanup undo/restore feature
metadata: 
  node_type: memory
  type: project
  originSessionId: 27e79302-a0d3-4909-82c2-98b758358f85
---

CF bypass-IP validity & cleanup logic in `BypassIpController.php` + `BypassIpScanner.php` (added May 2026).

**Validity rule (UPDATED June 10 2026 — cert-based)** — the old `500/503` status heuristic was broken: **any** AWS ELB returns `503` to an unknown `Host: api.ivacbd.com`, so an AWS-range scan produced ~80 false positives (osstem.com, nsdcindia.org, prokabaddi.com … ELBs). The real test is now the **TLS peer certificate via SNI**: an IP is a genuine IVAC origin only if its leaf cert Subject/SAN contains `ivacbd` (cert is `*.ivacbd.com`, DigiCert/RapidSSL). Cert serving is config-time, so it identifies the ELB even when the backend 503s / has no healthy targets.

- Helper: `BypassIpScanner::certMatchesOrigin(?array $certInfo): bool` + const `ORIGIN_CERT_NEEDLE='ivacbd'`. Reads `curl_getinfo($ch, CURLINFO_CERTINFO)` leaf `Subject` / `X509v3 Subject Alternative Name` (needs `CURLOPT_CERTINFO=>true`; works with curl_multi).
- Gated in `BypassIpScanner::probeChunk()`, `BypassIpController::batchCheck()` (both now require cert match, not status), and `ping()` (records `cert_match` + sets a "Not an ivacbd origin" message on mismatch). Unit test: `tests/Unit/BypassIpScannerCertTest.php`.
- **Reality**: the only real ELB found (`13.207.137.254`, AWS ap-south-1, `awselb/2.0`) still returns a bare ELB `503` to direct/bypass traffic — origin appears **Cloudflare-locked** (backend only routed for CF origin-pull). Public DNS for `api.ivacbd.com` is Cloudflare-only (`104.26.14.90/104.26.15.90/172.67.68.164`). The 80 garbage rows were cleaned out (snapshot saved for restore) leaving only `13.207.137.254`.

**Legacy note**: `cleanup()` still uses the old `whereNotIn(response_status,[500,503])` SQL and does NOT remove cert-mismatch 503 rows — clean those via a live cert re-check (tinker) if a scan re-adds garbage.

**cleanup()** deletes rows that are NOT valid: `whereNotIn('response_status',[500,503]) OR null OR (500 with wrong/null message)`. So 503 rows are always kept.

**UI status badge (`BypassIps/Index.vue`, June 10 2026)** — a `response_status === 500` ("Service temporarily unavailable") is the **healthy** signal (origin reached; the GET probe hits a POST-only endpoint). The badge was wrongly painting `>=500` as a red ✗ failure. Fixed: **500/503 (and any 2xx/3xx) → green ✓** ("Reached the IVAC origin — bypass is working"); **4xx → orange ✓**; other 5xx (502/504) / null → red ✗. Backend already counted 500 as reachable (`reachable=true` for httpCode>=200) — only the badge color was wrong. Rebuilt + chowned.

**Restore (undo cleanup)** — no soft-deletes on `bypass_ips`, so `cleanup()` snapshots deleted rows into cache key `bypass_ips:last_cleanup_snapshot` (24h TTL) before hard delete. `restoreCleanup()` (`POST /api/bypass-ips/cleanup/restore`) re-inserts them, skipping IPs re-added since, then clears the snapshot. UI: "Restore" button appears next to "Clean Up" after a deletion. **Only protects cleanups run after this change** — earlier hard-deletes are unrecoverable except via DB backup or re-scan (`ip_scan_results` may still hold found IPs).

Tested in `tests/Feature/Api/BypassIpCleanupTest.php`. See [[kb_cf_bypass]].
