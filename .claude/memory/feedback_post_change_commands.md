---
name: Post-change commands for Laravel project
description: After every change to the Laravel project, run ownership fix + npm build
type: feedback
originSessionId: 265e0407-e1ac-438a-854c-e3c23cd62315
---
After every change to the Laravel project, always run:

```
chown www-data:www-data /var/www/html/ipms_web/ -R && chmod 775 /var/www/html/ipms_web/ -R && npm run build
```

**Why:** The web server runs as www-data; files owned by root break Apache/PHP-FPM. The npm build compiles Vue/Inertia assets so frontend changes are visible.

**How to apply:** Run this single chained command at the end of every session where Laravel or Vue files were changed — before reporting work as done.
