#!/usr/bin/env bash
#
# Perbarui SMART PRB di VPS setelah ada perubahan yang di-push ke GitHub.
#
#   sudo bash /var/www/smart-prb/deploy/update.sh
set -euo pipefail

cd /var/www/smart-prb
export COMPOSER_ALLOW_SUPERUSER=1

php artisan down || true
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache database
php artisan up

echo "Update selesai."
