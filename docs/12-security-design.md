# Security Design

> Diperbarui 31 Oktober 2026. ✅ terpasang · ◐ sebagian · ⬜ belum.

| Kontrol | Status | Keterangan |
|---|---|---|
| Autentikasi pengguna (Sanctum) | ◐ | ✅ login/logout, hash password, akun petugas nonaktif/dihapus diblokir. ✅ **token staf kedaluwarsa 12 jam** (`SANCTUM_STAFF_TOKEN_MINUTES`; per-token `expires_at`, bukan `sanctum.expiration` global agar token perangkat tidak ikut terpengaruh) dan diperpanjang otomatis oleh frontend lewat `/auth/refresh` (token lama dicabut). ✅ token lama tanpa expiry diberi batas oleh migrasi. ⬜ tanpa MFA. |
| Otorisasi | ✅ | Permission granular (Spatie) + policy + scope cabang; cabang dari klien divalidasi server (`ResolveBranchContext`/`BranchAuthorizationService`). |
| Isolasi cabang | ✅ | Middleware, policy, query scope, FK komposit; diuji IDOR (master, antrian, channel, perangkat). |
| Perangkat Display/Kiosk | ✅ | Kunci pairing `{id}.{secret}` sekali tampil, hanya hash disimpan; token 7 hari dengan ability sempit (`display`/`kiosk`); tipe dicek middleware; rotate/revoke mengakhiri token; token perangkat **ditolak** di endpoint staf; kiosk tidak dapat masuk channel real-time. |
| Walk-in | ✅ | Bukan endpoint publik; staf berizin + cabang miliknya, atau perangkat kiosk. Mencegah pelewatan jam/kuota online. |
| Prioritas | ✅ | Customer tidak dapat mengklaim; verifikator dicatat. |
| Channel real-time | ✅ | `/broadcasting/auth` memakai Sanctum; `private-branch.{id}` hanya untuk staf cabang tersebut atau DISPLAY cabang itu; `private-employee.{id}` hanya pemilik/penyelia. Server Reverb memeriksa `Origin`. |
| Token pelacakan antrian | ✅ | 96 karakter acak, hash SHA-256, dibatasi rate limit. |
| Rate limiting | ✅ | Lihat `docs/09`. |
| Validasi & SQL | ✅ | Form Request, ORM binding. Pencarian memakai `LIKE … ESCAPE '!'` (wildcard dinetralkan). |
| Mass assignment, soft-delete | ✅ | `$fillable`; query mentah wajib `whereNull('deleted_at')` (kebocoran sebelumnya telah ditutup & diuji). |
| Sesi saat akun dinonaktifkan/dihapus/password direset | ✅ | Semua token pengguna dihapus. |
| Security headers & CSP | ✅ | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (HTTPS). **CSP:** SPA (Nginx, `location /`): `default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self' ws(s)://$http_host; object-src 'none'; frame-ancestors 'none'…` — diverifikasi di browser (inline script diblokir; tidak ada pelanggaran pada seluruh halaman termasuk Recharts, QR, WebSocket). API/JSON dari Laravel: `default-src 'none'`. Swagger `/api/docs`: nonce per-permintaan + `https://unpkg.com`. CSP **tidak** dipasang Nginx pada `/api/` agar tidak bertumpuk dengan CSP Laravel. **Pengecualian terukur: rute `/display`** memakai CSP yang dilonggarkan (`media-src` dan `connect-src` mengizinkan `http:`/`https:` karena segmen HLS dimuat dari host stream yang dipilih admin; `frame-src https://www.youtube-nocookie.com`). Rute ini hanya-baca, memakai token perangkat terbatas, dan tidak memuat input pengguna; halaman lain tetap ketat. Diverifikasi di Nginx sungguhan: hanya `/display`, `/display/`, `/display?…` yang longgar. |
| PII | ◐ | Event/display memuat nama + nomor; **tidak** memuat telepon/catatan. Telepon/keperluan hanya bagi pengguna `queues.view`. Retention & masking log ⬜. |
| Audit | ◐ | Master data, pengaturan, perangkat, assignment, status, prioritas, **login berhasil/gagal/diblokir dan logout** (IP, user-agent, email yang dicoba; **password dan token tidak pernah disimpan**). ⬜ penguncian append-only di database. |
| Idempotency / replay protection | ⬜ | Tidak diimplementasikan; duplikasi klik ditolak state machine + lock. |
| Backup | ◐ | `deploy/scripts/backup-postgres.sh`; ⬜ uji restore & enkripsi belum diverifikasi. |
| Dokumentasi API publik | ◐ | `/api/docs` aktif kecuali produksi; paksa dengan `API_DOCS_ENABLED`. Swagger UI dimuat dari CDN pihak ketiga. |

## Rekomendasi sebelum produksi
1. Daftarkan alamat/domain TV Display ke `REVERB_ALLOWED_ORIGINS` (Reverb menolak `Origin` yang tidak terdaftar, kode 4009). Kiosk tidak memakai WebSocket.
2. Aktifkan HSTS di Nginx setelah TLS terpasang (baris sudah disiapkan, dikomentari).
3. Tinjau `SANCTUM_STAFF_TOKEN_MINUTES` (default 720) sesuai kebijakan shift; pertimbangkan MFA untuk Super Admin/Admin Cabang.
4. Latih restore backup; tetapkan retention PII dan masking log.
5. Bila `/api/docs` dipertahankan di produksi, ingat Swagger UI dimuat dari CDN pihak ketiga (set `API_DOCS_ENABLED=false` bila tidak diperlukan).
