---
name: project-captcha-bundle-versioning
description: Bundle versioning architecture for IVAC captcha — activatable history of downloaded bundles with rollback support
metadata: 
  node_type: memory
  type: project
  originSessionId: aee2d2e7-fdbe-4c3e-9d2b-54bfc7f5ec59
---

Implemented June 15 2026. Lets admins activate any older downloaded IVAC bundle to roll back encryption if a new bundle breaks.

**Why:** `dump_bundle()` in Python always overwrote `ivac-bundle.js` in place — no archive. On a bad IVAC redeploy, there was no rollback. Also, unclean extractions left a mismatched pair (new bundle + old meta).

**How to apply:** When captcha 400 errors appear after an IVAC redeploy and the new bundle extraction is unclean, open the Bundle Versions panel on the Algorithm Monitor and click Activate on the last known-good version.

## Key files
- Migration: `database/migrations/2026_06_15_120000_create_captcha_bundle_versions_table.php`
- Model: `app/Models/CaptchaBundleVersion.php` — `activate()` uses query-builder (not `$this->update()`) to avoid dirty-check skipping the write
- Service: `app/Services/Captcha/CaptchaBundleVersionService.php`
- Backfill: `php artisan captcha:import-current-bundle` (idempotent, run once on deploy)
- UI panel: "Bundle Versions" in right sidebar of `resources/js/pages/CaptchaAlgorithm/Index.vue`
- Routes: `GET/POST/PATCH/DELETE /api/captcha-algorithm/versions[/{id}[/activate]]`

## Storage layout
- Versioned bundles: `storage/app/captcha/bundles/<sha256>.js` (content-addressed, dedup by hash)
- Active paths (sidecar reads): `storage/app/captcha/ivac-bundle.js` + `encrypt_meta.json`
- Activation mirrors chosen version's files → active paths atomically (bundle first, meta second)

## Invariants
- Meta is the commit marker: sidecar polls meta mtime only (bundle written first, meta last)
- Active or labeled versions are never pruned; retention = last 10
- Unclean extraction: broken bundle stored for inspection but NOT activated; `reconcileActiveFiles()` restores last-good active pair

## Key design decision
`CaptchaAlgorithmService::analyze()` does NOT call `sidecar->reload()` directly anymore — `registerBundleVersion()` always handles it: `activate()` on clean, `reconcileActiveFiles()` on unclean. Removing the early reload prevents the sidecar from briefly loading a bad bundle on unclean runs.
