# Fase 7 — Deployment Ubuntu Server

Panduan ini menargetkan Ubuntu 24.04 LTS, PHP 8.5, PostgreSQL, Nginx, Supervisor, dan Reverb (Redis opsional; cache, queue, dan sesi default memakai database). Perintah instalasi membutuhkan root dan belum dijalankan otomatis dari repository ini.

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
sudo -u postgres createuser --pwprompt antrian_user
sudo -u postgres createdb --owner=antrian_user antrian_db
```

Salin `deploy/env.production.example` ke `backend/.env` lalu isi nilai yang kosong (`APP_KEY`, `DB_PASSWORD`, secret Reverb, akun admin awal). Nilai contoh sudah sesuai server ULP Jombang: cache, queue, dan sesi memakai tabel database (Redis tidak wajib), Reverb di `127.0.0.1:8080`. Jangan commit `.env` dan jangan memakai secret berawalan "local".

> Catatan: contoh role di dokumen lama memakai `sqms`. Server saat ini memakai `antrian_user`; sesuaikan `DB_USERNAME`, `PGUSER`, dan hak aksesnya secara konsisten.

Instalasi pertama:

```bash
cd /var/www/ANTRIAN-KU/backend
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
cd ../frontend && npm ci && npm run build
```

Untuk **update rilis** pada server yang sudah berjalan gunakan bagian 8, bukan perintah di atas.

## 3. Nginx, PHP-FPM, Supervisor

Salin `deploy/nginx/antrian.conf` ke `/etc/nginx/sites-available/antrian`, sesuaikan `listen` (server ULP Jombang memakai port 8081) dan `server_name`, aktifkan symlink, lalu:

```bash
sudo ln -s /etc/nginx/sites-available/antrian /etc/nginx/sites-enabled/antrian
sudo nginx -t
sudo systemctl enable --now nginx php8.5-fpm
```

Pasang `deploy/supervisor/antrian-ku.conf` (berisi worker dan Reverb) ke `/etc/supervisor/conf.d/`, lalu:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Hasil yang diharapkan: `antrian-ku-reverb` dan `antrian-ku-worker:*` berstatus `RUNNING`. Pastikan `QUEUE_CONNECTION` di `.env` sama dengan argumen `queue:work` di file supervisor (saat ini `database`).

## 4. Reverb

Reverb dijalankan **hanya oleh Supervisor** (program `antrian-ku-reverb`, `127.0.0.1:8080`), dan Nginx meneruskan `/app/` ke port itu. Jangan mengaktifkan service systemd Reverb bersamaan: keduanya memakai port 8080 dan Supervisor akan gagal dengan `Address already in use`. Bila server lama masih punya service systemd, nonaktifkan:

```bash
sudo systemctl stop antrian-reverb
sudo systemctl disable antrian-reverb
sudo supervisorctl start antrian-ku-reverb
```

Jika TLS dihentikan di Nginx, gunakan `wss://` pada frontend dan sesuaikan `VITE_REVERB_SCHEME`, host, serta port. Port 8080 cukup listen di localhost karena Nginx menjadi reverse proxy.

## 5. Backup PostgreSQL

```bash
sudo install -m 0750 deploy/scripts/backup-postgres.sh /usr/local/sbin/antrian-backup-postgres
sudo crontab -e
```

Contoh cron harian:

```cron
15 2 * * * PGDATABASE=antrian_db PGUSER=antrian_user PGPASSWORD='PASSWORD_DARI_SECRET_STORE' BACKUP_DIR=/var/backups/antrian-ku /usr/local/sbin/antrian-backup-postgres >> /var/log/antrian-ku-backup.log 2>&1
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
curl -f http://ALAMAT_SERVER/up
sudo systemctl is-active nginx php8.5-fpm
sudo supervisorctl status
```

Pengujian otomatis (`vendor/bin/phpunit`, `npm run lint && npm run test`) dijalankan di mesin pengembangan atau CI, bukan di server produksi: `RefreshDatabase` pada database yang salah akan menghapus data.

Deployment hanya boleh disebut berhasil bila pemeriksaan tersebut berhasil pada server target, login API berhasil, migrasi berjalan, queue worker memproses job, backup dapat direstore, dan Reverb tersambung dari browser. Pada repository ini saya hanya memvalidasi aplikasi lokal; saya tidak menyatakan deployment Ubuntu berhasil karena tidak memiliki akses ke server target.

## 7. Catatan konfigurasi keamanan (diperbarui 1 November 2026)

- **CSP & header keamanan SPA** ada di `deploy/nginx/antrian.conf` pada `location /` (bukan `/api/`, agar tidak bertumpuk dengan CSP yang dikirim Laravel). Karena `add_header` di dalam `location` menggantikan header tingkat server, seluruh header diulang di sana. Perubahan pada frontend yang menambah sumber eksternal (font/CDN/WebSocket host lain) **harus** diikuti penyesuaian CSP ini. HSTS disiapkan (dikomentari) — aktifkan setelah TLS terpasang.
- **WebSocket:** Reverb diproksikan lewat `/app/` pada host yang sama; CSP `connect-src` memakai `ws(s)://$http_host`. Build frontend produksi sebaiknya menyetel `VITE_REVERB_HOST/PORT/SCHEME` ke host publik tersebut. Daftarkan domain/IP perangkat TV pada `REVERB_ALLOWED_ORIGINS` di `backend/.env`.
- **Token staf:** `SANCTUM_STAFF_TOKEN_MINUTES` (default 720). Jalankan `php artisan migrate` pada rilis ini: migrasi `expire_legacy_staff_tokens` memberi batas pada token lama tanpa expiry (token yang lebih tua dari batas langsung tidak berlaku; pengguna cukup login ulang).
- **Rute `/display`** memiliki blok `location` sendiri dengan CSP yang dilonggarkan (video IPTV/YouTube); jangan menyamakannya dengan CSP SPA. Server stream IPTV pada jaringan lokal harus mengirim header CORS agar HLS dapat dimuat browser. Variabel build opsional: `VITE_MEDIA_PROBE_SECONDS`, `VITE_MEDIA_RETRY_SECONDS`.
- **Swagger UI** (`/api/docs`) aktif kecuali `APP_ENV=production`; paksa dengan `API_DOCS_ENABLED=true|false`.
- Setelah deploy: verifikasi di browser bahwa konsol bebas pelanggaran CSP pada `/login`, `/operator`, `/display`, `/kiosk`, dan `/reports`.

## 8. Update rilis (server yang sudah berjalan)

Jalankan sebagai pengguna deploy (bukan root) yang punya `sudo`:

```bash
cd /var/www/ANTRIAN-KU
./deploy/scripts/update-release.sh
```

Skrip ini menjalankan, berurutan: `git pull --ff-only`, `composer install --no-dev --classmap-authoritative`, `migrate --force`, `db:seed --class=RolePermissionSeeder --force`, `optimize:clear`, perbaikan kepemilikan `storage` dan `bootstrap/cache`, build frontend, reload PHP-FPM, lalu restart Supervisor. Skrip tidak mengubah `.env` dan tidak menjalankan perintah destruktif.

Setelah update:
- Pengguna login ulang bila ada izin baru (daftar izin dibaca saat login).
- Pasangkan ulang TV/kiosk bila rilis mengubah nama channel realtime, dan tentukan unit untuk perangkat lama dari halaman Perangkat Display/Kiosk.
- Opsional: `php artisan config:cache && php artisan route:cache` setelah semuanya stabil; ulangi setiap `.env` atau route berubah.

## 9. Pemecahan masalah

| Gejala | Penyebab | Perbaikan |
|---|---|---|
| Error 500, log: `Class "App\Services\X" does not exist` | Autoload `--classmap-authoritative` belum dibuat ulang setelah ada kelas baru | `composer dump-autoload --classmap-authoritative --no-dev`, lalu `php artisan optimize:clear` dan reload PHP-FPM |
| Worker/Reverb `FATAL Exited too quickly` atau `spawn error`; log: `bootstrap/cache must be present and writable` | Perintah artisan dijalankan sebagai pengguna deploy sehingga `storage`/`bootstrap/cache` bukan milik `www-data` | `sudo chown -R www-data:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache`, lalu `sudo supervisorctl restart all` |
| Reverb gagal, log: `Failed to listen on tcp://127.0.0.1:8080 (EADDRINUSE)` | Reverb lama masih berjalan (service systemd atau proses manual) | `sudo ss -ltnp \| grep 8080`; hentikan service lama (`systemctl stop/disable antrian-reverb`), lalu `supervisorctl start antrian-ku-reverb` |
| `supervisorctl tail ... stderr` menjawab `no log file` | Konfigurasi memakai `redirect_stderr=true` | Gunakan `supervisorctl tail NAMA` (stdout) atau `tail /var/log/antrian-*.log` |
| Dashboard: "membutuhkan akses queue history" | Pesan umum untuk kegagalan `/api/v1/queues/history` | Lihat status di tab Network dan `tail -n 60 backend/storage/logs/laravel.log` |
| Menu/izin baru tidak muncul | Seeder izin belum dijalankan atau pengguna belum login ulang | `php artisan db:seed --class=RolePermissionSeeder --force`, lalu login ulang |

Saat menempel hasil Network atau log ke tempat lain, hapus baris `authorization` (token login) dan isi `.env`.
