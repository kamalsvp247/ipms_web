#!/usr/bin/env bash
set -euo pipefail

exec php artisan schedule:work --no-interaction
