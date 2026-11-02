# Prompt 9 — Environment & Infrastructure Validation

Tanggal validasi: 30 Oktober 2026.

## Ringkasan status

| Area | Status | Bukti / catatan |
|---|---|---|
| Struktur backend/frontend | PASS | Laravel berada di `backend/`, React di `frontend/`. |
| Composer/autoload | PASS | `composer validate --no-check-publish` dan PHPUnit berhasil. |
| PostgreSQL service | PASS | `pg_isready` menerima koneksi; `systemctl` aktif. |
| Laravel PostgreSQL connection | BLOCKED | `backend/.env` belum tersedia; Laravel masih default SQLite. |
| 6 migration static audit | PASS | UUID, foreign key, unique/index, JSONB, timestamp, check constraint, dan partial unique index ditemukan. |
| PostgreSQL migration execution | NOT TESTED | Tidak dijalankan tanpa database/user/credential yang dikonfirmasi. Tidak ada `migrate:fresh`. |
| Backend tests | PASS | Regression terakhir 25 tests, 93 assertions; PHPUnit memakai SQLite in-memory untuk test suite saat ini. |
| Redis service | PASS | `redis-cli ping` menghasilkan `PONG`. |
| Laravel Redis integration | FAIL | PHP runtime tidak memiliki class/extensi `Redis`; Laravel gagal saat Redis cache digunakan. |
| Reverb provider/config | PASS | Provider ApplicationManager terdaftar dan key konfigurasi Reverb v1.12 dilengkapi. |
| Reverb startup | PASS (probe) | `CACHE_STORE=array php artisan reverb:start` berhasil start di port probe 8090 dan graceful terminate. |
| Reverb production runtime | BLOCKED | Dengan konfigurasi default tanpa `.env`, Reverb mencoba cache database SQLite yang belum ada. |
| Queue worker Redis | NOT TESTED/BLOCKED | Queue production Redis belum dapat dipakai sebelum ekstensi PHP Redis dan `.env` tersedia. |
| Frontend build | PASS | `npm run lint`, `npm run test`, dan `npm run build` berhasil. |
| Nginx/PHP-FPM | PASS (service/config syntax) | Service aktif; config sistem dapat diparse dengan warning environment. Runtime virtual host belum diuji sebagai deployment target. |
| Supervisor | NOT TESTED | Service Supervisor terdeteksi `inactive`; worker belum dijalankan. |
| Git metadata | BLOCKED | `.git` tidak tersedia pada mount; repository baru tidak dibuat. |

## PostgreSQL

`backend/.env.example` sudah menggunakan `DB_CONNECTION=pgsql`, port 5432, dan database `sqms`. Server PostgreSQL aktif, tetapi role/database dan credential aplikasi belum dapat dikonfirmasi secara non-interaktif. Tidak ada database creation, migration, atau operasi destruktif yang dijalankan.

Untuk melanjutkan, administrator perlu menyediakan `backend/.env` secara aman, contohnya:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sqms
DB_USERNAME=sqms
DB_PASSWORD=<secret-dari-secret-store>
```

Lalu validasi dengan `php artisan migrate:status` dan `php artisan migrate --pretend` terlebih dahulu. Migration aktual hanya boleh dijalankan setelah database target dikonfirmasi.

## Migration audit

Enam migration tetap tersedia di `backend/database/migrations`. Audit statis menemukan UUID primary key, foreign key komposit untuk isolasi cabang, index cabang/tanggal/status, JSONB audit/outbox, timestamp timezone, PostgreSQL check constraint, dan partial unique index untuk active assignment/session. Seeder tetap tersedia di `backend/database/seeders`.

## Redis, Queue, dan Reverb

Redis daemon dapat diakses melalui CLI, tetapi aplikasi Laravel menggunakan PhpRedis connector dan runtime PHP tidak memuat ekstensi `Redis`. Instalasi server yang diperlukan:

```bash
sudo apt install php8.3-redis
sudo systemctl restart php8.3-fpm
```

Setelah `.env` tersedia, gunakan `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, lalu uji cache Laravel dan worker. Reverb sudah diperbaiki agar `ApplicationManagerServiceProvider` terdaftar dan konfigurasi v1.12 lengkap. `REVERB_ALLOWED_ORIGINS` sekarang tersedia agar production tidak memakai wildcard origin.

## Frontend

Frontend tetap independen di `frontend/`. Build tidak memasukkan credential backend; hanya variable `VITE_*` yang dipakai oleh Vite. API base URL dan Echo/Reverb tetap dikonfigurasi melalui `.env` frontend.

## Remaining issues

1. Buat/isi `backend/.env` melalui secret store; jangan commit file tersebut.
2. Konfirmasi database dan user PostgreSQL, kemudian jalankan migration pada database testing PostgreSQL.
3. Pasang ekstensi PHP Redis.
4. Aktifkan Supervisor dan jalankan worker.
5. Uji Reverb dengan `.env` aktual, private-channel auth, dan browser frontend.
6. Pulihkan atau attach kembali `.git` di root `ANTRIAN-KU`; jangan membuat repository baru otomatis.
7. Jalankan smoke test login, API, queue registration, assignment, dan display pada environment terkonfigurasi.
