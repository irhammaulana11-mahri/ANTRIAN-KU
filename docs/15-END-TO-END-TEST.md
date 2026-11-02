# End-to-End Test Status

| Skenario | Status | Bukti |
|---|---|---|
| Walk-in registration | PASS (feature test) | Nomor queue dan token diuji. |
| Online registration/tracking | PASS (feature test) | Secure token tracking diuji. |
| Auto assignment | PASS (feature test) | Assignment engine dan eligibility diuji. |
| Service completion | PASS (feature test) | Status terminal, release, dan service session diuji. |
| Break management | PASS (feature test) | Employee BREAK tidak dipilih. |
| Multi-branch | PASS (feature test) | Scope dan cross-branch assignment diuji. |
| Priority/aging | PASS (unit test) | Weight dan aging score diuji. |
| Browser login/dashboard | NOT TESTED | Playwright/browser runner belum tersedia dalam project. |
| Browser WebSocket/display/voice | NOT TESTED | Reverb daemon dan browser handshake belum diuji dengan `.env` aktual. |
| PostgreSQL transaction/concurrency | NOT TESTED | Test aktual masih SQLite in-memory. |

Build frontend berhasil, tetapi build bukan pengganti browser E2E. Tidak ada klaim bahwa seluruh alur browser sudah lulus.
