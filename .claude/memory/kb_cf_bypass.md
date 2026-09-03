---
name: kb-cf-bypass
description: CF bypass DNS override via OkHttp Dns lambda; bypass_ips table; shared connection pool
metadata: 
  node_type: memory
  type: project
  originSessionId: 3a6d00cb-ef54-40fb-94ff-579f237c0d93
---

## Bypass Mechanism (OkHttp3, current)

`IvacHttpClient.bypass(ip, phone)` builds an OkHttp client with a custom `Dns` lambda:
- Intercepts `api.ivacbd.com` lookups → returns `InetAddress.getByName(bypassIp)`
- All other hostnames resolved by system DNS
- URL stays `https://api.ivacbd.com/...` — TLS SNI set correctly by OkHttp automatically
- No Host header manipulation or URL rewriting needed

**Deleted (April 2026 — OkHttp rollback):** `BypassDnsResolverProvider.java` (JVM SPI), `CachingDns.java`, `HttpClientFactory.java` — these were part of a JDK HttpClient approach that was reverted.

## Shared Connection Pool

All `IvacHttpClient` instances (primary + all bypass) share `SHARED_POOL`:
- 100 idle connections, 15-minute keepalive
- Active connections are never capped by pool — only idle reuse is limited
- Each bypass IP is a different host (`api.ivacbd.com` mapped differently per client) so cross-client connection reuse is impossible; pool benefits are minimal but keep idle sockets warm

## Portal DB (`bypass_ips` table)

- `BypassIp` model: `id`, `label`, `ip`, `is_default`, `last_ping_ms`, `last_pinged_at`
- `AgentSlot.bypass_ip_id` FK — each slot can have a specific IP assigned
- Fallback: `BypassIp::getDefault()` returns `is_default=true` row
- `cfBypassIp` in config: `$slot?->bypassIp?->ip ?? ($slot ? BypassIp::getDefault()?->ip : null)`
