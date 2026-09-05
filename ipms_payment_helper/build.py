#!/usr/bin/env python3
"""Package the DURONTO IVAC Payment Helper browser extension into a distributable zip.

The zip is written to storage/app/public/extensions/ so the portal's public
landing page (route: payment-helper) can serve it for download.
"""

import json
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parent
SRC = HERE / "duronto-payment-helper"
OUT_DIR = REPO / "storage" / "app" / "public" / "extensions"
OUT_DIR.mkdir(parents=True, exist_ok=True)

version = json.loads((SRC / "manifest.json").read_text())["version"]
out_zip = OUT_DIR / "duronto-payment-helper.zip"

with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED) as zf:
    for path in sorted(SRC.rglob("*")):
        if path.is_file():
            zf.write(path, path.relative_to(SRC.parent))

print(f"Built {out_zip} (version {version}, {out_zip.stat().st_size} bytes)")
