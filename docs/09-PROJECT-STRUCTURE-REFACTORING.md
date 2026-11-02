# Project Structure Refactoring

Refactoring memisahkan seluruh komponen Laravel ke `backend/` dan mempertahankan React di `frontend/`. Migration, seeder, business logic, dan data database tidak dihapus.

## Struktur final

```text
ANTRIAN-KU/
├── backend/  (app, bootstrap, config, database, routes, storage, tests, vendor, artisan, Composer, PHPUnit)
├── frontend/
├── deploy/
├── docs/
├── scripts/
└── README.md
```

Pemetaan: `app/` → `backend/app/`, `bootstrap/` → `backend/bootstrap/`, `config/` → `backend/config/`, `database/` → `backend/database/`, `routes/` → `backend/routes/`, `storage/` → `backend/storage/`, `tests/` → `backend/tests/`, `vendor/` → `backend/vendor/`; file Artisan, Composer, PHPUnit, dan `.env.example` masuk `backend/`. `frontend/`, `deploy/`, dan dokumentasi tetap di root.

Jalankan Laravel dari `backend/`. Deployment memakai `/var/www/sqms/backend` untuk Laravel dan `/var/www/sqms/frontend/dist` untuk static assets. `.env` dan secret tetap hanya di backend.

## Validasi

```bash
cd backend && composer validate && php artisan about && php artisan route:list && php artisan migrate:status && php artisan test
cd ../frontend && npm run lint && npm run test && npm run build
```

PostgreSQL, Redis, Nginx, PHP-FPM, dan Reverb harus diuji pada server target. Jika tidak tersedia, hasil dicatat sebagai `NOT TESTED`.
