---
name: feedback_short_simple_summaries
description: "User wants summaries always short and in simple language — a few plain lines, no long write-ups"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: f8b7897e-4c88-4df1-8f9c-90189105ecee
  modified: 2026-07-29T11:01:45.326Z
---

Always summarize in **short, simple language**. A few plain lines: what was broken, what was done, what it means. No long structured write-ups, no evidence dumps, no tables unless asked.

**Why:** the user reads these on a terminal while operating a live system; long reports bury the point. Asked for it on Jul 29 2026 right after a detailed multi-section root-cause report on the auto-payment log page.

**How to apply:** do the deep investigation, but report only the conclusion and the fix in a handful of short sentences. Keep detail in the code, the tests and memory files — offer more only if asked. Pairs with [[feedback_minimal_for_small_changes]].
