# Fase 2 — Backend Foundation

## Endpoint

Semua endpoint memakai prefix `/api/v1`:

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/auth/login` | public |
| GET | `/auth/me` | authenticated |
| POST | `/auth/logout` | authenticated |
| CRUD | `/branches` | `branches.view/manage` |
| CRUD | `/employees` | `employees.view/manage` |
| PUT | `/employees/{employee}/competencies` | `employees.manage` |
| CRUD | `/counters` | `counters.view/manage` |
| CRUD | `/service-types` | `services.view/manage` |

## Authorization

Sanctum mengautentikasi token. Spatie menangani permission. `ResolveBranchContext` menghitung konteks cabang dari user server-side; Super Admin dapat memilih `branch_id`, sedangkan user cabang tidak dapat mengganti cabangnya. Policy tetap melakukan pemeriksaan kedua pada resource model.

`branch_id` dari request tidak pernah dipercaya secara langsung. Service layer membuang `branch_id` saat update dan memaksa branch pada saat create. Kompetensi employee hanya dapat dihubungkan ke service type pada cabang employee yang sama.

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
vendor/bin/phpunit
```

Fase ini menambahkan migration `personal_access_tokens` untuk Sanctum. Migration domain Fase 1 tidak diubah.

## Akun development

Seeder membuat akun cabang dengan password `ChangeMe123!`. Ganti password sebelum deployment. Akun admin contoh memakai email berbasis kode cabang, misalnya `jkt001@example.test`.

