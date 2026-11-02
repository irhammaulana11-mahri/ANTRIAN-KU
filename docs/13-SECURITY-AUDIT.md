# Security Audit

## Status risiko

| Risiko | Status | Catatan |
|---|---|---|
| Authentication bypass | PASS (test) | Sanctum login/logout dan protected route diuji. |
| Branch isolation | PASS (test/code) | Branch context, policy, service scope, dan foreign key komposit digunakan. |
| IDOR queue | PASS (code/test) | Queue operation memeriksa branch actor; cross-branch assignment ditolak. |
| SQL injection | PASS (review) | Query memakai Eloquent/query builder; `selectRaw('1')` tidak menerima input. |
| XSS | PASS (review) | Tidak ditemukan `dangerouslySetInnerHTML`, `eval`, atau `innerHTML`. |
| Login brute force | IMPROVED | Login limiter 5/menit ditambahkan. |
| Token exposure | MEDIUM | Bearer token berada di localStorage dan tracking token berada pada URL; perlu threat-model lanjutan. |
| CORS | IMPROVED | Allowlist environment-based; production wajib mengganti default localhost. |
| WebSocket authorization | PASS (code) / NOT TESTED (runtime) | Private channels memeriksa branch dan employee; live handshake belum diuji dengan frontend. |
| Dependency vulnerabilities | PASS | Composer audit dan npm production audit tidak menemukan advisory. |

Tidak ditemukan temuan CRITICAL pada static review. Production security belum dapat dinyatakan selesai sebelum HTTPS, secret store, PostgreSQL credentials, Redis extension, dan WebSocket handshake diverifikasi.

## Rekomendasi

1. Pertimbangkan HttpOnly/Secure cookie atau short-lived token dengan rotasi untuk deployment production.
2. Set `CORS_ALLOWED_ORIGINS` dan `REVERB_ALLOWED_ORIGINS` hanya ke domain resmi.
3. Aktifkan HTTPS, HSTS, secret manager, log redaction, dan monitoring 401/403.
4. Daftarkan Display Device dengan credential terpisah sebelum membuka central display ke jaringan.
