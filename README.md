# ANTRIAN-KU

Smart Queue Management System dengan backend Laravel dan frontend React terpisah.

## Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

## Frontend

```bash
cd frontend
npm install
npm run dev
```

Realtime dan worker dijalankan dari `backend/` dengan `php artisan reverb:start` dan `php artisan queue:work`. Lihat `docs/09-PROJECT-STRUCTURE-REFACTORING.md` dan `docs/22-deployment-ubuntu.md`.

## Test & dokumentasi API

- `cd backend && php artisan test` — suite cepat (SQLite).
- `php vendor/bin/phpunit --configuration phpunit.pgsql.xml` — suite penuh di PostgreSQL, termasuk tes concurrency (butuh `create schema sqms_test`).
- Dokumentasi API interaktif: `/api/docs` (OpenAPI di `backend/resources/openapi.yaml`).

Detail: `docs/23-testing-and-api-docs.md`.
