#!/usr/bin/env python3
"""Make an IVAC API request through Cloudflare using cloudscraper + optional BD proxy.

Plain cURL requests to api.ivacbd.com can be blocked by Cloudflare managed challenges.
cloudscraper handles the challenge negotiation; the BD proxy ensures the request exits
from a Bangladesh IP.

Reads a JSON job from stdin:
{
    "method": "POST"|"GET",
    "url": "https://...",
    "headers": {"Authorization": "Bearer ...", ...},
    "body": {...}|null,
    "proxy": "http://..."|null
}

Prints a JSON result to stdout:
{
    "status_code": int,
    "raw": str,
    "duration_ms": int,
    "error": str|null
}
"""

import json
import sys
import time


def main() -> int:
    try:
        job = json.load(sys.stdin)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"status_code": 0, "raw": "", "duration_ms": 0, "error": f"invalid job json: {exc}"}))
        return 1

    method = (job.get("method") or "GET").upper()
    url = job.get("url")
    headers = job.get("headers") or {}
    body = job.get("body")
    proxy = job.get("proxy")

    if not url:
        print(json.dumps({"status_code": 0, "raw": "", "duration_ms": 0, "error": "url is required"}))
        return 1

    try:
        import cloudscraper
    except ImportError:
        print(json.dumps({"status_code": 0, "raw": "", "duration_ms": 0, "error": "cloudscraper not installed"}))
        return 1

    proxies = {"http": proxy, "https": proxy} if proxy else None

    try:
        scraper = cloudscraper.create_scraper()
        start = time.time()
        resp = scraper.request(method, url, proxies=proxies, headers=headers, json=body if body is not None else None, timeout=60)
        duration_ms = int((time.time() - start) * 1000)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"status_code": 0, "raw": "", "duration_ms": 0, "error": f"{type(exc).__name__}: {exc}"}))
        return 1

    try:
        raw = resp.text
    except Exception:  # noqa: BLE001
        raw = ""

    print(json.dumps({
        "status_code": resp.status_code,
        "raw": raw,
        "duration_ms": duration_ms,
        "error": None,
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
