# Development Roadmap

> Status per 31 Oktober 2026: ✅ selesai · ◐ sebagian · ⬜ belum.

| Fase | Status | Catatan |
|---|---|---|
| 0. Blueprint approval | ✅ | Blueprint disetujui; direvisi 31 Okt 2026 agar sesuai implementasi. |
| 1. Foundation | ✅ | Laravel/React, PostgreSQL, UUID, auth/permission/scope cabang. Zona waktu aplikasi diatur eksplisit. |
| 2. Master data | ✅ | CRUD + nonaktif + hapus + **pulihkan**, jam operasional, hari libur. ◐ CRUD priority type belum ada. |
| 3. Queue core | ✅ | Sequence, registrasi, state machine, audit, aturan jam/kuota. ⬜ idempotency key (diganti state machine + lock). |
| 4. Operations | ✅ | Status/heartbeat, service session, Round Robin, prioritas+aging, estimasi tunggu, assign manual/transfer (API + UI). ◐ kuota fairness normal belum dipakai. |
| 5. Real-time/display | ✅ | Reverb/Echo, snapshot/reconnect, auth perangkat, suara. ⬜ outbox (memakai after-commit). Suara belum diuji di perangkat nyata. |
| 6. Frontend | ✅ | Operator, **Kendali Antrian supervisor**, kiosk, display, registrasi/tracking, master, pengaturan, laporan. ⬜ CRUD prioritas, penampil audit, UI ubah status petugas. |
| 7. Hardening | ◐ | Tes PostgreSQL + concurrency, OpenAPI, rate limit, **CSP, expiry+refresh token staf, audit login**, deploy Nginx/Supervisor/systemd. ⬜ uji beban, ⬜ uji restore, ⬜ observabilitas, ⬜ MFA. |
| 8. Pilot & rollout | ⬜ | Belum dimulai. |

## Backlog prioritas (rekomendasi)
1. **Sebelum pilot (perlu perangkat/lingkungan nyata):** jalankan `docs/24-uji-perangkat.md` — suara `id-ID` di TV, cetak pada printer termal, video IPTV/YouTube; daftar `REVERB_ALLOWED_ORIGINS` untuk domain/IP perangkat; latihan restore backup.
2. CRUD kategori prioritas + pemakaian `normal_fairness_quota`.
3. Penampil audit log di UI; UI ubah status petugas oleh supervisor; "lupa password" mandiri dan MFA.
4. Uji beban, metrik/alert, tes e2e browser otomatis.

*Selesai sejak revisi sebelumnya:* expiry + perpanjangan token staf, audit login, CSP, UI supervisor (assign manual, transfer, verifikasi prioritas), **video TV IPTV/YouTube dengan failover, menu ponsel, CSS cetak tiket 80 mm, indikator status suara di display**.

Setiap fase tetap wajib menghasilkan migration/API/UI yang berfungsi, test yang benar-benar dijalankan, dokumentasi, dan instruksi menjalankan.
