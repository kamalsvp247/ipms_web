#!/usr/bin/env bash
set -euo pipefail

exec php artisan queue:work redis \
  --queue=captcha_priority,captcha,payments,scan \
  --tries=1 \
  --timeout=330 \
  --sleep=1 \
  --no-interaction
