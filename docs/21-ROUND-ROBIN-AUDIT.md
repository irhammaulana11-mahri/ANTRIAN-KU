# ROUND ROBIN ENGINE AUDIT
**Tanggal:** 2026-11-03

---

## 1. ARSITEKTUR DISTRIBUSI

Sistem mendukung 3 strategi distribusi:

```
QueueDistributionEngine
├── assignNext()     → distribusi satu antrian spesifik  
├── assignPending()  → distribusi semua antrian menunggu di cabang
└── manualAssign()   → assignment manual oleh supervisor

Strategi (dipilih dari queue_settings):
├── RoundRobinStrategy      → distribusi bergilir berdasarkan cursor
├── PriorityQueueStrategy   → distribusi berdasarkan score+aging
└── ManualAssignmentStrategy → tidak auto-distribute, tunggu supervisor
```

---

## 2. ROUND ROBIN STRATEGY — ANALISIS DETAIL

### Kode: `RoundRobinStrategy::selectEmployee()`

**Alur:**
1. Baca `distribution_cursors` untuk `(branch_id, service_type_id)` — `lockForUpdate()`
2. Ambil semua eligible employee (aktif + AVAILABLE + punya skill serviceType)
3. Sort by `id` (UUID)
4. Cari posisi `last_employee_id` dalam list
5. Pilih employee berikutnya (modular)
6. Update cursor `last_employee_id`

### Masalah: Sort by UUID

```php
// RoundRobinStrategy.php
$employees = $this->eligibleEmployees($branchId, $serviceTypeId)
    ->orderBy('id') // UUID v4 = RANDOM ORDER
    ->get();
```

**Dampak:**
- UUID v4 tidak deterministik — urutan berubah setiap UUID baru dibuat
- RR "berputar" tapi tidak ada jaminan distribusi merata jika employee sering masuk/keluar
- Secara praktis tidak kritis karena lock pada cursor mencegah double-assign, tapi fairness tidak terjamin secara matematis

**Rekomendasi:** Gunakan `orderBy('created_at')` atau tambah kolom `sort_order` numerik

### Concurrency Safety

```php
// Dalam satu transaksi dengan retry:
DB::transaction(function() use (...) {
    $cursor = DistributionCursor::lockForUpdate()->firstOrCreate([...]);
    $queue = Queue::lockForUpdate()->find($queueId);
    $employee = $this->strategy->selectEmployee(...);
    // ... create assignment
    $cursor->update(['last_employee_id' => $employee->id]);
}, 5);  // 5 retries on LockTimeoutException
```

**Temuan:**
- ✅ Lock pada cursor + queue dalam satu transaksi — tidak ada race condition
- ✅ 5 retry dengan exponential-like handling (LockTimeoutException)
- ✅ Partial unique index di DB sebagai safety net terakhir
- ✅ `ShouldDispatchAfterCommit` — event tidak terfiring sebelum commit

---

## 3. PRIORITY QUEUE STRATEGY — ANALISIS DETAIL

### Formula Score

```php
// PriorityQueueStrategy.php
const AGING_RATE = 0.5;
const AGING_INTERVAL = 300; // 5 menit

$score = $priorityWeight + floor($waitSeconds / self::AGING_INTERVAL) * self::AGING_RATE;
```

**Simulasi:**
| Priority | Weight | Tunggu | Score |
|----------|--------|--------|-------|
| Urgent | 20 | 0 mnt | 20.0 |
| High | 10 | 0 mnt | 10.0 |
| Normal | 5 | 30 mnt | 5+3=8.0 |
| Normal | 5 | 75 mnt | 5+7.5=12.5 |
| High | 10 | 30 mnt | 10+3=13.0 |

→ Antrian Normal yang sudah 75 menit akan mengalahkan High baru (score 12.5 > 10) — anti-starvation bekerja.

**Temuan:**
- ✅ Anti-starvation terbukti via simulasi
- ⚠️ `AGING_RATE` dan `AGING_INTERVAL` hardcoded — tidak bisa dikonfigurasi per-cabang
- ⚠️ Tidak ada batas atas score — theoretically score bisa sangat tinggi pada antrian sangat lama

---

## 4. MANUAL ASSIGNMENT STRATEGY

### Kode: `ManualAssignmentStrategy::selectEmployee()`

Selalu mengembalikan `null` — tidak ada auto-distribute. Supervisor harus assign manual via `SupervisorController`.

**Temuan:**
- ✅ Mode manual berfungsi sebagai fallback/override
- ✅ Supervisor tetap bisa assign ke petugas manapun yang eligible
- ⚠️ Jika semua cabang mode MANUAL dan supervisor tidak aktif, antrian tidak akan pernah di-assign

---

## 5. DISTRIBUSI `assignPending()`

```php
public function assignPending(string $branchId): void
{
    // Ambil semua antrian WAITING di cabang
    // Untuk setiap antrian, coba assignNext()
    // Jika tidak ada petugas tersedia, hentikan
}
```

**Temuan:**
- ✅ `assignPending()` dipanggil setelah setiap: cancel, skip, complete, transfer — memastikan slot yang freed segera diisi
- ⚠️ Jika 50 antrian menunggu dan 1 petugas baru AVAILABLE, `assignPending()` akan loop 50x tapi hanya berhasil 1x — N-1 transaksi sia-sia

---

## 6. SKENARIO EDGE CASE

### Semua Petugas BUSY
- `selectEmployee()` return `null`
- Queue tetap `WAITING`
- Tidak ada error — antrian menunggu sampai ada yang available

### Semua Petugas OFFLINE  
- Sama dengan di atas — antrian menumpuk di WAITING
- Tidak ada notifikasi ke supervisor bahwa tidak ada petugas
- **Risiko:** Antrian terus masuk tapi tidak pernah di-assign — customer experience buruk

### Cursor Orphan
- Jika employee di cursor `last_employee_id` dihapus/nonaktif
- `eligibleEmployees()` tidak akan mengembalikan employee tersebut
- `findIndex()` return -1 → sistem pilih employee pertama dari list
- ✅ Handled gracefully — tidak crash

---

## 7. KESIMPULAN ROUND ROBIN

| Aspek | Nilai | Catatan |
|-------|-------|---------|
| Concurrency safety | 10/10 | lockForUpdate + partial unique index |
| Fairness RR | 7/10 | UUID sort tidak stabil |
| Priority scoring | 8/10 | Formula baik, hardcoded params |
| Anti-starvation | 9/10 | Aging mechanism terbukti |
| Edge case handling | 8/10 | Graceful fallback |
| Manual mode | 8/10 | Fungsional |
| **TOTAL** | **8.3/10** | |
