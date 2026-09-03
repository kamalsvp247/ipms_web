# Captcha live-bundle encrypt sidecar

A persistent Node process that loads the live IVAC bundle once and runs its real
encrypt modules, so captcha token transformation stays byte-correct across IVAC
algorithm rotations **without re-porting the PHP algorithm**.

- Script: `app/Scripts/captcha_encrypt_server.cjs` (uses `captcha_live_runtime.cjs`)
- Bundle: `storage/app/captcha/ivac-bundle.js` (refreshed by the Algorithm Monitor)
- Meta:   `storage/app/captcha/encrypt_meta.json` (per-type module map, written by the
  analyzer; the sidecar auto-reloads when it changes)
- Default bind: `127.0.0.1:8787` (set `CAPTCHA_SIDECAR_URL` in `.env` to point the
  portal at it).

## Install

```bash
sudo cp deploy/ipms-captcha-encrypt.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ipms-captcha-encrypt
sudo systemctl status ipms-captcha-encrypt
curl -s http://127.0.0.1:8787/health | jq
```

## Engine selection

`settings.captcha_engine` (set from the Algorithm Monitor UI):

- `php` (default): use the ported PHP transformer. Fast; correct while it matches the
  live bundle.
- `live_js`: use the sidecar for every token; falls back to PHP if the sidecar is down.
- `auto`: PHP per token type until the monitor detects that PHP no longer matches the
  live bundle for that type, then the sidecar (falling back to PHP on sidecar failure).

## Recovery after an IVAC redeploy

1. Open `/captcha-algorithm-monitor`, run analysis with a BD proxy. This refreshes the
   bundle + meta and reloads the sidecar automatically.
2. If a panel shows the seed/offset changed, click Apply for that type.
3. If "PHP matches live" is **No** for a type, either switch the engine to `live_js`
   (zero PHP work — the sidecar runs the site's own code) or re-port PHP.
