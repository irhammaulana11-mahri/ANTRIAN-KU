#!/usr/bin/env bash
# Update rilis ANTRIAN-KU di server yang sudah berjalan. Jalankan sebagai pengguna deploy (bukan root) dengan sudo tersedia:
#   cd /var/www/ANTRIAN-KU && ./deploy/scripts/update-release.sh
# Tidak mengubah backend/.env dan tidak menjalankan migrate:fresh atau perintah destruktif lain.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/ANTRIAN-KU}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"
WEB_USER="${WEB_USER:-www-data}"
SUPERVISOR_PROGRAMS="${SUPERVISOR_PROGRAMS:-all}"

log() { printf '[update-release] %s\n' "$*"; }

cd "$APP_DIR"
log "Mengambil kode terbaru"
git pull --ff-only

cd "$APP_DIR/backend"
log "Autoload Composer (classmap-authoritative: wajib diulang agar kelas baru dikenali)"
composer install --no-dev --classmap-authoritative --no-interaction

log "Migration dan izin/role"
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force

log "Membersihkan cache"
php artisan optimize:clear

# Perintah artisan di atas dijalankan oleh pengguna deploy; storage dan cache harus bisa ditulis worker (www-data).
log "Memperbaiki kepemilikan storage dan bootstrap/cache"
sudo chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

log "Build frontend"
cd "$APP_DIR/frontend"
npm ci
npm run build

log "Memuat ulang PHP-FPM dan memulai ulang worker/Reverb"
sudo systemctl reload "$PHP_FPM_SERVICE"
sudo supervisorctl restart "$SUPERVISOR_PROGRAMS"

log "Status"
sudo supervisorctl status
cd "$APP_DIR/backend"
php artisan migrate:status | grep -i pending || log "Tidak ada migration pending"
log "Selesai. Pengguna perlu login ulang bila ada izin baru; pasangkan ulang TV/kiosk bila nama channel realtime berubah."
