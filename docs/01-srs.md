# SQMS — Software Requirements Specification

> **Status dokumen:** diperbarui 31 Oktober 2026 agar sesuai implementasi. Penanda: ✅ selesai · ◐ sebagian · ⬜ belum.

## Tujuan dan ruang lingkup
SQMS mengelola antrian Customer Service dan Teller untuk banyak cabang, dari pendaftaran walk-in/online sampai pelayanan, display real-time, pelaporan, dan audit. Sistem berbentuk modular monolith Laravel dengan React client.

## Aktor
Super Admin, Admin Cabang, Supervisor, Customer Service, Teller, Customer, **Display Device (TV)**, dan **Kiosk Device (tablet)**. Hak akses ditentukan permission granular dan scope cabang; role bukan satu-satunya pemeriksaan. Perangkat Display dan Kiosk bukan pengguna: mereka memakai token perangkat dengan ability terpisah (`display` / `kiosk`) dan terkunci ke satu cabang.

## Asumsi dan batasan
- Satu database PostgreSQL shared-schema; seluruh transaksi operasional memiliki `branch_id`.
- Nomor antrian di-reset berdasarkan cabang, service type, dan **tanggal operasional dalam zona waktu aplikasi (`APP_TIMEZONE`, default Asia/Jakarta)**, bukan UTC.
- Jam operasional, hari libur, kuota online, dan ambang antrian menunggu dikonfigurasi per cabang (menu *Pengaturan Antrian*). Cabang tanpa jam operasional dianggap buka.
- **Kuota dan jam operasional hanya membatasi pendaftaran online.** Walk-in (staf/kiosk) tidak dibatasi keduanya.
- Customer online menggunakan token pelacakan (96 karakter, hanya hash yang disimpan) yang tidak mengekspos data customer lain.
- Kategori prioritas tidak dapat diklaim sendiri oleh customer; hanya staf dengan izin `queues.assign` yang dapat memverifikasinya (tercatat).
- Reverb adalah infrastruktur real-time; client menangani reconnect dengan snapshot REST. Server Reverb memeriksa header `Origin` (`REVERB_ALLOWED_ORIGINS`).
- Redis disebut sebagai target produksi; lingkungan pengembangan saat ini memakai driver `database` untuk cache/queue.

## Acceptance criteria tingkat sistem
| Kriteria | Status |
|---|---|
| Tidak ada nomor duplikat, assignment ganda, pelayanan ganda | ✅ dijaga lock + partial unique index; diuji paralel di PostgreSQL (`docs/23`) |
| Tidak ada akses lintas cabang | ✅ middleware + policy + query scope; diuji (IDOR, channel, token perangkat) |
| Setiap transisi status tercatat | ✅ `queue_transitions` + `audit_logs` |
| Display menerima event setelah commit | ✅ `ShouldDispatchAfterCommit` (bukan outbox — lihat `docs/15`) |
| Operasi kritis memiliki test concurrency | ✅ 11 tes pada PostgreSQL |
| API dan UI berbahasa Indonesia; API terdokumentasi | ✅ UI Indonesia; OpenAPI 3.0 di `backend/resources/openapi.yaml` |
| Uji end-to-end otomatis (browser) | ⬜ hanya uji visual manual |
