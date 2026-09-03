---
name: feedback_bot_version_bump
description: Bump BotVersion.VERSION in BotVersion.java whenever a Java bot feature is added or modified
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 016ab6ff-0975-4d5f-9f6e-99ac1b96da1a
---

Whenever any feature modification is made to the Java bot, update the version string in `ipms_java/src/main/java/com/ivac/booking/BotVersion.java`.

**Why:** The portal displays this version per worker so the user can see which VPS slots are running outdated JARs. If the version isn't bumped, the portal can't distinguish old from new builds.

**How to apply:** After any Java bot change (new feature, behaviour change, bug fix that affects runtime), increment `VERSION` following the pattern `blitz_v_X.Y` (e.g. `blitz_v_1.0` → `blitz_v_1.1`). Do this as part of the same change, not as a separate step.
