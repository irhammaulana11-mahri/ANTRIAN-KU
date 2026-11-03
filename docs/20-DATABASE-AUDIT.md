# DATABASE AUDIT
**Tanggal:** 2026-11-03

---

## 1. SKEMA DATABASE — OVERVIEW

Database menggunakan **PostgreSQL** dengan 4 migration file utama:

| Migration | Tabel |
|-----------|-------|
| 000001 | `branches`, `users`, `employees`, `units` |
| 000002 | `service_types`, `priority_types`, `counters`, `queue_settings`, `distribution_cursors` |
| 000003 | `queues`, `queue_assignments`, `service_sessions` |
| 000004 | `employee_status_logs`, `display_devices`, `queue_call_logs`, `queue_transitions`, `audit_logs`, `outbox_messages`, `idempotency_keys` |
| 000005 | Tambah kolom `display_announcement`, `display_media`, `voice_rate`, `voice_volume`, `media_volume` di `queue_settings` |
| 000006 | Tambah kolom `device_type`, `activation_code`, `unit_id` di `display_devices` |
| 000007 | Tambah `unit_id` ke `queues`, `counters`, `employees` |
| 000008-9 | Tambah kolom `offline_only` ke `queue_settings` |
| 000010 | Drop kolom `service_code` dari `service_types` |

---

## 2. ANALISIS CONSTRAINTS

### Check Constraints (PostgreSQL only)
```sql
-- branches.is_active: default true (no check — implicit)
-- queues.status: CHECK IN ('WAITING','ASSIGNED','CALLED','SERVING','COMPLETED','SKIPPED','NO_SHOW','TRANSFERRED','CANCELLED')
-- queue_assignments.ended_at: partial unique WHERE ended_at IS NULL
-- service_sessions.completed_at: partial unique WHERE completed_at IS NULL
-- employee_status_logs.to_status: CHECK IN ('AVAILABLE','BUSY','BREAK','OFFLINE','CLOSED')
-- queue_call_logs.call_type: CHECK IN ('INITIAL','RECALL')
```

**Temuan:**
- ✅ Check constraints menerapkan domain value pada kolom status — tidak ada status invalid bisa masuk DB
- ✅ Partial unique index `queue_assignments (employee_id, branch_id) WHERE ended_at IS NULL` — satu petugas hanya boleh punya 1 antrian aktif (DB-enforced)
- ✅ Partial unique index `service_sessions (employee_id, branch_id) WHERE completed_at IS NULL` — satu petugas hanya 1 sesi aktif
- ⚠️ Check constraints hanya aktif di PostgreSQL — `if (DB::connection()->getDriverName() === 'pgsql')` berarti SQLite (dev/test) tidak mendapat proteksi ini

### Foreign Keys
- Semua FK menggunakan `restrictOnDelete` atau `nullOnDelete` — tidak ada `cascadeOnDelete` yang berbahaya
- FK composite pada `employees(id, branch_id)` memastikan employee selalu terikat pada branch yang benar

---

## 3. INDEX ANALISIS

### Index yang Ada
```
branches: PK(id), unique(branch_code)
users: PK(id), unique(email), idx(branch_id), idx(unit_id)
employees: PK(id), unique(employee_code, branch_id), idx(branch_id, is_active)
queues: PK(id), unique(queue_number, branch_id, queue_date), idx(branch_id, queue_date, status)
queue_assignments: PK(id), partial_unique(employee_id, branch_id WHERE ended_at IS NULL), idx(branch_id, ended_at)
service_sessions: PK(id), partial_unique(employee_id, branch_id WHERE completed_at IS NULL)
audit_logs: idx(branch_id, created_at), idx(entity_type, entity_id)
```

### Index yang KURANG
```sql
-- Supervisor board query: WHERE branch_id = ? AND queue_date = TODAY AND status IN (...)
-- Saat ini ada index (branch_id, queue_date, status) tapi PostgreSQL mungkin pilih partial scan
-- Rekomendasi: composite index (branch_id, queue_date) INCLUDE (status, sequence_no)

-- Queue history filter: WHERE branch_id = ? AND service_type_id = ? AND created_at BETWEEN
-- Tidak ada index pada (branch_id, service_type_id, created_at)

-- Employee lookup per unit: WHERE branch_id = ? AND unit_id = ? AND is_active = true
-- Ada idx(branch_id, is_active) tapi tidak (branch_id, unit_id, is_active)
```

**Severity:** LOW — tidak akan menyebabkan error tapi akan slow pada data besar (>10k antrian per hari)

---

## 4. TABEL ORPHAN / TIDAK DIGUNAKAN

### `outbox_messages`
- Tabel ada di migration, tapi tidak ada worker/consumer yang memproses baris di tabel ini
- Tidak ada `artisan` command, tidak ada scheduled job, tidak ada observer yang membaca tabel ini
- **Status:** DEAD CODE — tabel ada tapi tidak berfungsi
- **Risiko:** Tidak ada, tapi menyesatkan — developer lain mungkin mengira outbox pattern sudah berjalan

### `idempotency_keys`
- Dibuat di migration tapi tidak ada middleware atau service yang menggunakannya
- `expires_at` index ada tapi tidak ada cleanup job
- **Status:** PARTIALLY DEAD — model ada tapi fitur belum diimplementasikan
- **Risiko:** Tabel akan tumbuh tanpa batas tanpa cleanup

---

## 5. DATA INTEGRITY

### Multi-tenant Isolation
- Semua tabel utama memiliki `branch_id` FK ke `branches`
- Tidak ada row yang bisa "lolos" ke branch lain melalui query normal karena semua service method filter by `branch_id`

### Soft Delete
- `display_devices` menggunakan `softDeletesTz` — device yang dihapus masih ada di DB
- `users`, `employees` belum menggunakan soft delete secara konsisten

### UUID vs Integer PK
- Semua PK menggunakan UUID v4 — tidak ada sequential ID yang bisa di-enumerate
- **Positif untuk keamanan:** tidak ada IDOR via ID prediction

---

## 6. KESIMPULAN DATABASE

| Aspek | Nilai | Catatan |
|-------|-------|---------|
| Constraint coverage | 9/10 | SQLite dev tidak dapat check constraint |
| Index coverage | 7/10 | Beberapa composite index kurang |
| Foreign key design | 9/10 | Restrict/null on delete, no cascade |
| Soft delete | 7/10 | Tidak konsisten antar model |
| Tabel orphan | 6/10 | `outbox_messages` dan `idempotency_keys` tidak berfungsi |
| Multi-tenant | 10/10 | Isolasi solid via branch_id |
| **TOTAL** | **8/10** | |
