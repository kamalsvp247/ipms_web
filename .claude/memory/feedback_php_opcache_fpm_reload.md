---
name: feedback-php-opcache-fpm-reload
description: "After editing PHP, reload php8.4-fpm — live FPM serves stale OPcache even though tinker (fresh process) shows the new code"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: c724d879-9ae2-4663-8fd5-f9fccaf2b462
---

After editing any PHP that the live site executes (controllers, models, services), the change does NOT take effect on `https://ipms.senda.fit` until **PHP-FPM is reloaded** — its workers serve the OLD code from OPcache. Run: `php artisan optimize:clear && systemctl reload php8.4-fpm` (service is `php8.4-fpm.service`; `reload` = graceful worker recycle, clears OPcache).

**Why:** OPcache likely has `validate_timestamps=0` in prod, so editing the file alone never invalidates the cache. Recent repo history already shows this pattern (commit "reload PHP-FPM to expose unapprove route").

**The trap that wastes time:** `mcp__laravel-boost__tinker` and `php artisan` run in a **fresh CLI process** that compiles the current source — so a change verifies GREEN in tinker while the browser still gets the stale controller via FPM. Symptom: "I verified it returns the data, but the page still doesn't show it." Don't trust a tinker-only check for a live-page bug — reload FPM, or inspect the actual HTTP JSON in DevTools.

**How to apply:** Editing PHP for a live-page change → always `optimize:clear` + `reload php8.4-fpm` before telling the user to refresh. Frontend (`.vue`) changes separately need `npm run build` (built bundle is also a cache layer) + `chown -R www-data public/build`. See [[feedback_build_and_permissions]].
