---
name: project-reverb-websocket
description: Laravel Reverb WebSocket server setup — systemd + Apache proxy for wss:// on HTTPS
metadata: 
  node_type: memory
  type: project
  originSessionId: aee2d2e7-fdbe-4c3e-9d2b-54bfc7f5ec59
---

Set up June 15 2026. Only the Gmail page (`resources/js/pages/Gmail/Index.vue`) uses WebSockets (private channel `gmail.<userId>`).

**Why:** Site runs on HTTPS (`ipms.senda.fit`). Browser blocked `ws://localhost:8080` as mixed content and tried upgrading to `wss://localhost:8080` (no TLS there either). Result: every page load emitted a WebSocket connection error in the console.

**How to apply:** If Reverb goes down, run `systemctl start ipms-reverb`. If Apache WebSocket proxy stops working, check `systemctl status ipms-reverb` and `apache2ctl configtest`.

## Architecture
- Reverb listens on `127.0.0.1:8080` (HTTP, local only)
- Apache proxies `/app` → `ws://127.0.0.1:8080/app` (WebSocket tunnel) and `/apps` → HTTP management
- Frontend connects to `wss://ipms.senda.fit/app/...` (port 443, TLS terminated by Apache)

## Key config
- Systemd: `/etc/systemd/system/ipms-reverb.service` (User=www-data, auto-restart, logs to `/var/log/ipms-reverb.log`)
- Apache: `/etc/apache2/sites-available/ipms.senda.fit-le-ssl.conf` — `ProxyPreserveHost On` + `<Location /app>` ws proxy
- `.env`: `VITE_REVERB_HOST=ipms.senda.fit`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https` (server-side `REVERB_HOST=localhost` / `PORT=8080` unchanged)
- Requires `proxy_wstunnel_module` (already enabled)
