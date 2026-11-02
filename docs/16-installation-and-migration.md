# Instalasi dan Migrasi Database Fase 1

Dokumen ini berlaku setelah project Laravel dibuat dan dependency `spatie/laravel-permission` sudah terpasang.

## 1. Konfigurasi PostgreSQL

Buat database PostgreSQL 16, lalu isi `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sqms
DB_USERNAME=sqms
DB_PASSWORD=change-this-secret

APP_TIMEZONE=Asia/Jakarta
```

Pastikan extension UUID tersedia (`CREATE EXTENSION IF NOT EXISTS pgcrypto;`). Migration tidak bergantung pada UUID database karena UUID dibuat aplikasi, tetapi extension direkomendasikan untuk operasi SQL manual.

## 2. Konfigurasi Spatie untuk UUID

Atur `config/permission.php` agar model key bertipe UUID, gunakan tabel migration yang dibuat fase ini, dan pastikan model `User` memakai `HasRoles`. `model_has_roles.model_id` dan `model_has_permissions.model_id` adalah UUID.

## 3. Jalankan migration dan seeder

```bash
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=MasterDataSeeder
```

Atau jalankan seluruh seeder:

```bash
php artisan db:seed
```

Seeder contoh membuat dua cabang (`JKT001`, `BDG001`), layanan CS/Teller, priority types, loket, jam operasional, serta akun contoh. Password contoh adalah `ChangeMe123!` dan wajib diganti sebelum lingkungan selain development digunakan.

## 4. Verifikasi

```bash
php artisan migrate:status
php artisan tinker
```

Verifikasi jumlah cabang, unique constraint, role/permission, dan partial index aktif menggunakan query PostgreSQL. Migration ini belum menjalankan queue business logic; generator nomor dan state transition akan diimplementasikan pada fase backend berikutnya.

## 5. Rollback

Untuk development saja:

```bash
php artisan migrate:rollback --step=4
```

Jangan rollback production tanpa backup dan runbook. Data transaksi historis tidak boleh dihapus sebagai bagian dari operasi normal.
