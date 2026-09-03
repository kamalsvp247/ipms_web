#!/usr/bin/env python3
"""Fetch an IVAC invoice PDF through Cloudflare using cloudscraper + the BD proxy.

api.ivacbd.com sits behind a Cloudflare managed challenge that a plain cURL/HTTP-proxy
request cannot pass (it returns a 403 "Just a moment..." page). cloudscraper solves the
challenge — the same approach analyze_captcha_algo.py uses for the captcha bundle — while
tunnelling through the BD residential proxy so the request also exits from a Bangladesh IP.

Usage:
    python3 fetch_invoice.py <output_path>

Reads a JSON job from stdin: {"url": ..., "jwt": ..., "proxy": ..., "x_token": ...} (jwt/proxy/
x_token go via stdin, not argv, so they never appear in the process list). When x_token is
present it is sent as the raw Cloudflare Turnstile token in the x-token header. Writes the response body to
<output_path> and prints a JSON status line to stdout:
    {"status": <int>, "content_type": <str>, "size": <int>, "error": <str|null>}
"""

import json
import sys


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"status": 0, "error": "missing output path"}))
        return 1

    output_path = sys.argv[1]

    try:
        job = json.load(sys.stdin)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"status": 0, "error": f"invalid job json: {exc}"}))
        return 1

    url = job.get("url")
    jwt = job.get("jwt")
    proxy = job.get("proxy")
    x_token = job.get("x_token")

    if not url or not jwt:
        print(json.dumps({"status": 0, "error": "url and jwt are required"}))
        return 1

    try:
        import cloudscraper
    except ImportError:
        print(json.dumps({"status": 0, "error": "cloudscraper not installed"}))
        return 1

    proxies = {"http": proxy, "https": proxy} if proxy else None
    headers = {
        "Accept": "application/pdf,application/json,*/*",
        "Authorization": "Bearer " + jwt,
    }
    if x_token:
        headers["x-token"] = x_token

    try:
        scraper = cloudscraper.create_scraper()
        resp = scraper.get(url, proxies=proxies, headers=headers, timeout=45)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"status": 0, "error": f"{type(exc).__name__}: {exc}"}))
        return 1

    body = resp.content or b""
    try:
        with open(output_path, "wb") as handle:
            handle.write(body)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"status": resp.status_code, "error": f"write failed: {exc}"}))
        return 1

    print(json.dumps({
        "status": resp.status_code,
        "content_type": resp.headers.get("content-type", ""),
        "size": len(body),
        "error": None,
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
