# Fase 7 — Deployment Ubuntu Server

Panduan ini menargetkan Ubuntu 24.04 LTS, PHP 8.5, PostgreSQL, Redis, Nginx, Supervisor, dan Reverb. Perintah instalasi membutuhkan root dan belum dijalankan otomatis dari repository ini.

## 1. Server kosong atau server existing

Installer bersifat idempotent dan aman untuk server yang sudah memiliki aplikasi:

- paket yang sudah terpasang tidak dihapus atau dipasang ulang;
- konfigurasi Nginx, Supervisor, systemd, dan backup yang sudah ada tidak ditimpa;
- backend/.env tidak dibuat, diubah, atau diganti otomatis;
- service yang sudah aktif tidak direstart secara default;
- konfigurasi repository hanya ditambahkan jika file target belum ada.

Jalankan pemeriksaan aman terlebih dahulu:

    sudo INSTALL_CONFIGS=0 ./deploy/install-ubuntu.sh

Jika ingin menambahkan hanya paket yang hilang dan file konfigurasi yang belum ada:

    sudo ./deploy/install-ubuntu.sh --install-missing-packages

Untuk mengaktifkan service yang belum aktif, lakukan secara eksplisit:

    sudo ./deploy/install-ubuntu.sh --enable-services

Jika target konfigurasi sudah ada, installer hanya memberi peringatan. Review dan merge perubahan secara manual agar konfigurasi aplikasi lain tetap aman.

Pada server kosong, perintah manual berikut tetap dapat digunakan:

```bash
sudo apt update
sudo apt install -y nginx redis-server supervisor postgresql postgresql-contrib git unzip curl ca-certificates
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.5-fpm php8.5-cli php8.5-pgsql php8.5-redis php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath
```

Install Composer resmi, clone source ke `/var/www/ANTRIAN-KU`, lalu jalankan Laravel dari `backend/`:

```bash
cd /var/www/ANTRIAN-KU/backend
composer install --no-dev --classmap-authoritative
cp .env.example .env
php artisan key:generate
```

## 2. PostgreSQL dan environment

```bash
sudo -u postgres createuser --pwprompt sqms
sudo -u postgres createdb --owner=sqms antrian_db
```

Isi `.env` production dengan `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, password database kuat, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb`, dan secret Reverb acak. Jangan commit `.env`.

> Catatan: nama database production adalah `antrian_db`, sedangkan contoh role PostgreSQL tetap `sqms` agar kompatibel dengan konfigurasi yang sudah ada. Jika role di server berbeda, sesuaikan `DB_USERNAME`, `PGUSER`, dan hak aksesnya secara konsisten.

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd /var/www/ANTRIAN-KU/frontend && npm ci && npm run build
```

## 3. Nginx, PHP-FPM, Redis

Salin `deploy/nginx/sqms.conf` ke `/etc/nginx/sites-available/sqms`, ganti `server_name antrian-ku.example.com` dengan domain/IP sebenarnya, aktifkan symlink, lalu:

```bash
sudo ln -s /etc/nginx/sites-available/sqms /etc/nginx/sites-enabled/sqms
sudo nginx -t
sudo systemctl enable --now nginx php8.5-fpm redis-server
```

Pasang `deploy/supervisor/sqms-worker.conf`, lalu:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status sqms-worker:*
```

## 4. Reverb

```bash
sudo cp deploy/systemd/sqms-reverb.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sqms-reverb
sudo systemctl status sqms-reverb
```

Jika TLS dihentikan di Nginx, gunakan `wss://` pada frontend dan sesuaikan `VITE_REVERB_SCHEME`, host, serta port. Buka hanya port 80/443; port 8080 cukup listen localhost bila Nginx menjadi reverse proxy.

## 5. Backup PostgreSQL

```bash
sudo install -m 0750 deploy/scripts/backup-postgres.sh /usr/local/sbin/sqms-backup-postgres
sudo crontab -e
```

Contoh cron harian:

```cron
15 2 * * * PGDATABASE=antrian_db PGUSER=sqms PGPASSWORD='PASSWORD_DARI_SECRET_STORE' BACKUP_DIR=/var/backups/antrian-ku /usr/local/sbin/sqms-backup-postgres >> /var/log/antrian-ku-backup.log 2>&1
```

Uji restore ke database terpisah:

```bash
createdb antrian_db_restore_test
pg_restore --clean --if-exists --dbname=antrian_db_restore_test /var/backups/antrian-ku/FILE.dump
dropdb antrian_db_restore_test
```

## 6. Verifikasi rilis

```bash
cd /var/www/ANTRIAN-KU/backend
php artisan about
php artisan migrate:status
curl -f https://antrian-ku.example.com/up
curl -I https://antrian-ku.example.com
sudo systemctl is-active nginx php8.5-fpm redis-server sqms-reverb
sudo supervisorctl status
vendor/bin/phpunit
cd /var/www/ANTRIAN-KU/frontend && npm run lint && npm run test && npm run build
```

Deployment hanya boleh disebut berhasil bila pemeriksaan tersebut berhasil pada server target, login API berhasil, migrasi berjalan, queue worker memproses job, backup dapat direstore, dan Reverb tersambung dari browser. Pada repository ini saya hanya memvalidasi aplikasi lokal; saya tidak menyatakan deployment Ubuntu berhasil karena tidak memiliki akses ke server target.

## 7. Catatan konfigurasi keamanan (diperbarui 1 November 2026)

- **CSP & header keamanan SPA** ada di `deploy/nginx/sqms.conf` pada `location /` (bukan `/api/`, agar tidak bertumpuk dengan CSP yang dikirim Laravel). Karena `add_header` di dalam `location` menggantikan header tingkat server, seluruh header diulang di sana. Perubahan pada frontend yang menambah sumber eksternal (font/CDN/WebSocket host lain) **harus** diikuti penyesuaian CSP ini. HSTS disiapkan (dikomentari) — aktifkan setelah TLS terpasang.
- **WebSocket:** Reverb diproksikan lewat `/app/` pada host yang sama; CSP `connect-src` memakai `ws(s)://$http_host`. Build frontend produksi sebaiknya menyetel `VITE_REVERB_HOST/PORT/SCHEME` ke host publik tersebut. Daftarkan domain/IP perangkat TV pada `REVERB_ALLOWED_ORIGINS` di `backend/.env`.
- **Token staf:** `SANCTUM_STAFF_TOKEN_MINUTES` (default 720). Jalankan `php artisan migrate` pada rilis ini: migrasi `expire_legacy_staff_tokens` memberi batas pada token lama tanpa expiry (token yang lebih tua dari batas langsung tidak berlaku; pengguna cukup login ulang).
- **Rute `/display`** memiliki blok `location` sendiri dengan CSP yang dilonggarkan (video IPTV/YouTube); jangan menyamakannya dengan CSP SPA. Server stream IPTV pada jaringan lokal harus mengirim header CORS agar HLS dapat dimuat browser. Variabel build opsional: `VITE_MEDIA_PROBE_SECONDS`, `VITE_MEDIA_RETRY_SECONDS`.
- **Swagger UI** (`/api/docs`) aktif kecuali `APP_ENV=production`; paksa dengan `API_DOCS_ENABLED=true|false`.
- Setelah deploy: verifikasi di browser bahwa konsol bebas pelanggaran CSP pada `/login`, `/operator`, `/display`, `/kiosk`, dan `/reports`.
