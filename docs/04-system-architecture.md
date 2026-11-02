# System Architecture

## Bentuk
Modular monolith: Laravel 13 API + modul layanan, PostgreSQL 16, Laravel Queue, Reverb, dan React/Vite SPA. Nginx sebagai TLS reverse proxy dan PHP-FPM menjalankan API (lihat `docs/22`). Redis adalah target produksi untuk cache/queue; di pengembangan dipakai driver `database`.

## Klien
| Klien | Autentikasi | Akses |
|---|---|---|
| SPA staf/admin (`/`, `/operator`, master data, laporan) | Sanctum bearer dari `/auth/login` | Permission + scope cabang |
| Halaman publik (`/register`, `/tracking`) | Tanpa token (pendaftaran online & pelacakan via token antrian) | Katalog publik, rate-limited |
| Central Display (`/display`) | Token perangkat ability `display` | Snapshot + channel `private-branch.{id}` cabangnya |
| Kiosk (`/kiosk`) | Token perangkat ability `kiosk` | `/kiosk/config`, `/kiosk/queues`; **tidak** dapat masuk channel real-time |

## Modul backend
Authentication, Users, Authorization, Branches, Employees, Services, Counters, Queues, Distribution, Priorities, Displays & Kiosk, Notifications (belum), Reporting, Audit, Settings. Pembagian aktual berbasis lapisan (bukan folder domain) — lihat `docs/11`.

## Aliran kritis
1. Request API divalidasi Form Request, middleware `branch.context` (atau `device.type`), dan policy.
2. Service membuka transaction, mengunci `queue_sequences` / queue / employee / counter, memutasi aggregate, menulis `queue_transitions` dan `audit_logs`.
3. Event broadcast diberi `ShouldDispatchAfterCommit`: baru dikirim setelah transaksi **commit** (tidak ada outbox).
4. Client menerima event dan memperbarui cache TanStack Query; reconnect melakukan snapshot REST. Display tidak pernah memutar suara dari snapshot, dan menolak panggilan yang lebih tua dari `call_expiry_seconds` (60 dtk).

## Aturan desain
- Business logic tidak berada di React.
- Isolasi cabang berlapis: middleware → policy → query scope → FK komposit di database. Nilai `branch_id` dari klien tidak dipercaya.
- Event publik memuat data minimum: nama customer dan nomor antrian, **tanpa nomor telepon/catatan**.
- Walk-in **bukan endpoint publik** (agar jam/kuota online tidak dapat dilewati): staf terautentikasi atau perangkat kiosk.
- Zona waktu aplikasi diatur eksplisit (`config/app.php` → `APP_TIMEZONE`); tanggal operasional mengikutinya.
