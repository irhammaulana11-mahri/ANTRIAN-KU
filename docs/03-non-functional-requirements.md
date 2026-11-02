# Non-Functional Requirements

Status per 31 Oktober 2026.

| Aspek | Target | Status |
|---|---|---|
| **Security** | TLS, password hashing, Sanctum, permission server-side, policy/scope cabang, rate limit, audit, least privilege | ◐ Sanctum + **token staf 12 jam dengan perpanjangan otomatis**, hashing, permission, scope cabang, rate limit (login 5/mnt, pendaftaran 20/mnt, aktivasi perangkat 10/mnt, kiosk 30/mnt, umum 120/mnt), **audit login/logout**, **CSP** (SPA di Nginx; API/JSON deny-all; Swagger ber-nonce) ✅. **Belum:** MFA, pengunci audit di database. TLS dikonfigurasi di Nginx (`deploy/`). |
| **Integrity** | UUID, FK, unique/check/index, transaction, `SELECT FOR UPDATE`, idempotency key, event setelah commit | ◐ Semua ✅ **kecuali** idempotency key & outbox: tabel `idempotency_keys` / `outbox_messages` ada tetapi **tidak dipakai**. Event memakai `ShouldDispatchAfterCommit`. Duplikasi klik ditangani state machine (409) dan lock. |
| **Performance** | p95 API <500 ms; event display <2 dtk | ⬜ Belum diukur (belum ada load test). Query dashboard memakai indeks & pagination. |
| **Availability** | Worker/Reverb restart aman; retry idempotent; reconnect display = snapshot ulang | ✅ Snapshot saat reconnect + poll 30 dtk; heartbeat petugas; job retry. |
| **Scalability** | Web/worker/Reverb horizontal, Redis, PostgreSQL | ◐ Arsitektur mendukung; **belum diuji multi-instance**. Dev memakai driver `database`. |
| **Observability** | Log terstruktur, correlation ID, metrik, health endpoint, alert | ◐ `/up` health check dan `correlation_id` pada audit ✅; metrik/alert ⬜. |
| **Usability** | Responsif, empty/loading/error state, tombol besar, display jauh | ✅ Diverifikasi visual di desktop, mobile (390 px), tablet kiosk (1024×768), TV (1920×1080). |
| **Maintainability** | Modular, service layer, typed React, migration reversible | ✅ (struktur aktual: `docs/11`). |
| **Compliance/privacy** | Minimisasi PII, masking, retention, backup terenkripsi | ◐ Event display membawa nama customer (diperlukan untuk pengumuman) dan tidak membawa telepon; token disimpan hash; `deploy/scripts/backup-postgres.sh` ada, **uji restore & retention ⬜**. |
