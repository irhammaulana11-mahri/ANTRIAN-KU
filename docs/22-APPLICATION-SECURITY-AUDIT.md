# APPLICATION SECURITY AUDIT
**Tanggal:** 2026-11-03

---

## 1. AUTENTIKASI

### Laravel Sanctum Token-based Auth

**Konfigurasi:**
- Token expire: `SANCTUM_STAFF_TOKEN_MINUTES` (default 720 menit = 12 jam)
- Display device token: 7 hari, per-ability (`display` atau `kiosk`)
- Token disimpan sebagai SHA-256 hash di DB — plaintext tidak disimpan

**Temuan:**
- ✅ Password di-hash dengan `Hash::make()` (bcrypt/argon2)  
- ✅ Login audit log: setiap attempt (sukses/gagal) dicatat dengan IP + User-Agent
- ✅ Token refresh: token lama dihapus saat refresh — tidak ada token accumulation
- ✅ Logout mencabut token aktif
- ✅ Employee inactive check pada login — akun nonaktif tidak bisa login
- 🔴 **TIDAK ADA RATE LIMITING** pada `/api/v1/auth/login` — brute force tidak dibatasi
  - Mitigasi: Audit log ada tapi tidak ada lockout otomatis
  - Rekomendasi: `RateLimiter::for('login', fn() => Limit::perMinute(5)->by(request()->ip()))`

### Token Lifetime
- Default 12 jam — reasonable untuk aplikasi operasional cabang
- Tidak ada sliding expiry — token expired walau user aktif → user harus login ulang setiap 12 jam
- Frontend menangani 401 dengan redirect ke `/login?expired=1`

---

## 2. OTORISASI

### Permission System (Spatie)

```
Roles: Super Admin, Admin Cabang, Admin Unit, Customer Service, Teller, Customer, Display Device
Permissions: 27 permission granular
```

**Temuan:**
- ✅ Permission check di setiap controller endpoint (`abort_unless($user->can(...)`)
- ✅ `ResolveBranchContext` middleware memastikan tenant isolation
- ✅ Super Admin harus menyertakan `branch_id` eksplisit — tidak ada "god mode" tanpa parameter

### IDOR Analysis

**QueueController::cancel()**
```php
public function cancel(QueueCancellationRequest $request, Queue $queue): QueueResource
{
    abort_unless($request->user()->can('queues.cancel'), 403);
    // ⚠️ Tidak ada check: apakah $queue->branch_id === user's branch_id?
    $cancelled = $this->queues->cancel($queue, ...);
}
```

Route model binding `Queue $queue` menemukan queue berdasarkan ID tanpa filter branch. User Admin Cabang A secara teoritis bisa cancel antrian di Cabang B jika mengetahui UUID-nya.

**Mitigasi parsial:** UUID v4 tidak bisa ditebak — probabilitas brute force sangat rendah. Tapi secara prinsip IDOR ada.

**Rekomendasi:**
```php
abort_unless($queue->branch_id === $request->attributes->get('branch_id'), 403);
```

### WebSocket Authorization

```php
// channels.php
Broadcast::channel('employee.{employeeId}', function ($user, string $employeeId): bool {
    $employee = Employee::query()->find($employeeId);
    return $employee !== null 
        && ($user->branch_id === null || $user->branch_id === $employee->branch_id) 
        && ($employee->user_id === $user->id || $user->can('queues.view'));
});
```

**Temuan:**
- ⚠️ Super Admin (`branch_id === null`) dapat subscribe ke channel `employee.*` SIAPAPUN di sistem
- ⚠️ Siapapun dengan `queues.view` di cabang yang sama dapat mendengarkan notifikasi petugas lain
- Informasi yang di-broadcast: antrian baru assigned — tidak mengandung data PII sensitif, tapi tetap perlu dibatasi

---

## 3. HEADERS KEAMANAN

```php
// SecurityHeaders middleware
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(self), geolocation=()
CSP: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'
HSTS: max-age=31536000; includeSubDomains (hanya jika HTTPS)
```

**Temuan:**
- ✅ CSP per-response-type: JSON API mendapat `default-src 'none'` — sangat restrictif
- ✅ `X-Frame-Options: DENY` mencegah clickjacking
- ✅ HSTS hanya aktif di HTTPS — tidak akan merusak HTTP dev
- ✅ Swagger UI menggunakan nonce-based CSP
- ⚠️ Tidak ada `Content-Security-Policy-Report-Only` untuk monitoring violation di production

---

## 4. INPUT VALIDATION

**Temuan:**
- ✅ Semua input melalui Form Request dengan rules Laravel (`required`, `string`, `max`, `uuid`, dll.)
- ✅ Queue registration tidak menerima arbitrary data — hanya field yang didefinisikan di `$rules`
- ✅ `mb_substr()` digunakan untuk string truncation — tidak ada buffer overrun
- ⚠️ `customer_name` di queue registration hanya `max:200` tapi tidak ada sanitasi XSS khusus
  - Mitigasi: API JSON response, bukan HTML template — XSS bukan ancaman di konteks ini

---

## 5. INJECTION VULNERABILITIES

### SQL Injection
- ✅ Seluruh codebase menggunakan Eloquent ORM dan Query Builder dengan parameter binding
- ✅ Tidak ada raw query dengan string interpolation user input
- ✅ `DB::table(...)` dengan `->where(...)` menggunakan prepared statements

### CORS
- ✅ CORS dikonfigurasi via Laravel middleware — tidak menggunakan `*` untuk `Access-Control-Allow-Origin`
- ⚠️ `SANCTUM_STATEFUL_DOMAINS` harus dikonfigurasi dengan benar di production

---

## 6. DISPLAY DEVICE SECURITY

**Device lifecycle:**
1. Admin create device → activation code 8-char generated
2. Device panggil `/display/activate` dengan activation code → dapat Bearer token (7 hari)
3. Token disimpan di device storage → digunakan untuk WebSocket + polling
4. Admin bisa "Kunci Baru" (rotate) atau "Nonaktifkan" (revoke)

**Temuan:**
- ✅ Credential disimpan sebagai SHA-256 hash — plaintext activation code tidak ada di DB
- ✅ Revoke langsung menolak token via `isRevoked()` check di middleware
- ✅ Device type check: DISPLAY tidak bisa pakai endpoint KIOSK
- ⚠️ Activation code 8 karakter (alphanumeric) = 36^8 = ~2.8 triliun kombinasi — cukup aman jika ada rate limit
- 🔴 Tidak ada rate limiting pada `/display/activate` — brute force activation code dimungkinkan

---

## 7. AUDIT LOGGING

**Temuan:**
- ✅ `AuditLogService::record()` dipanggil dari AuthController untuk login/logout/profile-update
- ✅ IP + User-Agent dicatat
- ⚠️ Tidak ada audit log pada: queue cancel, transfer, changeStatus — hanya `queue_transitions` table (tapi tanpa IP/UA)
- ⚠️ Audit log untuk aksi data master (buat service type, counter, employee) tidak konsisten

---

## 8. KESIMPULAN KEAMANAN

| Aspek | Nilai | Catatan |
|-------|-------|---------|
| Autentikasi | 8/10 | Baik tapi tidak ada rate limiting login |
| Otorisasi | 8/10 | IDOR potensial via route model binding |
| Headers | 9/10 | Sangat baik |
| Input validation | 9/10 | Solid |
| SQL injection | 10/10 | ORM + prepared statements konsisten |
| WebSocket auth | 7/10 | Super Admin bisa dengar semua channel |
| Audit trail | 7/10 | Tidak konsisten antar fitur |
| **TOTAL** | **8.3/10** | |

### Prioritas Perbaikan
1. Rate limiting login dan display activation
2. IDOR check pada queue cancel/status change
3. Pertimbangkan batasi WebSocket employee channel hanya untuk pemilik sendiri
