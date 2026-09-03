---
name: feedback-captcha-storage-perms
description: storage/app/captcha gets root-owned when analyze script runs as root — fix with chown www-data
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 0b03aa66-3ecf-4c44-b941-494c58b5c43e
---

If the captcha analyzer fails with `Permission denied` on a `.tmp` file under `storage/app/captcha/`, the directory was written as root (manual script run or sudo).

Fix: `sudo chown -R www-data:www-data /var/www/html/ipms_web/storage/app/captcha`

**Why:** The Python analyzer overwrites `ivac-bundle.js` and `encrypt_meta.json` there. If any prior run left those files owned by root, `www-data` (the web process) cannot create temp files alongside them.

**How to apply:** Any time the Algorithm Monitor analyze call returns a permission error, run the chown above.
