# BUSINESS LOGIC AUDIT
**Tanggal:** 2026-11-03

---

## 1. SKENARIO BISNIS — REGISTRASI & DISTRIBUSI ANTRIAN

### Skenario A: Walk-in Normal
**Alur:** CS mendaftarkan nasabah → sistem assign ke petugas tersedia → tampil di display

**Kode:** `QueueController::walkIn()` → `QueueManagementService::register()` → `QueueDistributionEngine::assignNext()`

**Temuan:**
- ✅ Nomor antrian dibuat atomik via `QueueNumberFormatter` dengan format `{prefix}{date}{seq}`
- ✅ Sequence number per-branch per-date di-lock dengan `lockForUpdate()`
- ✅ Assignment dilakukan dalam satu transaksi DB dengan retry 5x (`LockTimeoutException` handling)
- ✅ Event `QueueRegistered` + `QueueAssigned` di-dispatch AFTER commit (`ShouldDispatchAfterCommit`)
- ⚠️ `QueueController::walkIn()` hanya cek `queues.register OR queues.assign` — tidak ada validasi bahwa branch_id pada payload sama dengan branch user yang login

### Skenario B: Priority Queue
**Alur:** Nasabah prioritas masuk → verifikasi oleh supervisor → sistem assign lebih dulu

**Kode:** `PriorityQueueStrategy::selectEmployee()` dengan weighted score + aging

**Formula:** `score = weight + floor((now - registered_at).seconds / interval) * rate`

**Temuan:**
- ✅ Aging mechanism mencegah starvation antrian prioritas yang lama menunggu
- ✅ Priority hanya berlaku setelah `priority_verified_at` diisi (dual-check: type + verification)
- ✅ `verifyPriority()` membutuhkan permission `queues.assign` — tidak bisa self-verify
- ⚠️ Aging rate dan interval adalah hardcoded di strategy — tidak bisa dikonfigurasi per-cabang

### Skenario C: Transfer Antrian
**Alur:** CS transfer nasabah ke layanan lain → antrian lama selesai → antrian baru terdaftar

**Kode:** `QueueManagementService::transfer()`

**Temuan:**
- ✅ Transfer membuat antrian baru dengan `WALK_IN` channel, nomor baru
- ✅ Antrian lama di-mark `TRANSFERRED` dengan `reason` tersimpan
- ✅ `assignment` lama di-end setelah transfer, petugas freed
- ✅ `assignPending()` dipanggil setelah transfer — distribusi ulang otomatis
- ✅ Target service_type harus dari branch yang sama (divalidasi via `abort_unless`)

### Skenario D: Skip / No-Show
**Alur:** Nasabah tidak hadir → petugas skip → antrian lanjut

**Kode:** `QueueManagementService::changeStatus()` → status `SKIPPED` atau `NO_SHOW`

**Temuan:**
- ✅ Setelah skip/no-show, `assignPending()` dipanggil — slot petugas dibebaskan
- ✅ Status transition dicatat di `queue_transitions` table (audit trail lengkap)
- ⚠️ Tidak ada batas maksimum recall (RECALLED_AT bisa terus diupdate) — bisa infinite recall

### Skenario E: Kiosk Self-Registration
**Alur:** Nasabah scan QR di kiosk → pilih layanan → dapat nomor antrian

**Kode:** `KioskController` dengan device token tipe `KIOSK`

**Temuan:**
- ✅ Device token KIOSK terpisah dari DISPLAY — tidak bisa cross-use
- ✅ `EnsureDisplayDevice` middleware memvalidasi device type per endpoint
- ✅ Kiosk hanya bisa register antrian `WALK_IN` — tidak bisa akses data lain
- ✅ Offline mode: `queue_settings.offline_only = true` mencegah registrasi baru

---

## 2. STATE MACHINE ANTRIAN

```
WAITING → ASSIGNED → CALLED → SERVING → COMPLETED
                   ↓         ↓         
                 CANCELLED  SKIPPED / NO_SHOW
                              ↓
                           TRANSFERRED (membuat antrian baru)
```

**Temuan:**
- ✅ State transition divalidasi di `QueueManagementService` — tidak bisa langsung WAITING → COMPLETED
- ✅ `queue_transitions` menyimpan semua transisi dengan actor, reason, timestamp
- ⚠️ Tidak ada validasi eksplisit state machine di layer middleware — validasi hanya di service layer

---

## 3. MULTI-TENANT ISOLATION

**Kode:** `BranchAuthorizationService::fromRequest()`

**Logic:**
- Super Admin: wajib kirim `branch_id` di query param — tidak ada default
- Admin Cabang: `branch_id` dari user model — tidak bisa ganti
- Admin Unit / CS / Teller: `branch_id` dari user model, data difilter per `unit_id`

**Temuan:**
- ✅ Super Admin wajib menyertakan `branch_id` — tidak ada akses tanpa parameter
- ✅ Admin Cabang tidak dapat mengakses data cabang lain
- ✅ `ResolveBranchContext` middleware diaplikasikan ke semua route staf
- ⚠️ `SupervisorController::board()` abort_unless(branchId, 422) tapi pesan error kurang informatif untuk debuggig

---

## 4. LOGIKA DISTRIBUSI ANTRIAN

### Round Robin
- Cursor `last_employee_id` di-update setiap assignment
- `eligibleEmployees()`: aktif + tersedia (`AVAILABLE`) + punya skill (serviceType)
- Sort: `orderBy('id')` — UUID v4 (random) tidak berurutan stabil

**RISIKO:** Jika dua request concurrent memanggil `assignNext()` bersamaan, keduanya bisa membaca cursor yang sama sebelum salah satu update. Namun ini ditangani oleh `lockForUpdate()` dalam transaksi — hanya satu yang bisa lanjut.

### Priority Queue
- Score tertinggi dipilih pertama
- Aging rate hardcoded: `AGING_RATE = 0.5, AGING_INTERVAL = 300`
- Weight default priority: Low=1, Normal=5, High=10, Urgent=20

---

## 5. KESIMPULAN BUSINESS LOGIC

| Aspek | Status |
|-------|--------|
| State machine antrian | ✅ Lengkap |
| Multi-tenant isolation | ✅ Solid |
| Priority + aging | ✅ Fungsional |
| Transfer antrian | ✅ Lengkap |
| Kiosk self-service | ✅ Fungsional |
| Rate/retry safety | ✅ 5-retry lockForUpdate |
| Recall limit | ⚠️ Tidak ada batas |
| Aging konfigurasi | ⚠️ Hardcoded |
