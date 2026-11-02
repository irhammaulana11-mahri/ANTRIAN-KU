# 23. Testing, Concurrency, dan Dokumentasi API

## Menjalankan test

| Tujuan | Perintah (dari `backend/`) | Catatan |
|---|---|---|
| Suite cepat (SQLite in-memory) | `php artisan test` | 11 tes concurrency dilewati otomatis |
| Suite penuh di PostgreSQL | `php vendor/bin/phpunit --configuration phpunit.pgsql.xml` | Memakai schema terpisah `sqms_test` |
| Hanya tes concurrency | `php vendor/bin/phpunit --configuration phpunit.pgsql.xml --group=concurrency` | ±1 menit; memakai proses PHP terpisah |
| Frontend | `cd frontend && npx tsc -p tsconfig.app.json --noEmit && npx vitest run` | |

### Menyiapkan test PostgreSQL (sekali)

```bash
psql -h 127.0.0.1 -U sqms sqms -c "create schema if not exists sqms_test"
```

Host, nama database, user, dan password dibaca dari `.env`; `phpunit.pgsql.xml` hanya memaksa `DB_SCHEMA=sqms_test`.
Role database tidak memerlukan hak `CREATEDB`.

### Pengaman data

`tests/CreatesApplication.php` menolak menjalankan test bila koneksi PostgreSQL tidak memakai schema berakhiran `_test`
(mencegah `migrate:fresh` / `TRUNCATE` menyentuh schema `public` yang berisi data aplikasi), dan menolak SQLite selain `:memory:`.

## Tes concurrency (`tests/Feature/ConcurrencyTest.php`)

Setiap skenario memulai banyak **proses PHP terpisah** (Laravel `Concurrency`, driver `process`), masing-masing dengan koneksi
PostgreSQL sendiri, lalu menahan semuanya pada barrier waktu yang sama (`tests/Support/Parallel.php`) agar benar-benar berebut.
Data di-commit (tanpa transaksi pembungkus) agar terlihat oleh proses anak; seluruh tabel di schema `*_test` dikosongkan sebelum dan sesudah tiap tes.

| Skenario | Yang dijamin |
|---|---|
| 12 pendaftaran serentak (2 layanan, walk-in+online) | Nomor unik dan tanpa celah per layanan (`CS-001…006`, `TL-001…006`) |
| 10 pendaftaran online serentak, kuota 3 | Tepat 3 berhasil, 7 ditolak validasi |
| Nomor telepon sama didaftarkan serentak (online & walk-in) | Hanya 1 antrian aktif |
| 8 distribusi serentak, 1 antrian & 4 petugas | Antrian hanya mendapat 1 assignment aktif |
| 8 distribusi serentak, 1 petugas & 6 antrian | Petugas hanya melayani 1 antrian |
| 3 petugas berbagi 1 loket, 3 antrian | Loket hanya melayani 1 antrian |
| Round Robin serentak, 3 petugas & 3 antrian | Tiap petugas mendapat tepat 1 antrian |
| 2 petugas menyelesaikan layanan bersamaan | Masing-masing mendapat tepat 1 antrian baru, tanpa duplikasi |
| Klik ganda "Selesaikan" (4 permintaan serentak) | Hanya 1 berhasil; 1 antrian baru ter-assign |
| Pembatalan pelanggan vs panggilan petugas | Status akhir konsisten; tidak ada assignment yatim |

**Bug nyata yang ditemukan tes ini:** cek duplikat nomor telepon dilakukan sebelum baris `queue_sequences` dikunci, sehingga enam
pendaftaran walk-in serentak dengan nomor sama lolos semua. Sekarang cek dilakukan setelah kunci.

**Mutation check** (dilakukan manual, kode dikembalikan setelahnya): menghapus `lockForUpdate` pada `queue_sequences` membuat tes
penomoran gagal; menghapus seluruh `lockForUpdate` pada `QueueDistributionEngine` membuat tes assignment gagal. Sebagian tes lain tetap
lulus karena partial unique index di database menjadi lapisan pengaman kedua — itu perilaku yang diinginkan (defense in depth).

> Keterbatasan: race condition bersifat probabilistik. Lulusnya tes membuktikan tidak ada pelanggaran pada skenario ini,
> bukan membuktikan ketiadaan semua kemungkinan race.

## Dokumentasi API (OpenAPI 3.0)

- Sumber: `backend/resources/openapi.yaml` (74 operasi, seluruh `/api/v1/*` + `POST /broadcasting/auth`).
- Swagger UI: `GET /api/docs` — spesifikasi mentah: `GET /api/docs/openapi.yaml`.
  Aktif secara default kecuali `APP_ENV=production`; paksa dengan `API_DOCS_ENABLED=true|false`.
  Swagger UI dimuat dari CDN unpkg (`swagger-ui-dist@5.17.14`).
- `tests/Feature/OpenApiSpecTest.php` menjaga dokumen tetap jujur: setiap rute terdaftar harus terdokumentasi, tidak boleh ada endpoint
  basi, `operationId` unik, semua `$ref` terselesaikan, parameter path dideklarasikan, dan setiap izin yang disebut (`**Izin:**`) harus ada di `RolePermissionSeeder`.
- Dokumen ini **belum divalidasi dengan validator OpenAPI eksternal** (hanya oleh tes struktural di atas).

## Kiosk dan penguncian walk-in

`queues/walk-in` kini wajib login (staf berizin `queues.register`/`queues.assign`); tablet di kantor memakai perangkat bertipe `KIOSK` (`/kiosk`, token ability `kiosk`, terkunci ke cabang, tanpa klaim prioritas, 30 permintaan/menit). Ini menutup celah: walk-in publik dapat melewati jam operasional dan kuota online.

## Verifikasi di browser dengan stack terisolasi

Untuk menguji CSP, perpanjangan token, dan alur supervisor tanpa menyentuh data aplikasi: API dijalankan terhadap schema `sqms_test` (`DB_SCHEMA=sqms_test php -S …`), build produksi disajikan oleh server kecil yang menerapkan **header CSP yang diparse langsung dari `deploy/nginx/sqms.conf`** (dan memproksikan `/api`, `/broadcasting`, serta WebSocket `/app/` seperti Nginx), lalu Chrome headless dikendalikan lewat DevTools Protocol. Kontrol negatif membuktikan CSP aktif (skrip inline diblokir). Skrip bersifat sekali pakai dan tidak disimpan di repo.

- `docs/24-uji-perangkat.md`: daftar periksa untuk bagian yang hanya dapat diuji pada perangkat nyata (suara id-ID, printer termal, video IPTV).

## Uji visual

Uji visual dilakukan dengan Chrome headless yang dikendalikan lewat Chrome DevTools Protocol (skrip sekali pakai, tidak disimpan di repo): halaman admin, operator, registrasi, tracking, kiosk, display, dan Swagger UI; konsol dan jaringan dicek tanpa error. Suara tidak dapat diuji secara headless.

## Fitur pulihkan (restore)

Data master yang dihapus (soft delete) dapat dipulihkan: `POST /{resource}/{id}/restore` untuk cabang, petugas, loket, dan layanan;
daftar data terhapus lewat `?deleted=1` (di UI: filter status **Dihapus**). Pemulihan ditolak bila cabang induknya masih terhapus.
Hubungan petugas–layanan/loket tidak ikut dipulihkan karena dilepas saat penghapusan.
