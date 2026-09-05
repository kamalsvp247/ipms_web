#!/usr/bin/env bash
set -euo pipefail

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan event:cache
php artisan view:cache

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
