---
name: reference-bd-proxy
description: BD/HTTP proxy URL for running the captcha Algorithm Monitor / analyze_captcha_algo.py
metadata: 
  node_type: memory
  type: reference
  originSessionId: 32d426d1-0155-4fd3-8a88-fe36960a5091
---

Proxy for fetching the live IVAC bundle in the captcha Algorithm Monitor and `app/Scripts/analyze_captcha_algo.py`:

**Current (June 13 2026 — verified valid, exit IP 163.47.157.57 BD):**
`http://customer-smensulaiman_0O1gd:OTUw=ks3N~8TUD@bd-pr.oxylabs.io:30001`

**Previous (replaced):**
`http://user1:Dhaka%40123@151.158.125.203:1282`

Set as default in `resources/js/pages/CaptchaAlgorithm/Index.vue` (`proxyUrl` ref).
Also persisted in browser `localStorage` key `captcha_monitor_proxy_v2` after first run.
Pass it as the script's first arg or paste into the monitor's proxy field. Used when re-deriving seeds on IVAC redeploy — see [[kb_captcha_algorithm_verification]].
