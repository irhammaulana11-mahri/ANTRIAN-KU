# APPLICATION AUDIT REPORT
**Tanggal Audit:** 2026-11-03  
**Auditor:** Claude Sonnet 4.6 (automated code review)  
**Scope:** Full-stack queue management system — backend (Laravel 13/PHP 8.3), frontend (React 19/TypeScript), database (PostgreSQL), realtime (Laravel Reverb/WebSocket)

---

## RINGKASAN EKSEKUTIF

Sistem Manajemen Antrian (SQMS) telah diaudit secara menyeluruh mencakup 7 dimensi: arsitektur backend, logika bisnis, skema database, mesin distribusi antrian (Round Robin), keamanan aplikasi, UI/UX, dan cakupan pengujian.

### VERDICT FINAL

> **⚠️ READY WITH LIMITATIONS**

Sistem memiliki fondasi arsitektur yang solid — desain event-driven, transaksi DB dengan retry logic, partial unique indexes, dan middleware keamanan berlapis. Namun terdapat beberapa kelemahan yang harus diselesaikan sebelum deployment produksi skala penuh:

1. **24 test gagal** akibat referensi role `Supervisor` yang sudah dihapus — menyebabkan test suite tidak dapat diandalkan
2. **IDOR potensial** pada beberapa endpoint yang belum memvalidasi kepemilikan resource terhadap branch_id user
3. **Rate limiting tidak dikonfigurasi** untuk endpoint login dan registrasi antrian
4. **WebSocket channel** `employee.{employeeId}` mengizinkan siapapun dengan `queues.view` mendengarkan percakapan semua petugas

---

## TEMUAN BERDASARKAN SEVERITY

### 🔴 CRITICAL (0)
Tidak ada temuan critical.

### 🟠 HIGH (3)

| ID | Area | Temuan |
|----|------|--------|
| H-01 | Security | Login endpoint tidak memiliki rate limiting — brute force tidak dibatasi |
| H-02 | Testing | 24 dari 138 test gagal — test suite tidak reliabel untuk CI/CD |
| H-03 | Security | WebSocket `employee.{employeeId}` dapat didengarkan oleh semua user dengan `queues.view` lintas branch (Super Admin tanpa branch_id) |

### 🟡 MEDIUM (5)

| ID | Area | Temuan |
|----|------|--------|
| M-01 | Security | Tidak ada IDOR check pada `QueueController::cancel()` — belum memverifikasi antrian milik branch user |
| M-02 | Backend | `SupervisorController::board()` melakukan 5+ query N+1 tanpa batching agregat |
| M-03 | Backend | `outbox_messages` table tidak diproses oleh worker apapun (transactional outbox tanpa consumer) |
| M-04 | Database | `idempotency_keys` tidak dibersihkan secara otomatis (tidak ada scheduler/cleaner) |
| M-05 | Frontend | Token refresh tidak ditangani secara proaktif — frontend menunggu 401 untuk redirect |

### 🟢 LOW (6)

| ID | Area | Temuan |
|----|------|--------|
| L-01 | Backend | Round Robin `eligibleEmployees()` sort by `id` (UUID) — tidak stabil untuk fairness RR |
| L-02 | Backend | `WaitEstimator` menggunakan rata-rata 30 menit terakhir tapi tidak mempertimbangkan variasi beban |
| L-03 | Database | Tidak ada index pada `queues.status` — query supervisor board full scan pada tabel besar |
| L-04 | Frontend | Loading state di beberapa halaman tidak konsisten (ada yang pakai spinner, ada yang diam) |
| L-05 | Security | `SANCTUM_STATEFUL_DOMAINS` tidak divalidasi di production `.env` |
| L-06 | UI/UX | Tidak ada konfirmasi visual setelah aksi berhasil di beberapa form (hanya toast singkat) |

### 🔵 INFO (4)

| ID | Area | Temuan |
|----|------|--------|
| I-01 | Architecture | Arsitektur event-driven dengan `ShouldDispatchAfterCommit` — mencegah phantom event sebelum commit |
| I-02 | Security | CSP header dikonfigurasi per-response-type — solid untuk API/JSON |
| I-03 | Database | Partial unique indexes pada `queue_assignments` dan `service_sessions` — DB-level integrity enforcement |
| I-04 | Backend | `BranchAuthorizationService::fromRequest()` secara konsisten mengisolasi data per-tenant |

---

## SKOR KOMPONEN

| Komponen | Skor | Keterangan |
|----------|------|------------|
| Backend Architecture | 85/100 | Solid, ada outbox tidak diproses |
| Business Logic | 88/100 | Lengkap, ada celah IDOR minor |
| Database Design | 90/100 | Excellent constraints, kurang beberapa index |
| Round Robin Engine | 82/100 | Fungsional, UUID sort tidak ideal |
| Application Security | 78/100 | Baik tapi kurang rate limiting |
| UI/UX | 80/100 | Konsisten, beberapa inkonsistensi feedback |
| Test Coverage | 65/100 | 24 test gagal, tidak ada frontend test |
| **RATA-RATA** | **81/100** | **READY WITH LIMITATIONS** |

---

## PRASYARAT SEBELUM PRODUKSI PENUH

1. Perbaiki 24 test gagal (hapus referensi role `Supervisor` lama)
2. Tambah rate limiting pada `/login` dan `/queue/walk-in`  
3. Review IDOR exposure pada endpoint queue management
4. Konfirmasi `outbox_messages` apakah dibutuhkan atau bisa dihapus
5. Pasang `idempotency_keys:cleanup` di scheduler Laravel
