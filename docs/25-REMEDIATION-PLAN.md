# REMEDIATION PLAN
**Tanggal:** 2026-11-03  
**Berdasarkan:** docs/18-APPLICATION-AUDIT-REPORT.md + docs/19 sampai docs/24

---

## STATUS P1 (diperbarui 2026-11-03)

| ID | Status | Catatan |
|---|---|---|
| P1-01 | ✅ FIXED | Test suite: 128 passed, 0 failed. Role `Supervisor` → `Admin Unit`, referensi `service_code` dihapus, test kunci pairing disesuaikan ke format 8 karakter, 10 operasi (profil & unit) ditambahkan ke `resources/openapi.yaml`. |
| P1-02 | ✅ NO CHANGE NEEDED | Temuan keliru: `throttle:auth.login` (5/menit per email+IP) dan `throttle:display.activate` (10/menit per IP) sudah terpasang di `routes/api.php`. |
| P1-03 | ✅ FIXED | Isolasi antar-cabang sudah ada di `QueueManagementService`. Celah nyata adalah isolasi **unit**: Admin Unit dapat mengubah antrian unit lain di cabang yang sama. Diperbaiki lewat `authorizeScope()` (cabang + unit) pada cancel/verifyPriority/transfer/changeStatus, dengan test baru. |
| Tambahan | ✅ FIXED | Kunci aktivasi perangkat sebelumnya tersimpan sebagai teks asli (`activation_code`). Kini hanya hash yang disimpan; migration `2026_11_03_000014` menghapus kolom tersebut. |

## PRIORITAS PERBAIKAN

### 🔴 P1 — Harus Diselesaikan Sebelum Produksi Penuh

---

#### P1-01: Fix 24 Test Gagal (Backend)
**Severity:** HIGH  
**Estimasi:** 2–3 jam  
**File:** `tests/Feature/AuthSecurityTest.php`, `DisplayDeviceTest.php`, `SupervisorBoardTest.php`, `RegistrationRulesTest.php`, `MasterDataTest.php`, `KioskDeviceTest.php`, `OpenApiSpecTest.php`

**Langkah:**
1. Cari semua `assignRole('Supervisor')` di folder tests — ganti dengan `assignRole('Admin Unit')`
2. Pastikan factory `UserFactory` memiliki `unit_id` yang valid saat role `Admin Unit`
3. Investigasi `KioskDeviceTest` QueryException — kemungkinan kolom `service_code` yang sudah drop
4. Update `OpenApiSpecTest` — tambahkan route baru ke spec YAML

```bash
grep -r "Supervisor" tests/ --include="*.php" -l
```

---

#### P1-02: Rate Limiting Login & Display Activation
**Severity:** HIGH  
**Estimasi:** 1 jam  
**File:** `routes/api.php` atau `AppServiceProvider.php`

**Langkah:**
```php
// Di AppServiceProvider::boot() atau RouteServiceProvider
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip())->response(function() {
        return response()->json(['message' => 'Terlalu banyak percobaan. Coba lagi dalam 1 menit.'], 429);
    });
});

RateLimiter::for('display-activate', function (Request $request) {
    return Limit::perMinute(10)->by($request->ip());
});
```

```php
// Di routes/api.php
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/display/activate', [DisplayController::class, 'activate'])->middleware('throttle:display-activate');
```

---

#### P1-03: IDOR Check pada Queue Endpoints
**Severity:** HIGH (teoritis, UUID mitigates praktis)  
**Estimasi:** 2 jam  
**File:** `app/Http/Controllers/Api/V1/QueueController.php`

**Langkah:**
```php
// Tambahkan scope pada route model binding di RouteServiceProvider atau langsung di controller
public function cancel(QueueCancellationRequest $request, Queue $queue): QueueResource
{
    abort_unless($request->user()->can('queues.cancel'), 403);
    
    // Tambahkan ini:
    $branchId = $request->attributes->get('branch_id');
    abort_unless($queue->branch_id === $branchId, 403, 'Antrian tidak ditemukan di cabang ini.');
    
    $cancelled = $this->queues->cancel($queue, ...);
}
```

Lakukan hal sama untuk `changeStatus()`, `verifyPriority()`, `transfer()`.

---

### 🟡 P2 — Selesaikan Minggu Pertama Produksi

---

#### P2-01: Bersihkan Tabel Orphan
**Severity:** MEDIUM  
**Estimasi:** 30 menit  
**File:** `routes/console.php` (Laravel scheduler)

**Opsi A: Tambah cleanup scheduler (jika outbox/idempotency dibutuhkan nanti)**
```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
```

Tambahkan `MassPrunable` trait ke model `IdempotencyKey`:
```php
public function prunable(): Builder
{
    return static::where('expires_at', '<', now());
}
```

**Opsi B: Drop tabel jika tidak direncanakan digunakan**
```php
// Buat migration baru
Schema::dropIfExists('outbox_messages');
Schema::dropIfExists('idempotency_keys');
```

---

#### P2-02: Fix Round Robin Sort
**Severity:** LOW  
**Estimasi:** 30 menit  
**File:** `app/Services/RoundRobinStrategy.php`

```php
// Sebelum:
->orderBy('id') // UUID - tidak stabil

// Sesudah:
->orderBy('created_at') // Urutan masuk sistem = urutan RR yang stabil
->orderBy('id')         // Tiebreaker
```

---

#### P2-03: Tambah Index Database
**Severity:** LOW (preventif untuk skala besar)  
**Estimasi:** 1 jam  
**File:** Buat migration baru `2026_11_03_000011_add_performance_indexes.php`

```php
// queues: filter supervisor board
$table->index(['branch_id', 'queue_date', 'status', 'sequence_no'], 'queues_supervisor_idx');

// queues: history filter dengan service type
$table->index(['branch_id', 'service_type_id', 'created_at'], 'queues_history_idx');

// employees: filter per unit
$table->index(['branch_id', 'unit_id', 'is_active'], 'employees_unit_idx');
```

---

#### P2-04: Batasi WebSocket Employee Channel
**Severity:** MEDIUM  
**Estimasi:** 1 jam  
**File:** `routes/channels.php`

```php
// Sebelum: siapapun dengan queues.view bisa subscribe channel petugas lain
Broadcast::channel('employee.{employeeId}', function ($user, string $employeeId): bool {
    $employee = Employee::query()->find($employeeId);
    return $employee !== null 
        && ($user->branch_id === null || $user->branch_id === $employee->branch_id)
        && ($employee->user_id === $user->id || $user->can('queues.view'));
});

// Sesudah: hanya petugas sendiri atau yang punya permission queues.assign (supervisor)
Broadcast::channel('employee.{employeeId}', function ($user, string $employeeId): bool {
    if (!$user instanceof User) return false;
    $employee = Employee::query()->find($employeeId);
    if (!$employee) return false;
    
    $inSameBranch = $user->branch_id === null || $user->branch_id === $employee->branch_id;
    $isOwner = $employee->user_id === $user->id;
    $isSupervisor = $user->can('queues.assign'); // hanya supervisor/admin
    
    return $inSameBranch && ($isOwner || $isSupervisor);
});
```

---

### 🟢 P3 — Backlog (Jangka Menengah)

---

#### P3-01: Toast Notification Global (Frontend)
**File:** `frontend/src/context/` atau `frontend/src/components/`  
Tambahkan komponen `<Toaster>` global (misalnya Sonner atau Radix Toast) dan panggil dari mutation `onSuccess`/`onError`.

#### P3-02: Auto-refresh Dashboard
**File:** `frontend/src/pages/DashboardPage.tsx`  
Gunakan `refetchInterval: 30000` pada TanStack Query untuk polling setiap 30 detik.

#### P3-03: Konfigurasi Aging Rate per Cabang
**File:** `backend/app/Services/PriorityQueueStrategy.php`  
Baca `aging_rate` dan `aging_interval` dari `queue_settings` alih-alih hardcoded const.

#### P3-04: Frontend Testing
Tambahkan Vitest + React Testing Library:
- Test `AuthContext` (login/logout)
- Test `OperatorPage` state transitions
- Test `QueuePages` filter + pagination

#### P3-05: Supervisor Mobile Responsiveness
**File:** `frontend/src/pages/SupervisorPage.tsx`  
Tambahkan mode kartu untuk mobile — hide beberapa kolom atau gunakan `overflow-x-auto` dengan sticky kolom pertama.

#### P3-06: Export CSV di QueuePages
**File:** `frontend/src/pages/QueuePages.tsx`, `backend/app/Http/Controllers/Api/V1/QueueController.php`  
Tambahkan endpoint `GET /api/v1/queues/export?format=csv` dan tombol export di UI.

---

## TIMELINE REKOMENDASI

| Fase | Waktu | Item |
|------|-------|------|
| **Pre-launch** | Sebelum go-live | P1-01, P1-02, P1-03 |
| **Week 1** | 7 hari pertama | P2-01, P2-02, P2-04 |
| **Month 1** | 30 hari pertama | P2-03, P3-01, P3-02 |
| **Backlog** | Sesuai kapasitas | P3-03, P3-04, P3-05, P3-06 |

---

## CHECKLIST DEPLOYMENT

- [ ] `php artisan migrate --force` — jalankan semua migration pending
- [ ] `php artisan db:seed --force` — jalankan `RolePermissionSeeder` (seeder lain opsional)
- [ ] `npm run build` — build frontend di server atau lokal lalu copy `dist/`
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false` di `.env`
- [ ] Set `SANCTUM_STATEFUL_DOMAINS` sesuai domain frontend
- [ ] Set `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` untuk WebSocket
- [ ] Set `BROADCAST_CONNECTION=reverb`
- [ ] Jalankan `php artisan reverb:start --daemon` atau via Supervisor
- [ ] Jalankan `php artisan schedule:run` via cronjob (setiap menit)
- [ ] Test login → ambil antrian → operator call → complete via browser
- [ ] Verifikasi WebSocket realtime di display device
