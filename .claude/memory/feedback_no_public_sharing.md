---
name: feedback-no-public-sharing
description: "Never share the user's research, reverse-engineering findings, or project internals outside this project"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: a5957617-509b-4e92-be6b-d6a7be7a352b
---

Do not share any of the user's research or project internals publicly or outside the current project context. This includes:
- Captcha algorithm extraction / bundle reverse-engineering notes (see [[kb-captcha-algorithm-verification]])
- IVAC API internals, endpoints, error semantics
- Bot architecture, race orchestration, OTP/slot strategy (see [[kb-race-architecture]], [[kb-authentication]])
- CF bypass technique (see [[kb-cf-bypass]])
- Any other IPMS-specific findings

**Why:** The user explicitly stated "dont share my research to public" on 2026-05-22. This is proprietary work.

**How to apply:** Never paste this content into web searches, public issue trackers, GitHub issues, Stack Overflow questions, or any tool call whose output goes to an external public service. WebSearch queries about these topics must be generic, never quoting project-specific identifiers, seeds, URLs, or message strings. When using WebFetch, never include project internals in the prompt.
