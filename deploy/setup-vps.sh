#!/usr/bin/env bash
#
# Instalasi SMART PRB di VPS Ubuntu 24.04 (sekali jalan, sebagai root).
#
#   sudo bash setup-vps.sh <domain> <email-sertifikat> [url-repo]
#
# Contoh:
#   sudo bash setup-vps.sh 103-150-10-20.sslip.io saya@email.com git@github.com:tropicalforet/smart-prb.git
#
# Hasil: Nginx + PHP 8.5 + SQLite, HTTPS (Let's Encrypt), akun admin,
# dan DATA DEMO fiktif. Data pasien asli TIDAK pernah dikirim ke server.
set -euo pipefail

DOMAIN="${1:?Isi domain, mis. 103-150-10-20.sslip.io}"
EMAIL="${2:?Isi email untuk sertifikat HTTPS}"
REPO="${3:-git@github.com:tropicalforet/smart-prb.git}"
APP_DIR=/var/www/smart-prb
PHP_VER=8.5

echo "==> 1/7 Paket sistem (Nginx, PHP ${PHP_VER}, Node 22, Certbot)"
apt-get update -y
apt-get install -y software-properties-common
# PHP terbaru dari PPA ondrej/php (bawaan Ubuntu 24.04 hanya PHP 8.3)
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y nginx git unzip curl ufw certbot python3-certbot-nginx \
    php${PHP_VER}-fpm php${PHP_VER}-cli php${PHP_VER}-sqlite3 php${PHP_VER}-mbstring \
    php${PHP_VER}-xml php${PHP_VER}-curl php${PHP_VER}-zip php${PHP_VER}-bcmath \
    php${PHP_VER}-intl php${PHP_VER}-gd
update-alternatives --set php "/usr/bin/php${PHP_VER}"
if ! command -v node >/dev/null || [ "$(node -v | cut -d. -f1 | tr -d v)" -lt 22 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> 2/7 Ambil kode dari GitHub"
if [ ! -d "$APP_DIR/.git" ]; then
    git clone "$REPO" "$APP_DIR"
fi
cd "$APP_DIR"

echo "==> 3/7 Konfigurasi .env (production)"
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i \
        -e "s|^APP_NAME=.*|APP_NAME=\"Dashboard PRB\"|" \
        -e "s|^APP_ENV=.*|APP_ENV=production|" \
        -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
        -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
        -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
        .env
    echo "SESSION_SECURE_COOKIE=true" >> .env
fi
touch database/database.sqlite

echo "==> 4/7 Dependensi & build aset"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
npm ci
npm run build

echo "==> 5/7 Database, akun admin, data demo"
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache database

echo "==> 6/7 Nginx"
sed -e "s|__DOMAIN__|${DOMAIN}|g" -e "s|__PHP_VER__|${PHP_VER}|g" deploy/nginx-smart-prb.conf > /etc/nginx/sites-available/smart-prb
ln -sf /etc/nginx/sites-available/smart-prb /etc/nginx/sites-enabled/smart-prb
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> 7/7 Firewall & HTTPS"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect

echo
echo "Selesai. Buka https://${DOMAIN}"
echo "Password admin ada di baris 'password acak' pada langkah 5/7 di atas (catat, lalu ganti lewat menu Ganti Password)."
