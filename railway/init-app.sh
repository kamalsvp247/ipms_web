#!/usr/bin/env bash
set -euo pipefail

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan event:cache
php artisan view:cache

# Runtime assets are intentionally generated/copied during deployment because
# public/storage is ignored by git and Railway web containers are rebuilt from
# the repository. This keeps the public download pages usable after every
# deploy instead of depending on files left by an older container.
if [[ -f "ipms_payment_helper/build.py" ]]; then
  if command -v python3 >/dev/null 2>&1; then
    python3 ipms_payment_helper/build.py
  elif command -v zip >/dev/null 2>&1; then
    mkdir -p storage/app/public/extensions
    rm -f storage/app/public/extensions/duronto-payment-helper.zip
    (cd ipms_payment_helper && zip -qr ../storage/app/public/extensions/duronto-payment-helper.zip duronto-payment-helper)
  else
    echo "Warning: neither python3 nor zip is available; skipping extension ZIP rebuild."
  fi
fi

if [[ -f "ipms_sms_android/DURONTO.apk" ]]; then
  mkdir -p storage/app/public/apk-releases
  cp -f ipms_sms_android/DURONTO.apk storage/app/public/apk-releases/DURONTO.apk
fi

# Keep the public APK page aligned with the repository artifact. Set
# APK_SYNC_BUNDLED_RELEASE=false when releases are managed exclusively from
# the APK manager UI.
if [[ "${APK_SYNC_BUNDLED_RELEASE:-true}" == "true" && -f "ipms_sms_android/DURONTO.apk" ]]; then
  php artisan apk:sync-bundled
fi

if [[ -n "${BOOTSTRAP_ADMIN_EMAIL:-}" && -n "${BOOTSTRAP_ADMIN_PASSWORD:-}" ]]; then
  php artisan tinker --execute='
    $email = env("BOOTSTRAP_ADMIN_EMAIL");
    $password = env("BOOTSTRAP_ADMIN_PASSWORD");
    $name = env("BOOTSTRAP_ADMIN_NAME", "Administrator");
    $user = \App\Models\User::updateOrCreate(
        ["email" => $email],
        [
            "name" => $name,
            "password" => $password,
            "plain_password" => $password,
            "role" => \App\Models\User::ROLE_SUPER_ADMIN,
            "is_approved" => true,
            "approved_at" => now(),
        ]
    );
    echo "Bootstrap admin ready: {$user->email}\n";
  '
fi
php artisan storage:link || true
