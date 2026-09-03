---
name: VPS Manager Feature
description: LightNode VPS auto-provisioning feature added April 2026
type: project
originSessionId: fd46d798-2ef1-4272-902f-a3f52c3d1473
---
Portal can now provision/destroy LightNode VPS instances automatically and install the bot.

**Why:** Automate the manual process of creating VPS in LightNode console, SSHing in, and running install.sh.

**Key files:**
- `app/Services/LightNodeClient.php` — LightNode API wrapper (auth: `x-open-token` header, base: `https://openapi.lightnode.com`)
- `app/Jobs/ProvisionVpsJob.php` — Redis queue `vps`, timeout 600s; polls for IP then SSH-installs bot via phpseclib
- `app/Jobs/DestroyVpsJob.php` — releases VPS, keeps AgentSlot offline
- `app/Http/Controllers/Api/VpsManagerController.php`
- `app/Models/VpsInstance.php` — `root_password` uses `encrypted` cast
- `resources/js/pages/VpsManager/Index.vue`
- systemd: `ipms-vps-worker.service` (queue=vps)

**No user_data on LightNode** — confirmed. SSH via phpseclib after IP becomes available.

**How to apply:** When modifying provisioning flow, remember: LightNode create returns `ecsResourceUUID` immediately but IP requires polling `GET /instance/detail` until `ecsStatus=STARTED`. Password must meet LightNode rules (letters + digits + special chars from `!@#$*-+=?`).
