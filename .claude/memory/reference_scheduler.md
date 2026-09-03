---
name: reference-scheduler
description: How Laravel scheduler is wired for ipms_web (cron entry + Schedule::call tasks); previously dead before May 30 2026
metadata: 
  node_type: memory
  type: reference
  originSessionId: 14ccf61c-68d5-4592-9715-647b40b1416b
---

The ipms_web Laravel scheduler runs from a **root** crontab entry (`/usr/bin/php8.4`, absolute):

```
* * * * * cd /var/www/html/ipms_web && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

**The `cd` prefix is load-bearing and has silently gone missing before.** On Jul 6 2026 the live crontab was `* * * * * /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1` with NO `cd` — cron runs from `/root` where there is no `artisan`, so every tick died with "Could not open input file: artisan", swallowed by `>> /dev/null`. The whole scheduler was dead: 8.6k stale `method=LOG` bot_logs rows, captcha_requests from 5 days prior, and `captcha-algorithm:auto-refresh` (IVAC self-healer) never ran. Fixed by restoring the `cd`. Symptom to watch: stale rows in tables that should be purged every minute → check `sudo crontab -l` for the missing `cd`.

Tasks live in `routes/console.php` via `Schedule::call(...)` and `Schedule::command(...)`. Includes:
- captcha_tokens cleanup (every minute, >120s old)
- captcha_requests cleanup (every minute, >5 min old)
- bot_logs noise purge (every 5 min — status_code 504/502/503/401 + null+error_type)
- `bypass-ips:scan-subnets` (daily)
- `gmail:renew-watch` (daily), `gmail:sync-fallback` (every 10 min)

**History**: Before May 30 2026 there was NO cron entry for ipms_web's `schedule:run` — the Schedule::call entries were dead. Captcha tokens were cleaned only by `FillCaptchaPoolCommand::purgeExpired()` inside the captcha worker loop. Don't assume new `Schedule::call` entries work unless this cron is still in place — verify with `sudo crontab -l | grep ipms_web`.

**Purge criteria mirror the manual "Purge Noise" button** at `/log-analysis` (DbBotLogsController::purgeNoise) — keep them in sync if either changes.
