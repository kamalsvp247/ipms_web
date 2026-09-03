---
name: feedback_build_and_permissions
description: "After ANY change (frontend or backend), always run npm run build then chown + chmod — do it automatically, don't wait to be asked"
metadata: 
  node_node: memory
  type: feedback
  originSessionId: 4c2b92fc-ae7c-418e-bf5b-ceb673f4aa6f
---

After **every change** (Vue, PHP, JS, config — anything), always run all three automatically without waiting for the user to ask:

```bash
npm run build && chown -R www-data:www-data /var/www/html/ipms_web && chmod -R 775 /var/www/html/ipms_web
```

**Why:** npm run build compiles Vue/Tailwind assets. chown/chmod ensures www-data can serve everything. User explicitly asked for this to happen automatically after every change.

**How to apply:** Run immediately after finishing any code edit, before reporting the task done. Do not ask, do not wait — just run it.
