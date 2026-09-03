---
name: Don't over-investigate small, clearly-scoped changes
description: For small bug fixes / one-line behavior changes, skip the deep investigation and make the change
type: feedback
originSessionId: c984041b-5ed5-45ff-af81-bb845b920ff4
---
When the user asks for a small, well-scoped change (e.g. "change 503 to sleep 4s", "rename this", "swap that") — just do it. Don't trace through Thread.interrupt() propagation, OkHttp internals, executor lifecycles, or hypothetical edge cases first.

**Why:** User explicitly called out "you ate lot of token and think too much" for a one-line slot 503 change. They had already diagnosed the problem and prescribed the fix; the investigation was wasted effort.

**How to apply:** If the user has stated *what* to change and *what value to use*, the only research needed is: (1) find the exact lines to edit, (2) confirm the file compiles. Skip cause-and-effect analysis unless the user asks "why is this happening" or the change has obvious risk (data migration, destructive op, cross-cutting refactor).
