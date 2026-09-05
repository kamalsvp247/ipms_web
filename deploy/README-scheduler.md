# Laravel Scheduler Deployment

The scheduler runs the IVAC captcha auto-refresh command every five minutes and keeps the rest of the Laravel scheduled maintenance tasks active. The preferred production setup is the long-running systemd unit `ipms-scheduler.service`.

## Systemd (recommended)

Run on the VPS as `root`, adjusting the application path if the deployment is not `/var/www/html/ipms_web`:

```bash
sudo install -o root -g root -m 0644 deploy/ipms-scheduler.service /etc/systemd/system/ipms-scheduler.service
sudo systemctl daemon-reload
sudo systemctl enable --now ipms-scheduler.service
sudo systemctl status ipms-scheduler.service
sudo journalctl -u ipms-scheduler.service -f
```

The application must be readable by `www-data`, and Laravel's writable paths must remain writable:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan schedule:list
```

The unit uses `php8.3` at `/usr/bin/php8.3`, matching the supported production runtime. If the VPS uses another PHP path, update `ExecStart` before installation.

## Cron fallback

If systemd is unavailable, install this as the `www-data` user's crontab. Laravel's scheduler evaluates due tasks once per minute; it will run the captcha auto-refresh every five minutes according to `routes/console.php`.

```cron
* * * * * cd /var/www/html/ipms_web && /usr/bin/php8.3 artisan schedule:run --no-interaction >> /var/log/ipms-scheduler.log 2>&1
```

Do not run both systemd and cron at the same time, because scheduled tasks may overlap. The application-level `withoutOverlapping()` protections remain enabled for the captcha refresh and repair commands.

## Live verification

Confirm the scheduler is registered and the IVAC retry command is present:

```bash
cd /var/www/html/ipms_web
sudo -u www-data php artisan schedule:list | grep captcha-algorithm
sudo journalctl -u ipms-scheduler --since "10 minutes ago" | grep captcha-algorithm
```

When IVAC is serving the Cloudflare booking notice, the command logs a transient probe failure and keeps the last-known-good bundle. Once the notice lifts, the next five-minute run downloads, verifies, and activates the new live bundle automatically.
