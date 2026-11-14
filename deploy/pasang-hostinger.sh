#!/usr/bin/env bash
#
# Pasang / perbarui SMART PRB di Hostinger Web Hosting (lewat SSH hPanel).
# Jalankan dari folder proyek di server:
#
#   bash deploy/pasang-hostinger.sh <domain>
#
# Yang dilakukan:
#   - cek PHP 8.5, buat .env production (sekali), composer install
#   - pasang aset frontend dari deploy/keluaran/build.tar.gz
#   - bila ada deploy/keluaran/database.sqlite: pakai sebagai database
#     (database lama di server dibackup dulu)
#   - migrate, cache konfigurasi, arahkan public_html ke folder public/
#
# Aman dijalankan ulang untuk update setelah "git pull".
set -euo pipefail

DOMAIN="${1:?Isi domain, mis. bash deploy/pasang-hostinger.sh prb-namakamu.com}"
cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"
PHP_BIN="${PHP_BIN:-php}"
WEBROOT="$HOME/domains/$DOMAIN/public_html"
KELUARAN="$APP_DIR/deploy/keluaran"
STEMPEL="$(date +%Y%m%d-%H%M%S)"

echo "==> 1/6 Cek PHP"
if [ "$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')" -lt 80500 ]; then
    echo "PHP di terminal: $("$PHP_BIN" -r 'echo PHP_VERSION;'). Proyek ini butuh PHP 8.5."
    echo "Pilih PHP 8.5 di hPanel -> Advanced -> PHP Configuration, lalu buka ulang SSH."
    echo "Atau tunjuk langsung, mis.: PHP_BIN=/opt/alt/php85/usr/bin/php bash deploy/pasang-hostinger.sh $DOMAIN"
    exit 1
fi
if [ ! -d "$HOME/domains/$DOMAIN" ]; then
    echo "Folder $HOME/domains/$DOMAIN tidak ada. Cek nama domain di hPanel."
    exit 1
fi

echo "==> 2/6 Konfigurasi .env & dependensi"
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i \
        -e "s|^APP_NAME=.*|APP_NAME=\"SMART PRB\"|" \
        -e "s|^APP_ENV=.*|APP_ENV=production|" \
        -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
        -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
        -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
        .env
    echo "SESSION_SECURE_COOKIE=true" >> .env
fi
# Hostinger: "composer" bisa masih Composer 1; Composer 2 bernama "composer2"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer2 || command -v composer || true)}"
if [ -z "$COMPOSER_BIN" ]; then
    echo "Composer tidak ditemukan. Di Hostinger gunakan composer2 (paket Premium ke atas)."
    exit 1
fi
if [ "$PHP_BIN" = php ]; then
    COMPOSER_CMD=("$COMPOSER_BIN")
else
    COMPOSER_CMD=("$PHP_BIN" "$COMPOSER_BIN")
fi
if ! "${COMPOSER_CMD[@]}" --version 2>/dev/null | grep -q 'version 2'; then
    echo "Butuh Composer 2 ($COMPOSER_BIN bukan Composer 2). Jalankan dengan COMPOSER_BIN=\$(command -v composer2)."
    exit 1
fi
"${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64' .env || "$PHP_BIN" artisan key:generate --force

echo "==> 3/6 Aset frontend"
if [ -f "$KELUARAN/build.tar.gz" ]; then
    rm -rf public/build
    tar -xzf "$KELUARAN/build.tar.gz" -C public
    rm "$KELUARAN/build.tar.gz"
fi
if [ ! -f public/build/manifest.json ]; then
    echo "public/build belum ada. Jalankan deploy\\siapkan-hostinger.ps1 di laptop lalu unggah build.tar.gz."
    exit 1
fi

echo "==> 4/6 Database"
DB_BARU=0
if [ -f "$KELUARAN/database.sqlite" ]; then
    if [ -f database/database.sqlite ]; then
        cp database/database.sqlite "database/database.sqlite.backup-$STEMPEL"
        echo "Database lama dibackup ke database/database.sqlite.backup-$STEMPEL"
    fi
    mv "$KELUARAN/database.sqlite" database/database.sqlite
    echo "Database dari laptop dipasang."
elif [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    DB_BARU=1
fi
"$PHP_BIN" artisan migrate --force
if [ "$DB_BARU" = 1 ]; then
    "$PHP_BIN" artisan db:seed --class=AdminUserSeeder --force
fi

echo "==> 5/6 Cache & izin folder"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
chmod -R u+rwX,g+rwX storage bootstrap/cache database
chmod 600 .env

echo "==> 6/6 Arahkan public_html ke public/"
if [ "$(readlink "$WEBROOT" 2>/dev/null)" != "$APP_DIR/public" ]; then
    if [ -e "$WEBROOT" ] || [ -L "$WEBROOT" ]; then
        mv "$WEBROOT" "$WEBROOT.lama-$STEMPEL"
        echo "public_html lama dipindah ke public_html.lama-$STEMPEL"
    fi
    ln -s "$APP_DIR/public" "$WEBROOT"
fi

echo
echo "Selesai. Buka https://${DOMAIN}"
